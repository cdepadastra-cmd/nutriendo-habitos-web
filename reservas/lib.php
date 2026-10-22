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
const DIR_DATOS   = __DIR__ . '/datos';
const CABECERA_PROTEGIDA = "<?php http_response_code(404); exit; ?>\n";

/* ---------- almacenamiento ---------- */

function asegurar_datos(): void {
    if (!is_dir(DIR_DATOS)) {
        mkdir(DIR_DATOS, 0750, true);
    }
    $ht = DIR_DATOS . '/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file(DIR_DATOS . '/index.html')) {
        file_put_contents(DIR_DATOS . '/index.html', '');
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
    file_put_contents($tmp, CABECERA_PROTEGIDA . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $f);
}

/** Ejecuta $fn con un cerrojo exclusivo, para que dos reservas simultáneas no cojan el mismo hueco. */
function con_cerrojo(callable $fn) {
    asegurar_datos();
    $fh = fopen(DIR_DATOS . '/.cerrojo', 'c');
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
        'antelacion_horas' => 24,   // no se puede reservar con menos antelación
        'dias_vista'       => 60,   // cuántos días hacia delante se puede reservar
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
    $limite = date('Y-m-d', strtotime('+' . (int)$cfg['dias_vista'] . ' days'));
    if ($fecha < $hoy || $fecha > $limite) {
        return [];
    }
    $diaSemana = date('N', strtotime($fecha . ' 12:00'));
    $tramos = $cfg['horario'][$modalidad][$diaSemana] ?? [];
    $minimo = time() + (int)$cfg['antelacion_horas'] * 3600;

    $ocupado = [];
    foreach ($reservas as $r) {
        if ($r['fecha'] === $fecha && ($r['estado'] ?? '') === 'confirmada') {
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

/** Comprueba si un hueco choca con otra cita confirmada (para citas añadidas a mano). */
function choca_con_cita(array $reservas, string $fecha, string $hora, string $ignorarId = ''): bool {
    $t = minutos($hora);
    foreach ($reservas as $r) {
        if ($r['id'] === $ignorarId || $r['fecha'] !== $fecha || ($r['estado'] ?? '') !== 'confirmada') {
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
         . "Consulta: " . MODALIDADES[$r['modalidad']] . "\n";
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
