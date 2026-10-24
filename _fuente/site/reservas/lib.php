<?php
/**
 * Reservas de Nutriendo Hábitos — funciones comunes.
 * Guarda los datos en ficheros JSON dentro de reservas/datos/ (protegidos contra lectura web).
 * Sin base de datos, sin servicios externos, sin claves en el código.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

const DESTINO     = 'nutricion.arguello@gmail.com';
const REMITENTE   = 'web@nutriendohabitos.es';
const NOMBRE_WEB  = 'Nutriendo Hábitos';
const DURACION    = 45;                     // minutos por cita
const TELEFONO    = '669 87 70 52';
const DIRECCION   = 'Calle Gregorio Fernández, nº4, 47006 Valladolid';
const MODALIDADES = ['presencial' => 'Presencial en Valladolid', 'online' => 'Online'];
const MOTIVOS     = [
    'Nutrición deportiva',
    'Nutrición oncológica',
    'Pérdida de peso / adelgazamiento',
    'Ganancia de peso',
    'Patología digestiva (SIBO, intolerancias, celiaquía…)',
    'Diabetes y otras patologías',
    'Trastornos de la conducta alimentaria (TCA)',
    'Alimentación vegetariana o vegana',
    'Alimentación infantil',
    'Educación nutricional / hábitos saludables',
    'Otros',
];
const DIR_DATOS   = __DIR__ . '/datos';
const CABECERA_PROTEGIDA = "<?php http_response_code(404); exit; ?>\n";

/* ---------- almacenamiento ---------- */

/** Error al guardar en reservas/datos (normalmente, permisos de la carpeta en el hosting). */
class ErrorEscritura extends RuntimeException {}

/** Comprueba de verdad si PHP puede crear archivos en reservas/datos. */
function datos_escribibles(): bool {
    if (!is_dir(DIR_DATOS) && !@mkdir(DIR_DATOS, 0755, true)) {
        return false;
    }
    $f = DIR_DATOS . '/.prueba_' . bin2hex(random_bytes(3));
    $ok = @file_put_contents($f, 'ok') === 2;
    @unlink($f);
    return $ok;
}

// Cualquier fallo inesperado responde con un mensaje claro en vez de una página en blanco
set_exception_handler(function (Throwable $e) {
    $esc = $e instanceof ErrorEscritura;
    error_log('Reservas Nutriendo Hábitos: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $esc ? 'escritura' : 'servidor'], JSON_UNESCAPED_UNICODE);
});

function asegurar_datos(): void {
    if (!is_dir(DIR_DATOS) && !@mkdir(DIR_DATOS, 0755, true)) {
        throw new ErrorEscritura('No se puede crear la carpeta datos');
    }
    $ht = DIR_DATOS . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file(DIR_DATOS . '/index.html')) {
        @file_put_contents(DIR_DATOS . '/index.html', '');
    }
}

function ruta_datos(string $nombre): string {
    return DIR_DATOS . '/' . $nombre . '.json.php';
}

function leer_datos(string $nombre, array $porDefecto): array {
    $f = ruta_datos($nombre);
    if (!is_file($f)) {
        return $porDefecto;
    }
    $txt = (string)file_get_contents($f);
    $json = json_decode(substr($txt, strlen(CABECERA_PROTEGIDA)), true);
    return is_array($json) ? $json : $porDefecto;
}

function guardar_datos(string $nombre, array $datos): void {
    asegurar_datos();
    $f = ruta_datos($nombre);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $txt = CABECERA_PROTEGIDA . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $txt, LOCK_EX) !== strlen($txt) || !@rename($tmp, $f)) {
        @unlink($tmp);
        throw new ErrorEscritura('No se puede guardar ' . $nombre);
    }
}

/** Ejecuta $fn con un cerrojo exclusivo, para que dos reservas simultáneas no cojan el mismo hueco. */
function con_cerrojo(callable $fn) {
    asegurar_datos();
    $fh = @fopen(DIR_DATOS . '/.cerrojo', 'c');
    if (!$fh) {
        throw new ErrorEscritura('No se puede abrir el cerrojo');
    }
    flock($fh, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function config_por_defecto(): array {
    $semana = ['1' => [], '2' => [], '3' => [], '4' => [], '5' => [], '6' => [], '7' => []];
    return [
        'password'         => '',
        'horario'          => ['presencial' => $semana, 'online' => $semana],
        'bloqueos'         => [],   // [{id, desde, hasta, modalidad: todas|presencial|online, nota}]
        'especiales'       => [],   // días con horario distinto al semanal: [{id, fecha, modalidad, tramos: [[ini, fin], ...]}]
        'abierto_hasta'    => '',   // última fecha que se puede reservar (vacío = usar dias_vista)
        'antelacion_horas' => 24,   // no se puede reservar con menos antelación
        'dias_vista'       => 60,   // cuántos días hacia delante se puede reservar
        'recordatorios'    => true, // email automático el día antes de la cita
        'clave_cron'       => '',   // clave aleatoria para la tarea programada (se crea sola)
        'precio'           => '',   // importe de la consulta en euros, p. ej. "45.00"
        'pago_online'      => false, // pago con tarjeta al reservar (Redsys); apagado hasta tener los datos del banco
        'redsys'           => ['comercio' => '', 'terminal' => '1', 'clave' => '', 'entorno' => 'pruebas'],
        'sheets'           => ['url' => '', 'token' => ''],   // copia en Google Sheets
    ];
}

function cargar_config(): array {
    $c = leer_datos('config', config_por_defecto());
    return array_replace_recursive(config_por_defecto(), $c);
}

function cargar_reservas(): array {
    return leer_datos('reservas', []);
}

/* ---------- utilidades ---------- */

function minutos(string $hhmm): int {
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function hhmm(int $min): string {
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function es_fecha(string $f): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
}

function es_hora(string $h): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
}

function limpiar(string $v, int $max = 120): string {
    $v = trim(str_replace(["\r", "\n", "\0"], ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

function fecha_larga(string $fecha): string {
    $dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($fecha . ' 12:00');
    return $dias[(int)date('N', $t)] . ' ' . (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t)] . ' de ' . date('Y', $t);
}

function json_salida(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- disponibilidad ---------- */

/**
 * Última fecha reservable. Si Andrea ha puesto «Reservas abiertas hasta», manda esa fecha
 * (como mucho un año); si no, se usan los «días por delante» de Ajustes.
 */
function fecha_limite(array $cfg): string {
    $hasta = (string)($cfg['abierto_hasta'] ?? '');
    if (es_fecha($hasta)) {
        return min($hasta, date('Y-m-d', strtotime('+365 days')));
    }
    return date('Y-m-d', strtotime('+' . (int)$cfg['dias_vista'] . ' days'));
}

/** Tramos de un día: los del horario especial de esa fecha si existe; si no, los del horario semanal. */
function tramos_del_dia(array $cfg, string $fecha, string $modalidad): array {
    foreach ($cfg['especiales'] ?? [] as $e) {
        if ($e['fecha'] === $fecha && $e['modalidad'] === $modalidad) {
            return $e['tramos'];
        }
    }
    return $cfg['horario'][$modalidad][date('N', strtotime($fecha . ' 12:00'))] ?? [];
}

function dia_bloqueado(array $cfg, string $fecha, string $modalidad): bool {
    foreach ($cfg['bloqueos'] as $b) {
        if ($fecha >= $b['desde'] && $fecha <= $b['hasta']
            && ($b['modalidad'] === 'todas' || $b['modalidad'] === $modalidad)) {
            return true;
        }
    }
    return false;
}

/**
 * Huecos libres de un día para una modalidad.
 * Una cita ocupa a Andrea sea presencial u online, así que se descuentan todas las citas del día.
 */
function huecos_del_dia(array $cfg, array $reservas, string $fecha, string $modalidad): array {
    if (!isset(MODALIDADES[$modalidad]) || dia_bloqueado($cfg, $fecha, $modalidad)) {
        return [];
    }
    $hoy = date('Y-m-d');
    $limite = fecha_limite($cfg);
    if ($fecha < $hoy || $fecha > $limite) {
        return [];
    }
    $tramos = tramos_del_dia($cfg, $fecha, $modalidad);
    $minimo = time() + (int)$cfg['antelacion_horas'] * 3600;

    $ocupado = [];
    foreach ($reservas as $r) {
        if ($r['fecha'] === $fecha && ocupa_hueco($r)) {
            $ini = minutos($r['hora']);
            $ocupado[] = [$ini, $ini + DURACION];
        }
    }

    $libres = [];
    foreach ($tramos as $tramo) {
        $desde = minutos($tramo[0]);
        $hasta = minutos($tramo[1]);
        for ($t = $desde; $t + DURACION <= $hasta; $t += DURACION) {
            if (strtotime($fecha . ' ' . hhmm($t)) < $minimo) {
                continue;
            }
            foreach ($ocupado as [$a, $b]) {
                if ($t < $b && $t + DURACION > $a) {
                    continue 2;
                }
            }
            $libres[] = hhmm($t);
        }
    }
    sort($libres);
    return array_values(array_unique($libres));
}

/** Una cita pendiente de confirmar también ocupa el hueco, para que nadie más lo coja. */
function ocupa_hueco(array $r): bool {
    return in_array($r['estado'] ?? '', ['pendiente', 'confirmada'], true);
}

/** Comprueba si un hueco choca con otra cita pendiente o confirmada (para citas añadidas a mano). */
function choca_con_cita(array $reservas, string $fecha, string $hora, string $ignorarId = ''): bool {
    $t = minutos($hora);
    foreach ($reservas as $r) {
        if ($r['id'] === $ignorarId || $r['fecha'] !== $fecha || !ocupa_hueco($r)) {
            continue;
        }
        $a = minutos($r['hora']);
        if ($t < $a + DURACION && $t + DURACION > $a) {
            return true;
        }
    }
    return false;
}

/* ---------- correo ---------- */

function enviar_correo(string $para, string $asunto, string $cuerpo, string $responderA = ''): bool {
    $cab = [
        'From: =?UTF-8?B?' . base64_encode('Web ' . NOMBRE_WEB) . '?= <' . REMITENTE . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($responderA !== '' && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
        $cab[] = 'Reply-To: ' . $responderA;
    }
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, implode("\r\n", $cab), '-f' . REMITENTE);
}

function texto_cita(array $r): string {
    return "Día: " . fecha_larga($r['fecha']) . "\n"
         . "Hora: " . $r['hora'] . " (" . DURACION . " minutos)\n"
         . "Consulta: " . MODALIDADES[$r['modalidad']] . "\n"
         . (!empty($r['motivo']) ? "Motivo: " . $r['motivo'] . "\n" : '');
}

/** Correo de confirmación definitiva para el paciente (lo envía Andrea desde el panel). */
function correo_confirmacion(array $cita): bool {
    if (empty($cita['email'])) {
        return false;
    }
    $lugar = $cita['modalidad'] === 'presencial'
        ? "Lugar: " . DIRECCION . "\n"
        : "Antes de la cita te enviaré el enlace de la videollamada.\n";
    return enviar_correo($cita['email'], 'Cita confirmada · ' . NOMBRE_WEB,
        "Hola {$cita['nombre']}:\n\nTu cita con Andrea Argüello ha quedado confirmada.\n\n"
        . texto_cita($cita) . $lugar . "\n"
        . "Si necesitas cambiarla o anularla, llámame o escríbeme por WhatsApp al " . TELEFONO . ".\n\n"
        . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n",
        DESTINO);
}

/** Recordatorio por email el día antes. */
function correo_recordatorio(array $cita): bool {
    if (empty($cita['email'])) {
        return false;
    }
    $lugar = $cita['modalidad'] === 'presencial'
        ? "Lugar: " . DIRECCION . "\n"
        : "Es una consulta online: te enviaré el enlace de la videollamada.\n";
    return enviar_correo($cita['email'], 'Recordatorio: tu cita es mañana · ' . NOMBRE_WEB,
        "Hola {$cita['nombre']}:\n\nTe recuerdo que mañana tienes cita conmigo.\n\n"
        . texto_cita($cita) . $lugar . "\n"
        . "Si no puedes venir, avísame cuanto antes por teléfono o WhatsApp al " . TELEFONO . " y buscamos otro día.\n\n"
        . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n",
        DESTINO);
}

/**
 * Envía el recordatorio a todas las citas confirmadas de mañana que tengan email y aún no lo hayan recibido.
 * Se puede ejecutar varias veces: nunca manda dos recordatorios a la misma cita.
 */
function enviar_recordatorios(): array {
    $manana = date('Y-m-d', strtotime('tomorrow'));
    $toca = array_values(array_filter(cargar_reservas(), fn($r) => $r['fecha'] === $manana
        && $r['estado'] === 'confirmada' && !empty($r['email']) && empty($r['recordado'])));
    $enviados = 0;
    foreach ($toca as $cita) {
        if (correo_recordatorio($cita)) {
            $enviados++;
            $id = $cita['id'];
            con_cerrojo(function () use ($id) {
                $rs = cargar_reservas();
                foreach ($rs as &$r) {
                    if ($r['id'] === $id) {
                        $r['recordado'] = date('c');
                    }
                }
                guardar_datos('reservas', $rs);
            });
        }
    }
    return ['fecha' => $manana, 'pendientes' => count($toca), 'enviados' => $enviados];
}

/** Clave de la tarea programada (se genera la primera vez). */
function clave_cron(): string {
    return con_cerrojo(function () {
        $c = cargar_config();
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$c['clave_cron'])) {
            $c['clave_cron'] = bin2hex(random_bytes(16));
            guardar_datos('config', $c);
        }
        return $c['clave_cron'];
    });
}

/** Valida y prepara una cita añadida desde el panel. Devuelve null si faltan datos. */
function cita_del_panel(array $in): ?array {
    $fecha = (string)($in['fecha'] ?? '');
    $hora = (string)($in['hora'] ?? '');
    $mod = (string)($in['modalidad'] ?? '');
    $nombre = limpiar((string)($in['nombre'] ?? ''));
    if (!es_fecha($fecha) || !es_hora($hora) || !isset(MODALIDADES[$mod]) || $nombre === '') {
        return null;
    }
    $email = limpiar((string)($in['email'] ?? ''));
    return [
        'id' => bin2hex(random_bytes(6)), 'fecha' => $fecha, 'hora' => $hora, 'modalidad' => $mod,
        'nombre' => $nombre, 'apellidos' => limpiar((string)($in['apellidos'] ?? '')),
        'telefono' => limpiar((string)($in['telefono'] ?? ''), 30),
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'nota' => limpiar((string)($in['nota'] ?? ''), 200),
        'motivo' => in_array((string)($in['motivo'] ?? ''), MOTIVOS, true) ? (string)$in['motivo'] : '',
        'origen' => 'panel', 'estado' => 'confirmada', 'creada' => date('c'),
    ];
}

/** Dominio de la web. Solo se acepta el propio (o el equipo local de pruebas) para que nadie pueda falsear los enlaces. */
function host_web(): string {
    $host = strtolower(preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return preg_match('/^((www\.)?nutriendohabitos\.es|127\.0\.0\.1(:\d+)?|localhost(:\d+)?)$/', $host) ? $host : 'www.nutriendohabitos.es';
}

/** Dirección de la web (carpeta donde está index.html), terminada en «/». */
function url_web(): string {
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/reservas/api.php'), 2)), '/');
    $esquema = preg_match('/^(127\.|localhost)/', host_web()) ? 'http://' : 'https://';
    return $esquema . host_web() . $dir . '/';
}

function url_panel(): string {
    $host = host_web();
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/reservas/api.php'))), '/');
    return 'https://' . $host . $dir . '/admin.php';
}

/* ---------- límite de peticiones por IP ---------- */

function limitar(string $clave, int $maximo, int $segundos): bool {
    asegurar_datos();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $f = DIR_DATOS . '/.limite_' . $clave . '_' . md5($ip);
    $ahora = time();
    $marcas = is_file($f) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), fn($t) => $t > $ahora - $segundos) : [];
    if (count($marcas) >= $maximo) {
        return false;
    }
    $marcas[] = $ahora;
    file_put_contents($f, implode(',', $marcas), LOCK_EX);
    return true;
}


/* ---------- precio y cobros ---------- */

const METODOS_PAGO = ['online' => 'Pagado online', 'efectivo' => 'Efectivo', 'datafono' => 'Datáfono', 'bizum' => 'Bizum / transferencia'];

/** Convierte "45", "45,5" o "45.50" en "45.50". Devuelve '' si no es un importe válido. */
function importe(string $v): string {
    $v = str_replace([' ', '€'], '', str_replace(',', '.', trim($v)));
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $v)) {
        return '';
    }
    return number_format((float)$v, 2, '.', '');
}

function texto_importe(string $v): string {
    return $v === '' ? '' : str_replace('.', ',', $v) . ' €';
}

/* ---------- pago online con Redsys ---------- */

function redsys_listo(array $cfg): bool {
    $r = $cfg['redsys'];
    return !empty($cfg['pago_online']) && importe((string)$cfg['precio']) !== '' && (float)$cfg['precio'] > 0
        && preg_match('/^\d{6,10}$/', (string)$r['comercio']) && preg_match('/^\d{1,3}$/', (string)$r['terminal'])
        && strlen(base64_decode((string)$r['clave'], true) ?: '') >= 16;
}

/** Firma HMAC-SHA256 de Redsys: clave del pedido con 3DES y HMAC de los parámetros. */
function redsys_firma(string $params64, string $pedido, string $clave64): string {
    $clave = base64_decode($clave64, true) ?: '';
    $largo = (int)ceil(strlen($pedido) / 8) * 8;
    $claveP = openssl_encrypt(str_pad($pedido, $largo, "\0"), 'des-ede3-cbc', $clave, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, str_repeat("\0", 8));
    return base64_encode(hash_hmac('sha256', $params64, (string)$claveP, true));
}

/** Datos del formulario que el navegador envía a Redsys para pagar la cita. */
function redsys_formulario(array $cita, array $cfg): array {
    $r = $cfg['redsys'];
    $params = [
        'DS_MERCHANT_AMOUNT'             => (string)(int)round((float)$cita['pago']['importe'] * 100),
        'DS_MERCHANT_ORDER'              => $cita['pago']['pedido'],
        'DS_MERCHANT_MERCHANTCODE'       => (string)$r['comercio'],
        'DS_MERCHANT_CURRENCY'           => '978',
        'DS_MERCHANT_TRANSACTIONTYPE'    => '0',
        'DS_MERCHANT_TERMINAL'           => (string)$r['terminal'],
        'DS_MERCHANT_MERCHANTURL'        => url_web() . 'reservas/redsys.php',
        'DS_MERCHANT_URLOK'              => url_web() . 'reservar.html?pago=ok',
        'DS_MERCHANT_URLKO'              => url_web() . 'reservar.html?pago=ko',
        'DS_MERCHANT_PRODUCTDESCRIPTION' => 'Consulta de nutricion ' . date('d/m/Y', strtotime($cita['fecha'])) . ' ' . $cita['hora'],
        'DS_MERCHANT_TITULAR'            => mb_substr($cita['nombre'] . ' ' . $cita['apellidos'], 0, 60, 'UTF-8'),
        'DS_MERCHANT_MERCHANTNAME'       => 'Nutriendo Habitos',
        'DS_MERCHANT_CONSUMERLANGUAGE'   => '001',
    ];
    $p64 = base64_encode(json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return [
        'url'                  => $r['entorno'] === 'real' ? 'https://sis.redsys.es/sis/realizarPago' : 'https://sis-t.redsys.es:25443/sis/realizarPago',
        'Ds_SignatureVersion'  => 'HMAC_SHA256_V1',
        'Ds_MerchantParameters'=> $p64,
        'Ds_Signature'         => redsys_firma($p64, $params['DS_MERCHANT_ORDER'], (string)$r['clave']),
    ];
}

/** Número de pedido único para Redsys: 12 caracteres, los 4 primeros numéricos. */
function redsys_pedido(): string {
    return substr((string)time(), -8) . bin2hex(random_bytes(2));
}

/* ---------- tienda: productos, stock y ventas ---------- */

function cargar_tienda(): array {
    $t = leer_datos('tienda', []);
    return ['productos' => $t['productos'] ?? [], 'ventas' => $t['ventas'] ?? []];
}

/* ---------- copia en Google Sheets ---------- */

const CABECERA_CITAS  = ['ID', 'Fecha', 'Hora', 'Consulta', 'Motivo', 'Nombre', 'Apellidos', 'Teléfono', 'Email', 'Estado', 'Origen', 'Cobro', 'Importe (€)', 'Cobrado el', 'Reservada el'];
const CABECERA_VENTAS = ['ID', 'Fecha', 'Cliente', 'Producto', 'Unidades', 'Precio unidad (€)', 'Total (€)', 'Forma de pago', 'Estado', 'Nota'];
const CABECERA_STOCK  = ['ID', 'Producto', 'Precio (€)', 'Stock', 'Actualizado'];

function fila_cita(array $c): array {
    $estados = ['pendiente' => 'Pendiente de confirmar', 'confirmada' => 'Confirmada', 'cancelada' => (($c['estado_previo'] ?? '') === 'pendiente' ? 'Rechazada' : 'Anulada')];
    $pago = $c['pago'] ?? [];
    $cobro = ($pago['estado'] ?? '') === 'pagado' ? (METODOS_PAGO[$pago['metodo']] ?? $pago['metodo']) : ((($pago['metodo'] ?? '') === 'online') ? 'Pago online pendiente' : 'Sin cobrar');
    return [
        $c['id'], date('d/m/Y', strtotime($c['fecha'])), $c['hora'], MODALIDADES[$c['modalidad']] ?? $c['modalidad'],
        $c['motivo'] ?? '', $c['nombre'] ?? '', $c['apellidos'] ?? '', ($c['telefono'] ?? '') === '' ? '' : "'" . $c['telefono'], $c['email'] ?? '',
        $estados[$c['estado']] ?? $c['estado'], ($c['origen'] ?? '') === 'panel' ? 'Panel' : 'Web',
        $cobro, ($pago['estado'] ?? '') === 'pagado' ? (float)$pago['importe'] : '',
        !empty($pago['fecha']) ? date('d/m/Y H:i', strtotime($pago['fecha'])) : '',
        !empty($c['creada']) ? date('d/m/Y H:i', strtotime($c['creada'])) : '',
    ];
}

function fila_venta(array $v): array {
    return [$v['id'], date('d/m/Y H:i', strtotime($v['fecha'])), $v['cliente'], $v['producto'], (int)$v['unidades'],
            (float)$v['precio_unidad'], (float)$v['total'], METODOS_PAGO[$v['metodo']] ?? $v['metodo'],
            !empty($v['anulada']) ? 'Anulada' : 'Vendida', $v['nota'] ?? ''];
}

function fila_producto(array $p): array {
    return [$p['id'], $p['nombre'], (float)$p['precio'], (int)$p['stock'], date('d/m/Y H:i')];
}

/**
 * Envía a la hoja de Google las filas que han cambiado (o todo si $todo).
 * Si no está configurado o Google no responde, no pasa nada: el panel sigue funcionando.
 */
function sheets_enviar(array $citas = [], array $ventas = [], bool $stock = false, bool $todo = false): bool {
    $cfg = cargar_config();
    $url = (string)$cfg['sheets']['url'];
    if (!preg_match('~^https://script\.google\.com/(a/macros/[a-z0-9.-]+|macros)/s/[A-Za-z0-9_-]+/exec$~', $url) || $cfg['sheets']['token'] === '') {
        return false;
    }
    $tienda = cargar_tienda();
    $datos = [
        'token' => $cfg['sheets']['token'], 'todo' => $todo,
        'cabeceras' => ['Citas' => CABECERA_CITAS, 'Ventas' => CABECERA_VENTAS, 'Stock' => CABECERA_STOCK],
        'Citas' => array_map('fila_cita', $citas),
        'Ventas' => array_map('fila_venta', $ventas),
    ];
    if ($stock || $todo) {
        $datos['Stock'] = array_map('fila_producto', array_values(array_filter($tienda['productos'], fn($p) => empty($p['borrado']))));
    }
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $todo ? 30 : 6, CURLOPT_CONNECTTIMEOUT => 4]);
        $res = curl_exec($ch);   // (curl_close ya no hace falta y en PHP 8.5 da aviso)
    } else {
        $res = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json, 'timeout' => $todo ? 30 : 6]]));
    }
    $ok = is_string($res) && strpos($res, '"ok":true') !== false;
    if (!$ok) {
        error_log('Reservas Nutriendo Hábitos: no se pudo copiar en Google Sheets');
    }
    return $ok;
}

/** Copia en la hoja una cita concreta (por id), tras cualquier cambio. */
function sheets_cita(string $id): void {
    foreach (cargar_reservas() as $c) {
        if ($c['id'] === $id) {
            sheets_enviar([$c]);
            return;
        }
    }
}
