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
        'hasta'    => date('Y-m-d', strtotime('+' . (int)$cfg['dias_vista'] . ' days')),
        'duracion' => DURACION,
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

    if (!isset(MODALIDADES[$modalidad]) || !es_fecha($fecha) || !es_hora($hora)
        || $nombre === '' || $apellidos === ''
        || !preg_match('/^[0-9+()\s.-]{6,30}$/', $telefono)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || empty($_POST['privacidad'])) {
        json_salida(['ok' => false, 'error' => 'datos'], 422);
    }
    if (!limitar('reserva', 4, 3600)) {
        json_salida(['ok' => false, 'error' => 'limite'], 429);
    }

    $resultado = con_cerrojo(function () use ($modalidad, $fecha, $hora, $nombre, $apellidos, $telefono, $email) {
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
            'origen'    => 'web',
            'estado'    => 'confirmada',
            'creada'    => date('c'),
        ];
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
        "Nueva cita reservada desde la web\n\n" . texto_cita($cita) . "\n"
        . "Nombre: {$cita['nombre']}\nApellidos: {$cita['apellidos']}\n"
        . "Teléfono: {$cita['telefono']}\nEmail: {$cita['email']}\n\n"
        . "Puedes ver y gestionar las citas en el panel de reservas.\n",
        $cita['email']);

    // Confirmación para el paciente
    $lugar = $cita['modalidad'] === 'presencial'
        ? "Lugar: " . DIRECCION . "\n"
        : "Antes de la cita te enviaré el enlace de la videollamada.\n";
    enviar_correo($cita['email'], 'Cita confirmada · ' . NOMBRE_WEB,
        "Hola {$cita['nombre']}:\n\nTu cita con Andrea Argüello ha quedado confirmada.\n\n"
        . texto_cita($cita) . $lugar . "\n"
        . "Si necesitas cambiarla o anularla, llámame o escríbeme por WhatsApp al " . TELEFONO . ".\n\n"
        . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n",
        DESTINO);

    json_salida(['ok' => true, 'cita' => [
        'fecha' => $cita['fecha'], 'fecha_texto' => fecha_larga($cita['fecha']),
        'hora' => $cita['hora'], 'modalidad' => MODALIDADES[$cita['modalidad']],
    ]]);
}

json_salida(['ok' => false, 'error' => 'accion'], 400);
