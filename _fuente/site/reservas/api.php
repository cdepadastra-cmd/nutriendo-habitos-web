<?php
/**
 * API pública del calendario de reservas.
 *   GET  api.php?accion=mes&modalidad=presencial&mes=2026-11   -> días y horas libres
 *   POST api.php  (accion=reservar, modalidad, fecha, hora, nombre, apellidos, telefono, email, privacidad)
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'mes') {
    $modalidad = (string)($_GET['modalidad'] ?? '');
    $mes = (string)($_GET['mes'] ?? '');
    if (!isset(MODALIDADES[$modalidad]) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
        json_salida(['ok' => false, 'error' => 'parametros'], 400);
    }
    $cfg = cargar_config();
    $reservas = cargar_reservas();
    $dias = [];
    $total = (int)date('t', strtotime($mes . '-01'));
    for ($d = 1; $d <= $total; $d++) {
        $fecha = sprintf('%s-%02d', $mes, $d);
        $libres = huecos_del_dia($cfg, $reservas, $fecha, $modalidad);
        if ($libres) {
            $dias[$fecha] = $libres;
        }
    }
    json_salida([
        'ok'       => true,
        'dias'     => (object)$dias,
        'hoy'      => date('Y-m-d'),
        'hasta'    => fecha_limite($cfg),
        'duracion' => DURACION,
        'motivos'  => MOTIVOS,
        'pago'     => ['online' => redsys_listo($cfg), 'precio' => importe((string)$cfg['precio'])],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'reservar') {
    // Trampa para robots
    if (limpiar((string)($_POST['web'] ?? '')) !== '') {
        json_salida(['ok' => true, 'robot' => true]);
    }
    $modalidad = (string)($_POST['modalidad'] ?? '');
    $fecha     = (string)($_POST['fecha'] ?? '');
    $hora      = (string)($_POST['hora'] ?? '');
    $nombre    = limpiar((string)($_POST['nombre'] ?? ''));
    $apellidos = limpiar((string)($_POST['apellidos'] ?? ''));
    $telefono  = limpiar((string)($_POST['telefono'] ?? ''), 30);
    $email     = limpiar((string)($_POST['email'] ?? ''));
    $motivo    = limpiar((string)($_POST['motivo'] ?? ''), 80);
    $pagarYa   = ($_POST['pago'] ?? '') === 'online';

    if (!isset(MODALIDADES[$modalidad]) || !es_fecha($fecha) || !es_hora($hora)
        || $nombre === '' || $apellidos === ''
        || !preg_match('/^[0-9+()\s.-]{6,30}$/', $telefono)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($motivo, MOTIVOS, true)
        || empty($_POST['privacidad'])) {
        json_salida(['ok' => false, 'error' => 'datos'], 422);
    }
    if (!limitar('reserva', 4, 3600)) {
        json_salida(['ok' => false, 'error' => 'limite'], 429);
    }

    $resultado = con_cerrojo(function () use ($modalidad, $fecha, $hora, $nombre, $apellidos, $telefono, $email, $motivo, $pagarYa) {
        $cfg = cargar_config();
        $reservas = cargar_reservas();
        if (!in_array($hora, huecos_del_dia($cfg, $reservas, $fecha, $modalidad), true)) {
            return null; // el hueco ya no está libre
        }
        $cita = [
            'id'        => bin2hex(random_bytes(6)),
            'fecha'     => $fecha,
            'hora'      => $hora,
            'modalidad' => $modalidad,
            'nombre'    => $nombre,
            'apellidos' => $apellidos,
            'telefono'  => $telefono,
            'email'     => $email,
            'motivo'    => $motivo,
            'origen'    => 'web',
            'estado'    => 'pendiente',   // el hueco queda bloqueado hasta que Andrea confirme o rechace
            'creada'    => date('c'),
        ];
        if ($pagarYa && redsys_listo($cfg)) {
            $cita['pago'] = ['estado' => 'pendiente', 'metodo' => 'online', 'importe' => importe((string)$cfg['precio']), 'pedido' => redsys_pedido()];
        }
        $reservas[] = $cita;
        guardar_datos('reservas', $reservas);
        return $cita;
    });

    if ($resultado === null) {
        json_salida(['ok' => false, 'error' => 'ocupado'], 409);
    }

    $cita = $resultado;
    // Aviso para Andrea
    enviar_correo(DESTINO, 'RESERVA WEB',
        "Nueva solicitud de cita desde la web (PENDIENTE DE CONFIRMAR)\n\n" . texto_cita($cita)
        . (isset($cita['pago']) ? "Pago: online con tarjeta (" . texto_importe($cita['pago']['importe']) . "). Te llegará otro aviso cuando se complete.\n" : "Pago: en la consulta.\n") . "\n"
        . "Nombre: {$cita['nombre']}\nApellidos: {$cita['apellidos']}\n"
        . "Teléfono: {$cita['telefono']}\nEmail: {$cita['email']}\n\n"
        . "El hueco ya está bloqueado en el calendario. Para confirmarla o rechazarla, entra en el panel:\n"
        . url_panel() . "\n",
        $cita['email']);

    // Acuse de recibo para el paciente (la confirmación definitiva llega cuando Andrea la confirma)
    enviar_correo($cita['email'], 'Solicitud de cita recibida · ' . NOMBRE_WEB,
        "Hola {$cita['nombre']}:\n\nHe recibido tu solicitud de cita. Ese hueco queda reservado para ti mientras la reviso.\n\n"
        . texto_cita($cita) . "\n"
        . "Te enviaré un email con la confirmación definitiva lo antes posible.\n"
        . "Si tienes cualquier duda, llámame o escríbeme por WhatsApp al " . TELEFONO . ".\n\n"
        . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n",
        DESTINO);

    sheets_enviar([$cita]);
    $cfg = cargar_config();
    json_salida(['ok' => true, 'redsys' => isset($cita['pago']) ? redsys_formulario($cita, $cfg) : null, 'cita' => [
        'fecha' => $cita['fecha'], 'fecha_texto' => fecha_larga($cita['fecha']),
        'hora' => $cita['hora'], 'modalidad' => MODALIDADES[$cita['modalidad']], 'estado' => 'pendiente',
    ]]);
}

json_salida(['ok' => false, 'error' => 'accion'], 400);
