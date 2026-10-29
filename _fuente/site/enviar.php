<?php
/**
 * Formulario de reserva de Nutriendo Hábitos.
 * Envía cada solicitud a nutricion.arguello@gmail.com con el asunto "RESERVA WEB"
 * usando el correo del propio hosting (sin servicios externos, sin claves).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/reservas/lib.php';         // DESTINO, envío de correo (Gmail o correo del hosting)
const ASUNTO    = 'RESERVA WEB';
const MAX_POR_HORA = 5;                        // envíos por IP y hora (anti-spam)

function responder(bool $ok, int $codigo = 200, string $error = ''): void {
    http_response_code($codigo);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

function campo(string $clave, int $max = 120): string {
    $v = trim((string)($_POST[$clave] ?? ''));
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);          // evita inyección de cabeceras
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(false, 405, 'metodo');
}

// Trampa para robots: un campo oculto que las personas nunca rellenan.
if (campo('web') !== '') {
    responder(true);
}

$nombre    = campo('nombre');
$apellidos = campo('apellidos');
$telefono  = campo('telefono', 30);
$email     = campo('email');
$modalidad = campo('modalidad', 60);
$tema      = campo('tema', 80);

$opcionesModalidad = ['Presencial en Valladolid', 'Online', 'Aún no lo sé'];
$opcionesTema = ['Tratamiento de patologías', 'Sobrepeso, obesidad y ganancia de peso',
                 'Nutrición deportiva', 'Vegetariana y vegana', 'Otros'];

if ($nombre === '' || $apellidos === '' || $telefono === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[0-9+()\s.-]{6,30}$/', $telefono)
    || !in_array($modalidad, $opcionesModalidad, true)
    || !in_array($tema, $opcionesTema, true)
    || empty($_POST['privacidad'])) {
    responder(false, 422, 'datos');
}

// Límite sencillo de envíos por IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$registro = rtrim(sys_get_temp_dir(), '/') . '/nh_form_' . md5($ip);
$ahora = time();
$marcas = [];
if (is_readable($registro)) {
    $marcas = array_filter(array_map('intval', explode(',', (string)file_get_contents($registro))),
        fn($t) => $t > $ahora - 3600);
}
if (count($marcas) >= MAX_POR_HORA) {
    responder(false, 429, 'limite');
}
$marcas[] = $ahora;
@file_put_contents($registro, implode(',', $marcas), LOCK_EX);

date_default_timezone_set('Europe/Madrid');
$cuerpo = "Nueva solicitud de cita desde la web\n\n"
        . "Nombre: $nombre\n"
        . "Apellidos: $apellidos\n"
        . "Teléfono: $telefono\n"
        . "Email: $email\n"
        . "Consulta: $modalidad\n"
        . "Quiero trabajar: $tema\n\n"
        . "Ha aceptado la información sobre protección de datos.\n"
        . "Enviado el " . date('d/m/Y \a \l\a\s H:i') . "\n";

$enviado = enviar_correo(DESTINO, ASUNTO, $cuerpo, $email);

responder($enviado, $enviado ? 200 : 500, $enviado ? '' : 'envio');
