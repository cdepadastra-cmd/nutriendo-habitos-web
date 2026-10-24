<?php
/**
 * Panel de reservas de Nutriendo Hábitos (solo para Andrea).
 * La primera vez que se abre pide crear una contraseña; se guarda cifrada en reservas/datos/.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Acceso: al entrar se crea una clave aleatoria de sesión que el navegador guarda solo en esa pestaña
 * y envía en cada petición. En el servidor se guarda solo su huella (hash) en datos/, con caducidad.
 * No usa cookies ni las sesiones de PHP (en algunos hostings no se conservan entre peticiones).
 */
const SESION_HORAS = 12;

function sesiones_vigentes(array $ss): array {
    return array_values(array_filter($ss, fn($x) => is_array($x) && ($x['exp'] ?? 0) > time()));
}
function sesion_valida(string $t): bool {
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) {
        return false;
    }
    $h = hash('sha256', $t);
    foreach (sesiones_vigentes(leer_datos('sesiones', [])) as $x) {
        if (hash_equals($x['h'], $h)) {
            return true;
        }
    }
    return false;
}
function crear_sesion(): string {
    $t = bin2hex(random_bytes(32));
    con_cerrojo(function () use ($t) {
        $ss = sesiones_vigentes(leer_datos('sesiones', []));
        $ss[] = ['h' => hash('sha256', $t), 'exp' => time() + SESION_HORAS * 3600];
        guardar_datos('sesiones', array_slice($ss, -20));
    });
    return $t;
}
/** Clave compartida con la hoja de Google (se crea sola la primera vez). */
function token_sheets(): string {
    return con_cerrojo(function () {
        $c = cargar_config();
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$c['sheets']['token'])) {
            $c['sheets']['token'] = bin2hex(random_bytes(16));
            guardar_datos('config', $c);
        }
        return $c['sheets']['token'];
    });
}

/** Pacientes y clientes conocidos, para elegirlos al vender. */
function lista_clientes(): array {
    $out = [];
    foreach (cargar_reservas() as $r) {
        $n = trim(($r['nombre'] ?? '') . ' ' . ($r['apellidos'] ?? ''));
        if ($n !== '') {
            $out[mb_strtolower($n, 'UTF-8')] = $n;
        }
    }
    foreach (cargar_tienda()['ventas'] as $v) {
        $out[mb_strtolower($v['cliente'], 'UTF-8')] = $v['cliente'];
    }
    ksort($out);
    return array_values($out);
}

function borrar_sesiones(string $t = ''): void {
    con_cerrojo(function () use ($t) {
        $h = hash('sha256', $t);
        $ss = $t === '' ? [] : array_values(array_filter(sesiones_vigentes(leer_datos('sesiones', [])), fn($x) => !hash_equals($x['h'], $h)));
        guardar_datos('sesiones', $ss);
    });
}

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

/* ======================= API del panel ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        json_salida(['ok' => false, 'error' => 'peticion'], 400);
    }
    $accion = (string)($in['accion'] ?? '');
    $cfg = cargar_config();
    $configurado = $cfg['password'] !== '';
    $token = (string)($in['_t'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ''));
    $dentro = sesion_valida($token);

    if ($accion === 'estado') {
        json_salida(['ok' => true, 'configurado' => $configurado, 'dentro' => $dentro, 'escribible' => datos_escribibles(), 'php' => PHP_VERSION]);
    }

    if ($accion === 'crear_password') {
        if ($configurado) {
            json_salida(['ok' => false, 'error' => 'ya_configurado'], 409);
        }
        $p = (string)($in['password'] ?? '');
        if (strlen($p) < 8) {
            json_salida(['ok' => false, 'error' => 'corta'], 422);
        }
        con_cerrojo(function () use ($p) {
            $c = cargar_config();
            if ($c['password'] === '') {
                $c['password'] = password_hash($p, PASSWORD_DEFAULT);
                guardar_datos('config', $c);
            }
        });
        json_salida(['ok' => true, 'token' => crear_sesion()]);
    }

    if ($accion === 'entrar') {
        if (!limitar('login', 6, 900)) {
            json_salida(['ok' => false, 'error' => 'limite'], 429);
        }
        if (!$configurado || !password_verify((string)($in['password'] ?? ''), $cfg['password'])) {
            json_salida(['ok' => false, 'error' => 'password'], 401);
        }
        json_salida(['ok' => true, 'token' => crear_sesion()]);
    }

    if (!$dentro) {
        json_salida(['ok' => false, 'error' => 'no_autorizado'], 401);
    }

    switch ($accion) {
        case 'salir':
            borrar_sesiones($token);
            json_salida(['ok' => true]);

        case 'exportar':
            exportar_csv();

        case 'historial':
            $todas = cargar_reservas();
            usort($todas, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));
            json_salida(['ok' => true, 'citas' => $todas, 'hoy' => date('Y-m-d')]);

        case 'datos':
            $desde = date('Y-m-d', strtotime('-30 days'));
            $citas = array_values(array_filter(cargar_reservas(), fn($r) => $r['fecha'] >= $desde));
            usort($citas, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
            json_salida(['ok' => true,
                'horario' => $cfg['horario'], 'bloqueos' => $cfg['bloqueos'],
                'antelacion_horas' => $cfg['antelacion_horas'], 'dias_vista' => $cfg['dias_vista'],
                'abierto_hasta' => $cfg['abierto_hasta'], 'limite' => fecha_limite($cfg), 'especiales' => $cfg['especiales'],
                'citas' => $citas, 'hoy' => date('Y-m-d'), 'manana' => date('Y-m-d', strtotime('tomorrow')),
                'duracion' => DURACION, 'motivos' => MOTIVOS,
                'recordatorios' => !empty($cfg['recordatorios']),
                'url_cron' => preg_replace('~/admin\.php$~', '/recordatorios.php', url_panel()) . '?clave=' . clave_cron(),
                'direccion' => DIRECCION, 'telefono' => TELEFONO,
                'precio' => importe((string)$cfg['precio']), 'pago_online' => !empty($cfg['pago_online']), 'redsys_listo' => redsys_listo($cfg),
                'redsys' => ['comercio' => $cfg['redsys']['comercio'], 'terminal' => $cfg['redsys']['terminal'], 'entorno' => $cfg['redsys']['entorno'], 'clave' => $cfg['redsys']['clave'] !== ''],
                'sheets' => ['url' => $cfg['sheets']['url'], 'token' => token_sheets()],
                'metodos' => METODOS_PAGO, 'tienda' => cargar_tienda(), 'clientes' => lista_clientes()]);

        case 'guardar_horario':
            $horario = $in['horario'] ?? null;
            $nuevo = [];
            foreach (array_keys(MODALIDADES) as $m) {
                for ($d = 1; $d <= 7; $d++) {
                    $tramos = $horario[$m][(string)$d] ?? [];
                    $limpios = [];
                    foreach ((array)$tramos as $t) {
                        $a = (string)($t[0] ?? '');
                        $b = (string)($t[1] ?? '');
                        if (!es_hora($a) || !es_hora($b) || minutos($a) >= minutos($b)) {
                            json_salida(['ok' => false, 'error' => 'tramo', 'detalle' => ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][$d]], 422);
                        }
                        $limpios[] = [$a, $b];
                    }
                    usort($limpios, fn($x, $y) => strcmp($x[0], $y[0]));
                    $nuevo[$m][(string)$d] = $limpios;
                }
            }
            $ant = max(0, min(168, (int)($in['antelacion_horas'] ?? 24)));
            $vista = max(7, min(365, (int)($in['dias_vista'] ?? 60)));
            $abierto = (string)($in['abierto_hasta'] ?? '');
            if ($abierto !== '' && !es_fecha($abierto)) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            con_cerrojo(function () use ($nuevo, $ant, $vista, $abierto) {
                $c = cargar_config();
                $c['horario'] = $nuevo;
                $c['antelacion_horas'] = $ant;
                $c['dias_vista'] = $vista;
                $c['abierto_hasta'] = $abierto;
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'anadir_especial':
            $fecha = (string)($in['fecha'] ?? '');
            $mod = (string)($in['modalidad'] ?? '');
            $tramos = [];
            foreach ((array)($in['tramos'] ?? []) as $t) {
                $a = (string)($t[0] ?? '');
                $b = (string)($t[1] ?? '');
                if ($a === '' && $b === '') {
                    continue;
                }
                if (!es_hora($a) || !es_hora($b) || minutos($a) >= minutos($b)) {
                    json_salida(['ok' => false, 'error' => 'tramo'], 422);
                }
                $tramos[] = [$a, $b];
            }
            if (!es_fecha($fecha) || !isset(MODALIDADES[$mod]) || !$tramos) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            usort($tramos, fn($x, $y) => strcmp($x[0], $y[0]));
            con_cerrojo(function () use ($fecha, $mod, $tramos) {
                $c = cargar_config();
                // Un solo horario especial por día y modalidad: el nuevo sustituye al anterior
                $c['especiales'] = array_values(array_filter($c['especiales'], fn($e) => !($e['fecha'] === $fecha && $e['modalidad'] === $mod)));
                $c['especiales'][] = ['id' => bin2hex(random_bytes(5)), 'fecha' => $fecha, 'modalidad' => $mod, 'tramos' => $tramos];
                usort($c['especiales'], fn($a, $b) => strcmp($a['fecha'] . $a['modalidad'], $b['fecha'] . $b['modalidad']));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'quitar_especial':
            $id = (string)($in['id'] ?? '');
            con_cerrojo(function () use ($id) {
                $c = cargar_config();
                $c['especiales'] = array_values(array_filter($c['especiales'], fn($e) => $e['id'] !== $id));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'guardar_dias':
            // Horario de días concretos (igual para presencial y online). null = volver al horario habitual.
            $dias = (array)($in['dias'] ?? []);
            if (!$dias || count($dias) > 400) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            $limpio = [];
            foreach ($dias as $f => $tramos) {
                $f = (string)$f;
                if (!es_fecha($f)) {
                    json_salida(['ok' => false, 'error' => 'datos'], 422);
                }
                if ($tramos === null) {
                    $limpio[$f] = null;
                    continue;
                }
                $ts = [];
                foreach ((array)$tramos as $t) {
                    $a = (string)($t[0] ?? '');
                    $b = (string)($t[1] ?? '');
                    if (!es_hora($a) || !es_hora($b) || minutos($a) >= minutos($b)) {
                        json_salida(['ok' => false, 'error' => 'tramo', 'detalle' => fecha_larga($f)], 422);
                    }
                    $ts[] = [$a, $b];
                }
                usort($ts, fn($x, $y) => strcmp($x[0], $y[0]));
                $limpio[$f] = $ts;
            }
            con_cerrojo(function () use ($limpio) {
                $c = cargar_config();
                $c['especiales'] = array_values(array_filter($c['especiales'], fn($e) => !array_key_exists($e['fecha'], $limpio)));
                foreach ($limpio as $f => $ts) {
                    if ($ts === null) {
                        continue;
                    }
                    foreach (array_keys(MODALIDADES) as $m) {
                        $c['especiales'][] = ['id' => bin2hex(random_bytes(5)), 'fecha' => $f, 'modalidad' => $m, 'tramos' => $ts];
                    }
                }
                usort($c['especiales'], fn($a, $b) => strcmp($a['fecha'] . $a['modalidad'], $b['fecha'] . $b['modalidad']));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'aplicar_dias':
        case 'restablecer_dias':
            // Cambia el horario de varios días concretos a la vez (o los devuelve al horario habitual)
            $fechas = array_values(array_unique(array_filter(array_map('strval', (array)($in['fechas'] ?? [])), 'es_fecha')));
            $mod = (string)($in['modalidad'] ?? '');
            $mods = $mod === 'ambas' ? array_keys(MODALIDADES) : (isset(MODALIDADES[$mod]) ? [$mod] : []);
            if (!$fechas || count($fechas) > 400 || !$mods) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            $tramos = [];
            if ($accion === 'aplicar_dias') {
                foreach ((array)($in['tramos'] ?? []) as $t) {
                    $a = (string)($t[0] ?? '');
                    $b = (string)($t[1] ?? '');
                    if ($a === '' && $b === '') {
                        continue;
                    }
                    if (!es_hora($a) || !es_hora($b) || minutos($a) >= minutos($b)) {
                        json_salida(['ok' => false, 'error' => 'tramo'], 422);
                    }
                    $tramos[] = [$a, $b];
                }
                usort($tramos, fn($x, $y) => strcmp($x[0], $y[0]));
            }
            con_cerrojo(function () use ($fechas, $mods, $tramos, $accion) {
                $c = cargar_config();
                $quitar = [];
                foreach ($fechas as $f) {
                    foreach ($mods as $m) {
                        $quitar[$f . '|' . $m] = true;
                    }
                }
                $c['especiales'] = array_values(array_filter($c['especiales'], fn($e) => !isset($quitar[$e['fecha'] . '|' . $e['modalidad']])));
                if ($accion === 'aplicar_dias') {
                    foreach ($fechas as $f) {
                        foreach ($mods as $m) {
                            // tramos vacíos = ese día está cerrado para esa modalidad
                            $c['especiales'][] = ['id' => bin2hex(random_bytes(5)), 'fecha' => $f, 'modalidad' => $m, 'tramos' => $tramos];
                        }
                    }
                }
                usort($c['especiales'], fn($a, $b) => strcmp($a['fecha'] . $a['modalidad'], $b['fecha'] . $b['modalidad']));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true, 'dias' => count($fechas)]);

        case 'anadir_bloqueo':
            $desde = (string)($in['desde'] ?? '');
            $hasta = (string)($in['hasta'] ?? '') ?: $desde;
            $mod = (string)($in['modalidad'] ?? 'todas');
            if (!es_fecha($desde) || !es_fecha($hasta) || $hasta < $desde || !in_array($mod, ['todas', 'presencial', 'online'], true)) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            $nota = limpiar((string)($in['nota'] ?? ''), 80);
            con_cerrojo(function () use ($desde, $hasta, $mod, $nota) {
                $c = cargar_config();
                $c['bloqueos'][] = ['id' => bin2hex(random_bytes(5)), 'desde' => $desde, 'hasta' => $hasta, 'modalidad' => $mod, 'nota' => $nota];
                usort($c['bloqueos'], fn($a, $b) => strcmp($a['desde'], $b['desde']));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'quitar_bloqueo':
            $id = (string)($in['id'] ?? '');
            con_cerrojo(function () use ($id) {
                $c = cargar_config();
                $c['bloqueos'] = array_values(array_filter($c['bloqueos'], fn($b) => $b['id'] !== $id));
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'anadir_cita':
            $cita = cita_del_panel($in);
            if (!$cita) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            $ok = con_cerrojo(function () use ($cita) {
                $rs = cargar_reservas();
                if (choca_con_cita($rs, $cita['fecha'], $cita['hora'])) {
                    return false;
                }
                $rs[] = $cita;
                guardar_datos('reservas', $rs);
                return true;
            });
            if (!$ok) {
                json_salida(['ok' => false, 'error' => 'ocupado'], 409);
            }
            sheets_enviar([$cita]);
            json_salida(['ok' => true]);

        case 'anadir_citas':
            // Varias citas a la vez (pasadas desde la agenda de papel)
            $lista = array_slice((array)($in['citas'] ?? []), 0, 200);
            $nuevas = [];
            $res = con_cerrojo(function () use ($lista, &$nuevas) {
                $rs = cargar_reservas();
                $out = [];
                foreach ($lista as $item) {
                    $cita = is_array($item) ? cita_del_panel($item) : null;
                    if (!$cita) {
                        $out[] = 'datos';
                    } elseif (choca_con_cita($rs, $cita['fecha'], $cita['hora'])) {
                        $out[] = 'ocupado';
                    } else {
                        $rs[] = $cita;
                        $nuevas[] = $cita;
                        $out[] = 'ok';
                    }
                }
                guardar_datos('reservas', $rs);
                return $out;
            });
            if ($nuevas) {
                sheets_enviar($nuevas);
            }
            json_salida(['ok' => true, 'resultados' => $res, 'anadidas' => count(array_keys($res, 'ok', true))]);

        case 'recordar_ahora':
            json_salida(['ok' => true] + enviar_recordatorios());

        case 'marcar_wa':
            $id = (string)($in['id'] ?? '');
            con_cerrojo(function () use ($id) {
                $rs = cargar_reservas();
                foreach ($rs as &$r) {
                    if ($r['id'] === $id) {
                        $r['recordado_wa'] = date('c');
                    }
                }
                guardar_datos('reservas', $rs);
            });
            json_salida(['ok' => true]);

        case 'ajuste_recordatorios':
            $on = !empty($in['activo']);
            con_cerrojo(function () use ($on) {
                $c = cargar_config();
                $c['recordatorios'] = $on;
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'confirmar_cita':
            $id = (string)($in['id'] ?? '');
            $cita = con_cerrojo(function () use ($id) {
                $rs = cargar_reservas();
                foreach ($rs as &$r) {
                    if ($r['id'] === $id && $r['estado'] === 'pendiente') {
                        $r['estado'] = 'confirmada';
                        $r['confirmada'] = date('c');
                        guardar_datos('reservas', $rs);
                        return $r;
                    }
                }
                return null;
            });
            if (!$cita) {
                json_salida(['ok' => false, 'error' => 'no_encontrada'], 404);
            }
            $avisado = correo_confirmacion($cita);
            sheets_enviar([$cita]);
            json_salida(['ok' => true, 'avisado' => $avisado]);

        case 'cancelar_cita':
            $id = (string)($in['id'] ?? '');
            $avisar = !empty($in['avisar']);
            $cita = con_cerrojo(function () use ($id) {
                $rs = cargar_reservas();
                foreach ($rs as &$r) {
                    if ($r['id'] === $id && ocupa_hueco($r)) {
                        $r['estado_previo'] = $r['estado'];
                        $r['estado'] = 'cancelada';
                        $r['cancelada'] = date('c');
                        guardar_datos('reservas', $rs);
                        return $r;
                    }
                }
                return null;
            });
            if (!$cita) {
                json_salida(['ok' => false, 'error' => 'no_encontrada'], 404);
            }
            $avisado = false;
            if ($avisar && !empty($cita['email']) && ($cita['estado_previo'] ?? '') === 'pendiente') {
                $avisado = enviar_correo($cita['email'], 'Solicitud de cita no confirmada · ' . NOMBRE_WEB,
                    "Hola {$cita['nombre']}:\n\nLo siento, no puedo confirmarte la cita que solicitaste.\n\n" . texto_cita($cita) . "\n"
                    . "Puedes elegir otro día y hora en el calendario de la web, o llamarme o escribirme por WhatsApp al " . TELEFONO . " y lo buscamos juntas.\n\n"
                    . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n", DESTINO);
            } elseif ($avisar && !empty($cita['email'])) {
                $avisado = enviar_correo($cita['email'], 'Cita anulada · ' . NOMBRE_WEB,
                    "Hola {$cita['nombre']}:\n\nTu cita ha quedado anulada.\n\n" . texto_cita($cita) . "\n"
                    . "Si quieres buscar otro día, puedes reservar de nuevo en la web o llamarme al " . TELEFONO . ".\n\n"
                    . "Un saludo,\nAndrea Argüello · " . NOMBRE_WEB . "\n", DESTINO);
            }
            sheets_enviar([$cita]);
            json_salida(['ok' => true, 'avisado' => $avisado]);

        case 'cobro':
            // Registrar (o quitar) el cobro de una cita: efectivo, datáfono o Bizum
            $id = (string)($in['id'] ?? '');
            $metodo = (string)($in['metodo'] ?? '');
            $imp = importe((string)($in['importe'] ?? ''));
            if ($metodo !== '' && (!isset(METODOS_PAGO[$metodo]) || $metodo === 'online' || $imp === '')) {
                json_salida(['ok' => false, 'error' => 'importe'], 422);
            }
            $cita = con_cerrojo(function () use ($id, $metodo, $imp) {
                $rs = cargar_reservas();
                foreach ($rs as &$r) {
                    if ($r['id'] !== $id) {
                        continue;
                    }
                    if (($r['pago']['metodo'] ?? '') === 'online' && ($r['pago']['estado'] ?? '') === 'pagado') {
                        return false;   // lo pagado online no se cambia a mano
                    }
                    if ($metodo === '') {
                        unset($r['pago']);
                    } else {
                        $r['pago'] = ['estado' => 'pagado', 'metodo' => $metodo, 'importe' => $imp, 'fecha' => date('c')];
                    }
                    guardar_datos('reservas', $rs);
                    return $r;
                }
                return null;
            });
            if ($cita === false) {
                json_salida(['ok' => false, 'error' => 'pagado_online'], 409);
            }
            if (!$cita) {
                json_salida(['ok' => false, 'error' => 'no_encontrada'], 404);
            }
            sheets_enviar([$cita]);
            json_salida(['ok' => true]);

        case 'guardar_pago':
            // Precio de la consulta y pago online (Redsys). La clave secreta solo se guarda, nunca se devuelve.
            $precio = trim((string)($in['precio'] ?? '')) === '' ? '' : importe((string)$in['precio']);
            if (trim((string)($in['precio'] ?? '')) !== '' && $precio === '') {
                json_salida(['ok' => false, 'error' => 'importe'], 422);
            }
            $com = preg_replace('/\D/', '', (string)($in['comercio'] ?? ''));
            $ter = preg_replace('/\D/', '', (string)($in['terminal'] ?? '1')) ?: '1';
            $clave = trim((string)($in['clave'] ?? ''));
            if ($clave !== '' && strlen(base64_decode($clave, true) ?: '') < 16) {
                json_salida(['ok' => false, 'error' => 'clave_redsys'], 422);
            }
            $ent = ($in['entorno'] ?? '') === 'real' ? 'real' : 'pruebas';
            $activar = !empty($in['activo']);
            $r = con_cerrojo(function () use ($precio, $com, $ter, $clave, $ent, $activar) {
                $c = cargar_config();
                $c['precio'] = $precio;
                $c['redsys']['comercio'] = $com;
                $c['redsys']['terminal'] = $ter;
                $c['redsys']['entorno'] = $ent;
                if ($clave !== '') {
                    $c['redsys']['clave'] = $clave;
                }
                $c['pago_online'] = $activar;
                if ($activar && !redsys_listo($c)) {
                    return 'incompleto';
                }
                guardar_datos('config', $c);
                return 'ok';
            });
            if ($r !== 'ok') {
                json_salida(['ok' => false, 'error' => 'redsys_incompleto'], 422);
            }
            json_salida(['ok' => true]);

        case 'sheets_guardar':
            $url = trim((string)($in['url'] ?? ''));
            if ($url !== '' && !preg_match('~^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$~', $url)) {
                json_salida(['ok' => false, 'error' => 'url_sheets'], 422);
            }
            con_cerrojo(function () use ($url) {
                $c = cargar_config();
                $c['sheets']['url'] = $url;
                guardar_datos('config', $c);
            });
            json_salida(['ok' => true]);

        case 'sheets_todo':
            // Envía todas las citas, ventas y el stock (también sirve para probar la conexión)
            $t = cargar_tienda();
            $ok = sheets_enviar(cargar_reservas(), $t['ventas'], true, true);
            json_salida(['ok' => $ok, 'error' => $ok ? null : 'sheets']);

        /* ---------- tienda ---------- */
        case 'producto_guardar':
            $nombre = limpiar((string)($in['nombre'] ?? ''), 80);
            $precio = importe((string)($in['precio'] ?? ''));
            $stock = (int)($in['stock'] ?? 0);
            $pid = (string)($in['id'] ?? '');
            if ($nombre === '' || $precio === '' || $stock < 0 || $stock > 100000) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            con_cerrojo(function () use ($nombre, $precio, $stock, $pid) {
                $t = cargar_tienda();
                $hecho = false;
                foreach ($t['productos'] as &$p) {
                    if ($p['id'] === $pid) {
                        $p['nombre'] = $nombre; $p['precio'] = $precio; $p['stock'] = $stock; $hecho = true;
                    }
                }
                unset($p);
                if (!$hecho) {
                    $t['productos'][] = ['id' => bin2hex(random_bytes(5)), 'nombre' => $nombre, 'precio' => $precio, 'stock' => $stock, 'creado' => date('c')];
                }
                guardar_datos('tienda', $t);
            });
            sheets_enviar([], [], true);
            json_salida(['ok' => true]);

        case 'producto_entrada':
            $pid = (string)($in['id'] ?? '');
            $n = (int)($in['unidades'] ?? 0);
            if ($n === 0 || abs($n) > 100000) {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            con_cerrojo(function () use ($pid, $n) {
                $t = cargar_tienda();
                foreach ($t['productos'] as &$p) {
                    if ($p['id'] === $pid) {
                        $p['stock'] = max(0, (int)$p['stock'] + $n);
                    }
                }
                unset($p);
                guardar_datos('tienda', $t);
            });
            sheets_enviar([], [], true);
            json_salida(['ok' => true]);

        case 'producto_borrar':
            $pid = (string)($in['id'] ?? '');
            con_cerrojo(function () use ($pid) {
                $t = cargar_tienda();
                foreach ($t['productos'] as &$p) {
                    if ($p['id'] === $pid) {
                        $p['borrado'] = date('c');   // se oculta, pero las ventas antiguas lo siguen mostrando
                    }
                }
                unset($p);
                guardar_datos('tienda', $t);
            });
            sheets_enviar([], [], true);
            json_salida(['ok' => true]);

        case 'venta_registrar':
            $pid = (string)($in['producto'] ?? '');
            $cliente = limpiar((string)($in['cliente'] ?? ''), 100);
            $uds = (int)($in['unidades'] ?? 0);
            $total = importe((string)($in['total'] ?? ''));
            $metodo = (string)($in['metodo'] ?? '');
            $nota = limpiar((string)($in['nota'] ?? ''), 200);
            if ($cliente === '' || $uds < 1 || $uds > 1000 || $total === '' || !isset(METODOS_PAGO[$metodo]) || $metodo === 'online') {
                json_salida(['ok' => false, 'error' => 'datos'], 422);
            }
            $venta = con_cerrojo(function () use ($pid, $cliente, $uds, $total, $metodo, $nota) {
                $t = cargar_tienda();
                foreach ($t['productos'] as &$p) {
                    if ($p['id'] !== $pid || !empty($p['borrado'])) {
                        continue;
                    }
                    if ((int)$p['stock'] < $uds) {
                        return 'stock';
                    }
                    $p['stock'] = (int)$p['stock'] - $uds;
                    $v = ['id' => bin2hex(random_bytes(5)), 'fecha' => date('c'), 'cliente' => $cliente, 'producto_id' => $p['id'], 'producto' => $p['nombre'],
                          'unidades' => $uds, 'precio_unidad' => number_format((float)$total / $uds, 2, '.', ''), 'total' => $total, 'metodo' => $metodo, 'nota' => $nota];
                    $t['ventas'][] = $v;
                    guardar_datos('tienda', $t);
                    return $v;
                }
                return null;
            });
            if ($venta === 'stock') {
                json_salida(['ok' => false, 'error' => 'stock'], 409);
            }
            if (!$venta) {
                json_salida(['ok' => false, 'error' => 'no_encontrada'], 404);
            }
            sheets_enviar([], [$venta], true);
            json_salida(['ok' => true]);

        case 'venta_anular':
            $vid = (string)($in['id'] ?? '');
            $venta = con_cerrojo(function () use ($vid) {
                $t = cargar_tienda();
                foreach ($t['ventas'] as &$v) {
                    if ($v['id'] !== $vid || !empty($v['anulada'])) {
                        continue;
                    }
                    $v['anulada'] = date('c');
                    foreach ($t['productos'] as &$p) {
                        if ($p['id'] === $v['producto_id']) {
                            $p['stock'] = (int)$p['stock'] + (int)$v['unidades'];   // vuelve al stock
                        }
                    }
                    unset($p);
                    guardar_datos('tienda', $t);
                    return $v;
                }
                return null;
            });
            if (!$venta) {
                json_salida(['ok' => false, 'error' => 'no_encontrada'], 404);
            }
            sheets_enviar([], [$venta], true);
            json_salida(['ok' => true]);

        case 'cambiar_password':
            if (!password_verify((string)($in['actual'] ?? ''), $cfg['password'])) {
                json_salida(['ok' => false, 'error' => 'password'], 401);
            }
            $n = (string)($in['nueva'] ?? '');
            if (strlen($n) < 8) {
                json_salida(['ok' => false, 'error' => 'corta'], 422);
            }
            con_cerrojo(function () use ($n) {
                $c = cargar_config();
                $c['password'] = password_hash($n, PASSWORD_DEFAULT);
                guardar_datos('config', $c);
            });
            borrar_sesiones();   // cierra cualquier otra sesión abierta
            json_salida(['ok' => true, 'token' => crear_sesion()]);
    }
    json_salida(['ok' => false, 'error' => 'accion'], 400);
}

/* ======================= Descargar citas (Excel / CSV) ======================= */
function exportar_csv(): void {
    $citas = cargar_reservas();
    usort($citas, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));
    // Evita que Excel interprete un campo como fórmula
    $celda = function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v;
        }
        return $v;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="citas-nutriendo-habitos-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // para que Excel lea bien las tildes
    fputcsv($out, ['Fecha', 'Día', 'Hora', 'Consulta', 'Motivo', 'Nombre', 'Apellidos', 'Teléfono', 'Email', 'Estado', 'Origen', 'Cobro', 'Importe (€)', 'Cobrado el', 'Reservada el', 'Confirmada el', 'Anulada el'], ';');
    $estados = ['pendiente' => 'Pendiente de confirmar', 'confirmada' => 'Confirmada', 'cancelada' => 'Anulada / rechazada'];
    $dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    foreach ($citas as $c) {
        fputcsv($out, array_map($celda, [
            date('d/m/Y', strtotime($c['fecha'])),
            $dias[(int)date('N', strtotime($c['fecha'] . ' 12:00'))],
            $c['hora'],
            MODALIDADES[$c['modalidad']] ?? $c['modalidad'],
            $c['motivo'] ?? '',
            $c['nombre'] ?? '', $c['apellidos'] ?? '', $c['telefono'] ?? '', $c['email'] ?? '',
            $estados[$c['estado'] ?? ''] ?? '',
            ($c['origen'] ?? '') === 'panel' ? 'Añadida en el panel' : 'Web',
            ($c['pago']['estado'] ?? '') === 'pagado' ? (METODOS_PAGO[$c['pago']['metodo']] ?? '') : (($c['pago']['metodo'] ?? '') === 'online' ? 'Pago online pendiente' : 'Sin cobrar'),
            ($c['pago']['estado'] ?? '') === 'pagado' ? str_replace('.', ',', $c['pago']['importe']) : '',
            !empty($c['pago']['fecha']) ? date('d/m/Y H:i', strtotime($c['pago']['fecha'])) : '',
            !empty($c['creada']) ? date('d/m/Y H:i', strtotime($c['creada'])) : '',
            !empty($c['confirmada']) ? date('d/m/Y H:i', strtotime($c['confirmada'])) : '',
            !empty($c['cancelada']) ? date('d/m/Y H:i', strtotime($c['cancelada'])) : '',
        ]), ';');
    }
    fclose($out);
    exit;
}

/* ======================= Página del panel ======================= */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Panel de reservas · Nutriendo Hábitos</title>
<link rel="icon" href="../img/favicon.webp">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=Outfit:wght@300;400;500;600&display=swap">
<style>
:root{--bg:#FBFAF6;--fg:#2F302C;--card:#fff;--primary:#56704F;--primary-fg:#FBFAF6;--secondary:#E4F0D0;--secondary-fg:#3F523B;--muted:#F2EFEA;--muted-fg:#6B6660;--accent:#7D726A;--border:#E3E1D8;--leaf:#C2D8B9;--danger:#A6463B;--r:1rem}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font-family:"Outfit",system-ui,sans-serif;font-size:15px;line-height:1.5}
h1,h2,h3{font-family:"Fraunces",Georgia,serif;font-weight:400;margin:0;color:var(--primary)}
button,input,select{font:inherit;color:inherit}
[hidden]{display:none!important}
.wrap{max-width:68rem;margin:0 auto;padding:0 max(1rem,env(safe-area-inset-left)) 4rem max(1rem,env(safe-area-inset-right))}
header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border);margin-bottom:1.5rem}
header .brand{display:flex;align-items:center;gap:.75rem}
header img{width:3.2rem;height:3.2rem;object-fit:contain}
.btn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;height:2.4rem;padding:0 1.1rem;border-radius:999px;border:1px solid transparent;background:var(--primary);color:var(--primary-fg);font-weight:500;font-size:.9rem;cursor:pointer;white-space:nowrap}
.btn:hover{filter:brightness(1.08)}
.btn.sec{background:var(--card);color:var(--primary);border-color:var(--border)}
.btn.sm{height:2rem;padding:0 .8rem;font-size:.82rem}
.btn.danger{background:var(--card);color:var(--danger);border-color:color-mix(in oklab,var(--danger) 35%,var(--border))}
.btn:disabled{opacity:.55;cursor:default}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:1.25rem}
.login{max-width:24rem;margin:4rem auto;display:grid;gap:1rem;text-align:center}
.login img{width:7rem;margin:0 auto}
label{display:grid;gap:.3rem;font-size:.82rem;font-weight:500;color:var(--secondary-fg)}
input,select{height:2.4rem;border:1px solid var(--border);border-radius:.6rem;background:var(--bg);padding:0 .7rem;width:100%}
.msg{font-size:.88rem;padding:.6rem .8rem;border-radius:.6rem}
.msg.err{background:color-mix(in oklab,var(--danger) 12%,var(--card));color:var(--danger)}
.msg.ok{background:var(--secondary);color:var(--secondary-fg)}
.tabs{display:flex;flex-wrap:wrap;gap:.35rem;padding:.3rem;background:var(--secondary);border-radius:999px;width:fit-content;max-width:100%;margin-bottom:1.5rem}
.tabs button{border:0;background:none;padding:.45rem 1rem;border-radius:999px;cursor:pointer;color:var(--secondary-fg);font-size:.9rem}
.tabs button[aria-selected="true"]{background:var(--card);color:var(--fg);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.grid2{display:grid;gap:1.25rem}
@media(min-width:860px){.grid2{grid-template-columns:1fr 1fr}}
.grid2>*{min-width:0}
.day{border-top:1px solid var(--border);padding:.9rem 0}
.day:first-child{border-top:0}
.day h3{font-size:1.05rem;margin-bottom:.5rem}
.day h3::first-letter{text-transform:uppercase}
.cita{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;padding:.6rem .75rem;border-radius:.7rem;background:var(--muted);margin:.35rem 0}
.cita .h{font-weight:600;font-variant-numeric:tabular-nums;min-width:3.2rem}
.cita .who{flex:1;min-width:12rem}
.cita .who small{display:block;color:var(--muted-fg)}
.pill{font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:.2rem .55rem;border-radius:999px;background:var(--secondary);color:var(--secondary-fg)}
.pill.online{background:#ECE8E3;color:#5E554F}
.cita.cancelada{opacity:.55}
.pago{display:inline-flex;align-items:center;gap:.3rem;font-size:.75rem;font-weight:600;padding:.2rem .55rem;border-radius:999px;background:var(--secondary);color:var(--secondary-fg);white-space:nowrap}
.pago.pe{background:#F6EBD6;color:#7A5A1F}.pago.no{background:#F3E4E1;color:var(--danger)}
.cobro-w{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;width:100%}
.cobro-w select{height:2rem;width:auto;font-size:.8rem;padding:0 .5rem}
.cobro-w input{height:2rem;width:5rem;font-size:.8rem;padding:0 .5rem;text-align:right}
.cobro-w .lnk,.lnk{border:0;background:none;color:var(--primary);font-size:.78rem;cursor:pointer;padding:0;text-decoration:underline}
.totales{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.8rem}
.totales span{background:var(--muted);border-radius:.7rem;padding:.45rem .7rem;font-size:.82rem}
.totales b{display:block;font-size:1.05rem;font-variant-numeric:tabular-nums}
.venta{display:flex;flex-wrap:wrap;gap:.3rem 1rem;align-items:center;padding:.6rem .75rem;border-radius:.7rem;background:var(--muted);margin:.35rem 0}
.venta .q{flex:1;min-width:12rem}.venta small{display:block;color:var(--muted-fg)}
.venta .t{font-weight:600;font-variant-numeric:tabular-nums}
.venta.anulada{opacity:.5;text-decoration:line-through}
table.stock{min-width:32rem}table.stock td{vertical-align:middle}
.grid2>*>*{min-width:0}
table.stock .st{font-weight:600;font-variant-numeric:tabular-nums}
table.stock .st.bajo{color:var(--danger)}
table.stock .ent{display:flex;gap:.3rem}table.stock .ent input{width:4.5rem;height:2rem}
.pasos{margin:.4rem 0;padding-left:1.2rem;font-size:.85rem;display:grid;gap:.35rem}
#s-code{flex:1;min-width:0;font:.72rem/1.4 ui-monospace,Menlo,monospace;border:1px solid var(--border);border-radius:.6rem;padding:.5rem;background:var(--bg);resize:vertical}
.card.manana{border-color:color-mix(in oklab,var(--primary) 35%,var(--border))}
.cita .rec{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center}
.ok-tag{font-size:.75rem;color:var(--secondary-fg);background:var(--secondary);border-radius:999px;padding:.15rem .55rem}
.btn.wa{background:#2E7D4F;border-color:#2E7D4F;color:#fff}
.btn.wa.hecho{background:transparent;color:#2E7D4F}
.ej summary{cursor:pointer;font-size:.88rem;color:var(--primary);margin-bottom:.4rem}
.ej pre{background:var(--muted);border-radius:.6rem;padding:.6rem .75rem;font-size:.8rem;white-space:pre-wrap;margin:0}
#imp-txt{width:100%;font:inherit;font-size:.9rem;border:1px solid var(--border);border-radius:.7rem;padding:.6rem .75rem;background:var(--card);resize:vertical;margin-top:.6rem}
.tbl-wrap{overflow-x:auto;margin:.8rem 0}
.foto-box{display:flex;flex-wrap:wrap;gap:.8rem;align-items:end}
.foto-box label.btn{cursor:pointer}
.dia-foto{flex:1 1 12rem;font-size:.85rem}
.foto-prev{display:flex;gap:.8rem;align-items:center;margin-top:.8rem;padding:.6rem;border-radius:.8rem;background:var(--muted)}
.foto-prev img{width:4.5rem;height:4.5rem;object-fit:cover;border-radius:.5rem;flex:none}
.foto-prev>div{flex:1;min-width:0}
.barra{height:.4rem;border-radius:999px;background:var(--border);overflow:hidden;margin-top:.4rem}
.barra span{display:block;height:100%;width:0;background:var(--primary);transition:width .3s}
table.imp{width:100%;border-collapse:collapse;font-size:.85rem}
table.imp th{text-align:left;font-weight:600;color:var(--muted-fg);font-size:.75rem;padding:.3rem .4rem;border-bottom:1px solid var(--border)}
table.imp td{padding:.4rem;border-bottom:1px solid var(--border);vertical-align:top}
table.imp td small{display:block;color:var(--muted-fg)}
table.imp tr.mal td{background:#FBEFEC}
table.imp td.ie{font-size:.78rem;color:#9A3B2E;min-width:9rem}table.imp td.ie small{font-size:.72rem}table.imp td:first-child{white-space:nowrap}
.copy{display:flex;gap:.4rem}.copy input{flex:1;min-width:0;font-size:.78rem}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.cita.cancelada .who{text-decoration:line-through}
.week{display:grid;gap:.6rem}
.wd{display:grid;grid-template-columns:6.5rem 1fr;gap:.6rem;align-items:start;padding:.55rem 0;border-top:1px solid var(--border)}
.wd:first-child{border-top:0}
.wd b{padding-top:.35rem;font-weight:500}
.tramos{display:grid;gap:.4rem}
.tramo{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
.tramo input{width:8.6rem}
.x{border:0;background:none;color:var(--danger);cursor:pointer;font-size:1.2rem;line-height:1;padding:.2rem .4rem}
.add{border:1px dashed var(--border);background:none;border-radius:.6rem;padding:.3rem .7rem;color:var(--primary);cursor:pointer;font-size:.82rem;width:fit-content}
.row{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}
.row>label{flex:1 1 9rem}
.list li{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between;padding:.6rem 0;border-top:1px solid var(--border)}
.list{list-style:none;margin:0;padding:0}
.list li:first-child{border-top:0}
.tbl-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:.8rem}
.tbl{width:100%;border-collapse:collapse;font-size:.88rem;min-width:44rem}
.tbl th{text-align:left;font-weight:600;font-size:.75rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted-fg);background:var(--muted);padding:.6rem .75rem;position:sticky;top:0}
.tbl td{padding:.6rem .75rem;border-top:1px solid var(--border);vertical-align:top}
.tbl td.n{font-variant-numeric:tabular-nums;white-space:nowrap}
.tbl tr.anulada td{color:var(--muted-fg)}
.tbl tr.anulada td.p{text-decoration:line-through}
.tbl mark{background:var(--secondary);color:inherit;border-radius:.2rem;padding:0 .1rem}
.est{font-size:.72rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:.15rem .5rem;border-radius:999px;background:var(--secondary);color:var(--secondary-fg);white-space:nowrap}
.est.no{background:#F3E4E1;color:var(--danger)}
.est.pe{background:#F6EBD6;color:#7A5A1F}
.badge{display:inline-grid;place-items:center;min-width:1.3rem;height:1.3rem;padding:0 .35rem;border-radius:999px;background:#C98A2B;color:#fff;font-size:.72rem;font-weight:600;margin-left:.25rem}
.pend{border-color:#E8CF9F;background:#FFFBF2}
.cita.pendiente{background:#FBF1DE}
.cita .acts{display:flex;gap:.4rem;flex-wrap:wrap}
.motivo{display:block;color:var(--secondary-fg);font-size:.82rem}
.semana{display:grid;gap:1rem}
.sem-top{display:flex;flex-wrap:wrap;gap:1rem;align-items:end;justify-content:space-between}
.sem-mes{flex:0 1 16rem}
.sem-mes select::first-letter{text-transform:uppercase}
.sem-nav{display:flex;align-items:center;gap:.6rem;flex:1 1 22rem;justify-content:flex-end}
.sem-nav h3{font-size:1.1rem;text-align:center;flex:1;margin:0}
.sem-dias{display:grid;gap:.4rem}
.sd{display:grid;grid-template-columns:9.5rem minmax(0,1fr) auto;gap:.5rem 1rem;align-items:start;padding:.6rem .75rem;border-radius:.8rem;background:var(--muted)}
.sd .dn{font-weight:600;padding-top:.35rem}
.sd .dn small{display:block;font-weight:400;color:var(--muted-fg);font-size:.78rem}
.sd .dn::first-letter{text-transform:uppercase}
.sd .tramos{display:flex;flex-wrap:wrap;gap:.4rem .8rem;align-items:center}
.sd .tag{font-size:.72rem;font-weight:600;padding:.15rem .5rem;border-radius:999px;background:var(--card);color:var(--muted-fg);white-space:nowrap}
.sd .tag.cam{background:#F6EBD6;color:#7A5A1F}
.sd .tag.cer{background:#F3E4E1;color:var(--danger)}
.sd .side{display:flex;flex-direction:column;gap:.3rem;align-items:flex-end}
.sd .lnk{border:0;background:none;color:var(--primary);font-size:.78rem;cursor:pointer;padding:0;text-decoration:underline}
.sd.pas{opacity:.45}
.sd.otro .dn{color:var(--muted-fg)}
.sd.blo{background:repeating-linear-gradient(135deg,#F3E4E1 0 6px,var(--muted) 6px 12px)}
.sem-acc{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}
@media(max-width:640px){.sd .tramo{display:flex;flex-wrap:nowrap;align-items:center;gap:.3rem}.sd .tramo input{width:7.4rem;min-width:0;padding:.45rem .4rem}.sd{grid-template-columns:1fr auto}.sd .tramos{grid-column:1/-1;grid-row:2}.sem-nav{flex-basis:100%}}
.abierto{display:flex;flex-wrap:wrap;gap:1rem 1.5rem;align-items:end;border-color:var(--leaf);background:color-mix(in oklab,var(--secondary) 40%,var(--card))}
.muted{color:var(--muted-fg);font-size:.88rem}
.sticky{position:sticky;bottom:0;padding:.8rem 0;background:linear-gradient(transparent,var(--bg) 35%);display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
.toast{position:fixed;left:50%;bottom:1.25rem;transform:translateX(-50%);background:var(--fg);color:var(--bg);padding:.6rem 1rem;border-radius:999px;font-size:.9rem;z-index:10}
.dialog{position:fixed;inset:0;background:rgba(30,32,28,.45);display:grid;place-items:center;padding:1rem;z-index:20}
.dialog .card{max-width:26rem;width:100%;display:grid;gap:.9rem}
.chk{display:flex;gap:.5rem;align-items:center;font-weight:400;color:var(--fg)}
.chk input{width:auto;height:auto}
</style>
</head>
<body>
<div class="wrap">

  <section id="v-login" class="login card" hidden>
    <img src="../img/logo.webp" alt="Nutriendo Hábitos">
    <h1 id="login-title">Panel de reservas</h1>
    <p class="muted" id="login-sub">Escribe tu contraseña.</p>
    <form id="f-login" style="display:grid;gap:.8rem;text-align:left">
      <label for="l-pass">Contraseña<input id="l-pass" type="password" autocomplete="current-password" required></label>
      <label for="l-pass2" id="l-pass2-w" hidden>Repite la contraseña<input id="l-pass2" type="password" autocomplete="new-password"></label>
      <p class="msg err" id="l-err" hidden></p>
      <button class="btn" type="submit" id="l-btn">Entrar</button>
    </form>
  </section>

  <section id="v-panel" hidden>
    <header>
      <div class="brand"><img src="../img/logo.webp" alt=""><div><h1 style="font-size:1.4rem">Reservas</h1><span class="muted">Nutriendo Hábitos · citas de <span id="dur">45</span> min</span></div></div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap"><a class="btn sec sm" href="../reservar.html" target="_blank" rel="noopener">Ver calendario público</a><button class="btn sec sm" id="b-salir">Salir</button></div>
    </header>

    <div class="tabs" role="tablist">
      <button role="tab" aria-selected="true" data-tab="citas">Citas <span class="badge" id="n-pend" hidden></span></button>
      <button role="tab" aria-selected="false" data-tab="horario">Horario</button>
      <button role="tab" aria-selected="false" data-tab="bloqueos">Días bloqueados</button>
      <button role="tab" aria-selected="false" data-tab="historial">Historial</button>
      <button role="tab" aria-selected="false" data-tab="ventas">Ventas</button>
      <button role="tab" aria-selected="false" data-tab="ajustes">Ajustes</button>
    </div>

    <!-- CITAS -->
    <div data-panel="citas">
      <div class="grid2">
        <div style="display:grid;gap:1.25rem;align-content:start">
          <div class="card manana" id="man-card">
            <h2 style="font-size:1.3rem;margin-bottom:.25rem">Recordatorios de mañana</h2>
            <p class="muted" style="margin:0 0 .5rem" id="man-sub"></p>
            <div id="man-list"></div>
            <button class="btn sec sm" type="button" id="b-recordar" style="margin-top:.6rem" hidden>Enviar ahora los emails de mañana</button>
          </div>
          <div class="card pend" id="pend-card" hidden><h2 style="font-size:1.3rem;margin-bottom:.25rem">Pendientes de confirmar</h2><p class="muted" style="margin:0 0 .5rem">Estos huecos ya están bloqueados en la web. Al confirmar, al paciente le llega el email de confirmación definitiva.</p><div id="citas-pend"></div></div>
          <div class="card"><h2 style="font-size:1.3rem;margin-bottom:.5rem">Próximas citas confirmadas</h2><div id="citas-prox"></div></div>
        </div>
        <div style="display:grid;gap:1.25rem;align-content:start">
          <div class="card" id="imp-card">
            <h2 style="font-size:1.2rem;margin-bottom:.4rem">Pasar citas de tu agenda</h2>
            <p class="muted" style="margin:0 0 .8rem">Haz una foto a la página de la agenda y el panel lee las citas. Tú revisas lo que ha entendido, corriges si hace falta y pulsas «Añadir citas». La foto se lee en tu propio móvil u ordenador: no se envía a ningún sitio.</p>
            <div class="foto-box">
              <label class="btn" for="imp-foto"><svg viewBox="0 0 24 24" aria-hidden="true" style="width:1.1rem;height:1.1rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg> Foto de la agenda</label>
              <input id="imp-foto" type="file" accept="image/*" class="sr">
              <label for="imp-dia" class="dia-foto">Día de la página (si es de un solo día)<input id="imp-dia" type="date"></label>
            </div>
            <div class="foto-prev" id="foto-prev" hidden><img id="foto-img" alt="Foto de la agenda"><div><p id="foto-est" style="margin:0;font-weight:500"></p><div class="barra"><span id="foto-barra"></span></div><p class="muted" id="foto-txt" style="margin:.3rem 0 0;font-size:.8rem"></p></div></div>
            <p class="muted" style="margin:.8rem 0 0;font-size:.85rem">O pega o escribe las citas, una por línea:</p>
            <details class="ej"><summary>Ver cómo escribirlas</summary>
              <pre>12/11 10:00 María López García 600123456
12/11 10:45 Juan Pérez online
Viernes 14/11
9:30 Lucía Martín 611222333 lucia@gmail.com
11:15 Pedro Sanz presencial</pre>
              <p class="muted" style="margin:.4rem 0 0">Una línea con solo el día vale para las horas de debajo. Si pones «online» o «presencial» se usa eso; si no, lo que elijas abajo. Teléfono y email son opcionales.</p>
            </details>
            <label for="imp-txt" class="sr">Citas de la agenda</label>
            <textarea id="imp-txt" rows="7" placeholder="12/11 10:00 María López 600123456"></textarea>
            <div class="row" style="margin-top:.6rem">
              <label for="imp-mod">Si no lo pone, son<select id="imp-mod"><option value="presencial">Presencial</option><option value="online">Online</option></select></label>
              <label for="imp-motivo">Motivo<select id="imp-motivo"><option value="">Sin indicar</option></select></label>
            </div>
            <button class="btn sec" type="button" id="imp-rev" style="margin-top:.7rem">Revisar citas</button>
            <div id="imp-prev" hidden>
              <div class="tbl-wrap"><table class="imp"><thead><tr><th>Día</th><th>Hora</th><th>Paciente</th><th>Consulta</th><th></th></tr></thead><tbody id="imp-body"></tbody></table></div>
              <p class="msg" id="imp-msg" hidden></p>
              <button class="btn" type="button" id="imp-ok">Añadir citas</button>
            </div>
          </div>
          <div class="card">
            <h2 style="font-size:1.2rem;margin-bottom:.75rem">Añadir una cita a mano</h2>
            <p class="muted" style="margin:0 0 .75rem">Para citas que te piden por teléfono o WhatsApp. Ese hueco deja de estar libre en la web.</p>
            <form id="f-cita" style="display:grid;gap:.7rem">
              <div class="row">
                <label for="c-fecha">Día<input id="c-fecha" type="date" required></label>
                <label for="c-hora">Hora<input id="c-hora" type="time" step="300" required></label>
                <label for="c-mod">Consulta<select id="c-mod"><option value="presencial">Presencial</option><option value="online">Online</option></select></label>
              </div>
              <label for="c-motivo">Motivo<select id="c-motivo"><option value="">Sin indicar</option></select></label>
              <div class="row">
                <label for="c-nombre">Nombre<input id="c-nombre" required></label>
                <label for="c-ape">Apellidos<input id="c-ape"></label>
              </div>
              <div class="row">
                <label for="c-tel">Teléfono<input id="c-tel" type="tel"></label>
                <label for="c-email">Email<input id="c-email" type="email"></label>
              </div>
              <p class="msg err" id="c-err" hidden></p>
              <button class="btn" type="submit">Añadir cita</button>
            </form>
          </div>
          <div class="card"><h2 style="font-size:1.2rem;margin-bottom:.5rem">Citas pasadas (últimos 30 días)</h2><div id="citas-pas"></div></div>
        </div>
      </div>
    </div>

    <!-- HORARIO -->
    <div data-panel="horario" hidden>
      <div class="card abierto">
        <div style="display:grid;gap:.35rem;min-width:0;flex:1 1 18rem">
          <h2 style="font-size:1.3rem">Reservas abiertas hasta</h2>
          <p class="muted" style="margin:0">Los pacientes solo pueden reservar hasta este día. Ve abriendo mes a mes, o de dos en dos, cuando tengas planificado tu horario.</p>
          <p style="margin:.2rem 0 0;font-weight:500;color:var(--secondary-fg)" id="lim-txt"></p>
        </div>
        <div class="row" style="flex:1 1 20rem;align-items:end">
          <label for="h-abierto" style="flex:1 1 10rem">Último día que se puede reservar<input id="h-abierto" type="date"></label>
          <button class="btn sec sm" type="button" data-mas="1">+1 mes</button>
          <button class="btn sec sm" type="button" data-mas="2">+2 meses</button>
        </div>
      </div>

      <!-- Horario de cada semana -->
      <h2 style="font-size:1.3rem;margin:1.6rem 0 .3rem">Horario de cada semana</h2>
      <p class="muted" style="margin-top:0">Elige el mes y ve semana a semana. Las horas que pongas sirven igual para citas presenciales y online: el paciente elige la modalidad al reservar. Al cambiar de semana se guarda solo.</p>
      <div class="card semana">
        <div class="sem-top">
          <label for="s-mes" class="sem-mes">Mes<select id="s-mes"></select></label>
          <div class="sem-nav">
            <button class="btn sec sm" type="button" id="s-prev" aria-label="Semana anterior">‹</button>
            <h3 id="s-titulo"></h3>
            <button class="btn sec sm" type="button" id="s-next" aria-label="Semana siguiente">›</button>
          </div>
        </div>
        <p class="msg err" id="s-aviso" hidden></p>
        <div class="sem-dias" id="s-dias"></div>
        <p class="msg err" id="s-err" hidden></p>
        <div class="sem-acc">
          <button class="btn" type="button" id="s-guardar">Guardar semana</button>
          <button class="btn sec" type="button" id="s-repetir">Repetir esta semana en el resto del mes</button>
          <span class="muted" id="s-msg"></span>
        </div>
      </div>

      <h2 style="font-size:1.3rem;margin:2rem 0 .3rem">Horario habitual</h2>
      <p class="muted" style="margin-top:0">Lo normal de cada semana. Es lo que aparece al abrir una semana nueva; luego lo cambias arriba en los días que sean distintos. La web divide las horas en huecos de <span class="dur2">45</span> minutos, y cada hueco se puede reservar una vez (presencial u online).</p>
      <div class="card" style="max-width:44rem"><div class="week" id="w-habitual"></div></div>
      <div class="sticky"><button class="btn" id="b-guardar-h">Guardar horario habitual y fecha</button><span class="muted" id="h-msg"></span></div>
    </div>

    <!-- BLOQUEOS -->
    <div data-panel="bloqueos" hidden>
      <div class="grid2">
        <div class="card">
          <h2 style="font-size:1.2rem;margin-bottom:.5rem">Bloquear días</h2>
          <p class="muted" style="margin:0 0 .75rem">Vacaciones, festivos o días que no vas a pasar consulta. Para bloquear un solo día, deja «Hasta» vacío.</p>
          <form id="f-bloq" style="display:grid;gap:.7rem">
            <div class="row">
              <label for="b-desde">Desde<input id="b-desde" type="date" required></label>
              <label for="b-hasta">Hasta<input id="b-hasta" type="date"></label>
            </div>
            <div class="row">
              <label for="b-mod">Afecta a<select id="b-mod"><option value="todas">Presencial y online</option><option value="presencial">Solo presencial</option><option value="online">Solo online</option></select></label>
              <label for="b-nota">Nota (opcional)<input id="b-nota" maxlength="80" placeholder="Vacaciones, festivo…"></label>
            </div>
            <p class="msg err" id="b-err" hidden></p>
            <button class="btn" type="submit">Bloquear</button>
          </form>
        </div>
        <div class="card"><h2 style="font-size:1.2rem;margin-bottom:.5rem">Días bloqueados</h2><ul class="list" id="bloq-list"></ul></div>
      </div>
    </div>

    <!-- HISTORIAL -->
    <div data-panel="historial" hidden>
      <div class="card" style="display:grid;gap:1rem">
        <div class="row" style="align-items:end">
          <label for="h-q" style="flex:2 1 16rem">Buscar paciente<input id="h-q" type="search" placeholder="Nombre, apellidos, teléfono o email" autocomplete="off"></label>
          <label for="h-est" style="flex:1 1 10rem">Mostrar<select id="h-est"><option value="todas">Todas</option><option value="pendiente">Pendientes</option><option value="confirmada">Confirmadas</option><option value="cancelada">Anuladas</option></select></label>
          <label for="h-cuando" style="flex:1 1 10rem">Fechas<select id="h-cuando"><option value="todas">Todas</option><option value="pasadas">Pasadas</option><option value="proximas">Próximas</option></select></label>
          <button class="btn" type="button" id="h-excel" style="flex:0 0 auto">Descargar en Excel</button>
        </div>
        <p class="muted" id="h-count" style="margin:0"></p>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>Fecha</th><th>Hora</th><th>Consulta</th><th>Paciente</th><th>Contacto</th><th>Estado</th><th>Cobro</th></tr></thead>
          <tbody id="h-body"></tbody>
        </table></div>
        <p class="muted" style="margin:0">El archivo de Excel incluye todas las citas, también las anuladas. Guárdalo de vez en cuando como copia de seguridad.</p>
      </div>
    </div>

    <!-- VENTAS (productos de la consulta: solo en el panel) -->
    <div data-panel="ventas" hidden>
      <div class="grid2">
        <div style="display:grid;gap:1.25rem;align-content:start">
          <div class="card">
            <h2 style="font-size:1.3rem;margin-bottom:.25rem">Nueva venta</h2>
            <p class="muted" style="margin:0 0 .8rem">Lo que vendes en la consulta. Al guardarla se descuenta del stock.</p>
            <form id="f-venta" style="display:grid;gap:.7rem">
              <label for="v-cliente">Cliente<input id="v-cliente" list="dl-clientes" autocomplete="off" placeholder="Escribe para buscar un paciente" required></label>
              <datalist id="dl-clientes"></datalist>
              <label for="v-prod">Producto<select id="v-prod" required></select></label>
              <div class="row">
                <label for="v-uds" style="flex:1 1 6rem">Unidades<input id="v-uds" type="number" min="1" value="1" required></label>
                <label for="v-total" style="flex:1 1 8rem">Total (€)<input id="v-total" inputmode="decimal" required></label>
                <label for="v-met" style="flex:2 1 10rem">Forma de pago<select id="v-met"><option value="efectivo">Efectivo</option><option value="datafono">Datáfono</option><option value="bizum">Bizum / transferencia</option></select></label>
              </div>
              <label for="v-nota">Nota (opcional)<input id="v-nota" maxlength="200"></label>
              <p class="msg err" id="v-err" hidden></p>
              <button class="btn" type="submit">Registrar venta</button>
            </form>
          </div>
          <div class="card">
            <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:.6rem">
              <h2 style="font-size:1.3rem">Ventas</h2>
              <label for="v-mes" style="flex:0 1 12rem"><span class="sr">Mes</span><select id="v-mes"></select></label>
            </div>
            <div class="totales" id="v-totales"></div>
            <div id="v-lista"></div>
          </div>
        </div>
        <div style="display:grid;gap:1.25rem;align-content:start">
          <div class="card">
            <h2 style="font-size:1.3rem;margin-bottom:.6rem">Productos y stock</h2>
            <div class="tbl-wrap"><table class="tbl stock"><thead><tr><th>Producto</th><th>Precio</th><th>Stock</th><th>Añadir unidades</th><th></th></tr></thead><tbody id="p-body"></tbody></table></div>
          </div>
          <div class="card">
            <h2 style="font-size:1.2rem;margin-bottom:.6rem" id="p-titulo">Añadir producto</h2>
            <form id="f-prod" style="display:grid;gap:.7rem">
              <input type="hidden" id="p-id">
              <label for="p-nombre">Nombre<input id="p-nombre" maxlength="80" placeholder="Ej.: Proteína whey vainilla 1 kg" required></label>
              <div class="row">
                <label for="p-precio" style="flex:1 1 8rem">Precio de venta (€)<input id="p-precio" inputmode="decimal" required></label>
                <label for="p-stock" style="flex:1 1 8rem">Unidades en stock<input id="p-stock" type="number" min="0" value="0" required></label>
              </div>
              <p class="msg err" id="p-err" hidden></p>
              <div class="row"><button class="btn" type="submit" id="p-btn">Añadir producto</button><button class="btn sec" type="button" id="p-cancelar" hidden>Cancelar</button></div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- AJUSTES -->
    <div data-panel="ajustes" hidden>
      <div class="grid2">
        <div class="card" style="display:grid;gap:.8rem;align-content:start">
          <h2 style="font-size:1.2rem">Reservas</h2>
          <label for="a-ant">Antelación mínima (horas)<input id="a-ant" type="number" min="0" max="168"></label>
          <p class="muted" style="margin:-.4rem 0 0">Nadie podrá reservar una cita que empiece antes de este tiempo.</p>
          <label for="a-vista">Se puede reservar hasta (días por delante)<input id="a-vista" type="number" min="7" max="365"></label>
          <div><button class="btn" id="b-guardar-a">Guardar ajustes</button> <span class="muted" id="a-msg"></span></div>
        </div>
        <div class="card" style="display:grid;gap:.7rem;align-content:start">
          <h2 style="font-size:1.2rem">Recordatorios automáticos</h2>
          <label class="chk" for="r-on"><input id="r-on" type="checkbox"> Enviar un email de recordatorio el día antes de cada cita confirmada</label>
          <p class="muted" style="margin:0">Para que salgan solos cada día, crea una vez la tarea en DonDominio: <b>Hosting → Tareas programadas (Cron) → Crear tarea</b>, todos los días a las 10:00, con esta dirección:</p>
          <div class="copy"><input id="r-url" readonly aria-label="Dirección para la tarea programada"><button class="btn sec sm" type="button" id="r-copiar">Copiar</button></div>
          <p class="muted" style="margin:0;font-size:.8rem">No compartas esta dirección. Aunque no crees la tarea, puedes mandarlos tú desde «Citas» → «Enviar ahora los emails de mañana». Los recordatorios por WhatsApp se envían desde ese mismo recuadro con un toque por paciente.</p>
        </div>
        <div class="card" style="display:grid;gap:.7rem;align-content:start">
          <h2 style="font-size:1.2rem">Precio y pago online</h2>
          <label for="g-precio">Precio de la consulta (€)<input id="g-precio" inputmode="decimal" placeholder="Ej.: 45"></label>
          <p class="muted" style="margin:-.3rem 0 0;font-size:.8rem">Se propone al marcar un cobro y es lo que se paga online.</p>
          <label class="chk" for="g-on"><input id="g-on" type="checkbox"> Ofrecer el pago con tarjeta al reservar en la web (Redsys)</label>
          <p class="msg" id="g-estado"></p>
          <details class="ej"><summary>Datos de Redsys (te los da tu banco)</summary>
            <div style="display:grid;gap:.6rem;margin-top:.4rem">
              <div class="row">
                <label for="g-com" style="flex:2 1 9rem">Número de comercio (FUC)<input id="g-com" inputmode="numeric" autocomplete="off"></label>
                <label for="g-ter" style="flex:1 1 5rem">Terminal<input id="g-ter" inputmode="numeric" value="1"></label>
              </div>
              <label for="g-clave">Clave secreta SHA-256<input id="g-clave" type="password" autocomplete="off" placeholder="Pégala aquí"></label>
              <label for="g-ent">Entorno<select id="g-ent"><option value="pruebas">Pruebas (no cobra de verdad)</option><option value="real">Real</option></select></label>
              <p class="muted" style="margin:0;font-size:.8rem">La clave secreta se guarda solo en tu hosting y no vuelve a mostrarse. En la configuración de Redsys, la «URL de notificación» es la que el panel envía sola; no hace falta ponerla a mano.</p>
            </div>
          </details>
          <p class="msg err" id="g-err" hidden></p>
          <div><button class="btn" type="button" id="b-guardar-g">Guardar</button></div>
        </div>
        <div class="card" style="display:grid;gap:.7rem;align-content:start">
          <h2 style="font-size:1.2rem">Copia en Google Sheets</h2>
          <p class="muted" style="margin:0" id="s-estado"></p>
          <details class="ej"><summary>Cómo conectarla (solo una vez)</summary>
            <ol class="pasos">
              <li>Entra en Google con <b>nutricion.arguello@gmail.com</b> y crea una hoja nueva en <a href="https://sheets.new" target="_blank" rel="noopener">sheets.new</a>. Ponle de nombre «Nutriendo Hábitos · Citas y ventas».</li>
              <li>Menú <b>Extensiones → Apps Script</b>. Borra lo que haya y pega este código:</li>
            </ol>
            <div class="copy" style="align-items:start"><textarea id="s-code" readonly rows="6" aria-label="Código para Apps Script"></textarea><button class="btn sec sm" type="button" id="s-copiar">Copiar</button></div>
            <ol class="pasos" start="3">
              <li>Pulsa <b>Guardar</b> y luego <b>Implementar → Nueva implementación</b>. En el engranaje elige <b>Aplicación web</b>; «Ejecutar como: <b>yo</b>» y «Quién tiene acceso: <b>cualquier usuario</b>». Pulsa <b>Implementar</b> y da permiso con tu cuenta.</li>
              <li>Copia la <b>URL de la aplicación web</b> (termina en <code>/exec</code>), pégala aquí abajo y pulsa «Guardar y enviar todo».</li>
            </ol>
          </details>
          <label for="s-url">URL de la aplicación web<input id="s-url" placeholder="https://script.google.com/macros/s/…/exec" autocomplete="off"></label>
          <p class="msg err" id="s-err" hidden></p>
          <div class="row"><button class="btn" type="button" id="b-sheets">Guardar y enviar todo</button></div>
          <p class="muted" style="margin:0;font-size:.8rem">Desde ese momento, cada cita, cobro y venta se copia sola en la hoja (pestañas «Citas», «Ventas» y «Stock»). Contiene datos de salud de tus pacientes: no compartas la hoja.</p>
        </div>
        <div class="card">
          <h2 style="font-size:1.2rem;margin-bottom:.75rem">Cambiar contraseña</h2>
          <form id="f-pass" style="display:grid;gap:.7rem">
            <label for="p-act">Contraseña actual<input id="p-act" type="password" autocomplete="current-password" required></label>
            <label for="p-new">Nueva contraseña (mínimo 8 caracteres)<input id="p-new" type="password" autocomplete="new-password" minlength="8" required></label>
            <p class="msg" id="p-msg" hidden></p>
            <button class="btn" type="submit">Cambiar contraseña</button>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<div class="dialog" id="dlg" hidden role="dialog" aria-modal="true" aria-labelledby="dlg-t">
  <div class="card">
    <h2 id="dlg-t" style="font-size:1.25rem">Anular cita</h2>
    <p id="dlg-txt" style="margin:0"></p>
    <label class="chk" for="dlg-avisar" id="dlg-avisar-w"><input id="dlg-avisar" type="checkbox" checked> Avisar al paciente por email</label>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap"><button class="btn sec" id="dlg-no">No, mantener</button><button class="btn danger" id="dlg-si">Anular cita</button></div>
  </div>
</div>
<div class="toast" id="toast" hidden></div>

<script>
(function(){
  var TOKEN = '', D = null, H = null;
  try { TOKEN = sessionStorage.getItem('nh_t') || ''; } catch (e) {}
  function ponerToken(t){ TOKEN = t || ''; try { if (TOKEN) sessionStorage.setItem('nh_t', TOKEN); else sessionStorage.removeItem('nh_t'); } catch (e) {} }
  var DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
  var MOD = { presencial: 'Presencial', online: 'Online' };
  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function api(accion, datos){
    var body = Object.assign({ accion: accion, _t: TOKEN }, datos || {});
    return fetch('admin.php', { method: 'POST', credentials: 'omit', cache: 'no-store', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'red' }; }); }, function(){ return { ok: false, error: 'red' }; })
      .then(function(r){ if (r && r.error === 'no_autorizado' && accion !== 'estado') { ponerToken(''); mostrarLogin(true); } return r; });
  }
  function toast(t){ var e = $('toast'); e.textContent = t; e.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(function(){ e.hidden = true; }, 2600); }
  function fechaLarga(f){
    var d = new Date(f + 'T12:00:00');
    return d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }
  function fechaCorta(f){ var d = new Date(f + 'T12:00:00'); return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }); }
  var ERR_EXTRA = { importe: 'Pon un importe válido (por ejemplo 45 o 45,50).', pagado_online: 'Esta cita ya está pagada online y no se puede cambiar a mano.', clave_redsys: 'La clave secreta de Redsys no parece correcta: cópiala tal cual te la da el banco.', redsys_incompleto: 'Para activar el pago online faltan datos: precio, número de comercio, terminal y clave secreta.', url_sheets: 'La dirección tiene que ser la de la aplicación web de Apps Script (empieza por https://script.google.com/macros/s/ y termina en /exec).', sheets: 'No se ha podido conectar con la hoja de Google. Revisa que la implementación sea «Aplicación web» con acceso «Cualquier usuario» y que hayas pegado el código completo.', stock: 'No hay unidades suficientes en stock.', no_encontrada: 'No se ha encontrado. Recarga la página.' };
  var ERR = { password: 'Contraseña incorrecta.', limite: 'Demasiados intentos. Espera 15 minutos y vuelve a probar.', corta: 'La contraseña debe tener al menos 8 caracteres.', ocupado: 'Ese hueco ya tiene una cita.', datos: 'Revisa los datos: falta algo o no es válido.', red: 'No hay conexión con el servidor.', sesion: 'La sesión ha caducado. Recarga la página.', no_autorizado: 'La sesión ha caducado. Recarga la página.', tramo: 'Hay un tramo con la hora de fin antes que la de inicio.', escritura: 'El servidor no deja guardar datos en la carpeta reservas/datos. En el administrador de archivos de DonDominio, pon permisos 755 a las carpetas «reservas» y «datos».', servidor: 'Ha fallado algo en el servidor. Prueba otra vez en un momento.' };
  function errTxt(r){ return ERR[r && r.error] || ERR_EXTRA[r && r.error] || 'No se ha podido guardar. Prueba otra vez.'; }

  /* ---------- acceso ---------- */
  var primeraVez = false;
  function mostrarLogin(configurado){
    primeraVez = !configurado;
    $('v-panel').hidden = true; $('v-login').hidden = false;
    $('login-title').textContent = primeraVez ? 'Crea tu contraseña' : 'Panel de reservas';
    $('login-sub').textContent = primeraVez ? 'Es la primera vez que entras. Elige una contraseña de al menos 8 caracteres; solo tú podrás gestionar las citas.' : 'Escribe tu contraseña.';
    $('l-pass2-w').hidden = !primeraVez; $('l-btn').textContent = primeraVez ? 'Crear y entrar' : 'Entrar';
    $('l-pass').setAttribute('autocomplete', primeraVez ? 'new-password' : 'current-password');
    $('l-pass').focus();
  }
  $('f-login').addEventListener('submit', function(e){
    e.preventDefault(); var p = $('l-pass').value, err = $('l-err'); err.hidden = true;
    if (primeraVez && p !== $('l-pass2').value) { err.textContent = 'Las dos contraseñas no coinciden.'; err.hidden = false; return; }
    api(primeraVez ? 'crear_password' : 'entrar', { password: p }).then(function(r){
      if (!r.ok) { err.textContent = errTxt(r); err.hidden = false; return; }
      ponerToken(r.token); $('l-pass').value = ''; $('l-pass2').value = ''; cargar();
    });
  });
  $('b-salir').addEventListener('click', function(){ api('salir').then(function(){ ponerToken(''); location.reload(); }); });

  /* ---------- pestañas ---------- */
  document.querySelectorAll('[data-tab]').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('[data-tab]').forEach(function(x){ x.setAttribute('aria-selected', x === b); });
      document.querySelectorAll('[data-panel]').forEach(function(p){ p.hidden = p.getAttribute('data-panel') !== b.getAttribute('data-tab'); });
    });
  });

  /* ---------- datos ---------- */
  function cargar(){
    return api('datos').then(function(r){
      if (!r.ok) { if (r.error !== 'no_autorizado') toast(errTxt(r)); return; }
      D = r; H = unir(r.horario);
      $('v-login').hidden = true; $('v-panel').hidden = false;
      $('dur').textContent = r.duracion; document.querySelectorAll('.dur2').forEach(function(e){ e.textContent = r.duracion; });
      ['c-motivo', 'imp-motivo'].forEach(function(id){ if ($(id).options.length < 2) (r.motivos || []).forEach(function(m){ var o = document.createElement('option'); o.value = o.textContent = m; $(id).appendChild(o); }); });
      $('r-on').checked = !!r.recordatorios; $('r-url').value = r.url_cron || '';
      pintarManana(); pintarAjustesPago(); pintarVentas();
      pintarCitas(); pintarHorario(); pintarBloqueos();
      $('a-ant').value = r.antelacion_horas; $('a-vista').value = r.dias_vista;
      $('h-abierto').value = r.abierto_hasta || ''; $('h-abierto').min = r.hoy; pintarLimite(); if (!SUCIO) { if (SEM) abrirSemana(SEM); else pintarSemana(); }
      $('c-fecha').min = r.hoy;
    });
  }

  function pintarCitas(){
    var pend = D.citas.filter(function(c){ return c.estado === 'pendiente' && c.fecha >= D.hoy; });
    $('pend-card').hidden = !pend.length; $('citas-pend').innerHTML = agrupar(pend, true);
    $('n-pend').hidden = !pend.length; $('n-pend').textContent = pend.length;
    var prox = D.citas.filter(function(c){ return c.fecha >= D.hoy && c.estado !== 'pendiente'; });
    var pas = D.citas.filter(function(c){ return c.fecha < D.hoy; }).reverse();
    $('citas-prox').innerHTML = agrupar(prox, true) || '<p class="muted">Todavía no hay citas reservadas.</p>';
    $('citas-pas').innerHTML = agrupar(pas, false) || '<p class="muted">Sin citas en los últimos 30 días.</p>';
  }
  function agrupar(lista, conAcciones){
    var html = '', dia = '';
    lista.forEach(function(c){
      if (c.fecha !== dia) { if (dia) html += '</div>'; dia = c.fecha; html += '<div class="day"><h3>' + esc(fechaLarga(c.fecha)) + '</h3>'; }
      var canc = c.estado === 'cancelada', pend = c.estado === 'pendiente';
      var acts = '';
      if (conAcciones && pend) acts = '<span class="acts"><button class="btn sm" data-confirm="' + esc(c.id) + '">Confirmar</button><button class="btn danger sm" data-cancel="' + esc(c.id) + '">Rechazar</button></span>';
      else if (conAcciones && !canc) acts = '<button class="btn danger sm" data-cancel="' + esc(c.id) + '">Anular</button>';
      html += '<div class="cita' + (canc ? ' cancelada' : '') + (pend ? ' pendiente' : '') + '"><span class="h">' + esc(c.hora) + '</span>' +
        '<span class="pill' + (c.modalidad === 'online' ? ' online' : '') + '">' + esc(MOD[c.modalidad]) + '</span>' +
        '<span class="who">' + esc(c.nombre + ' ' + (c.apellidos || '')) + (c.motivo ? '<span class="motivo">' + esc(c.motivo) + '</span>' : '') + '<small>' + esc([c.telefono, c.email].filter(Boolean).join(' · ')) + (c.origen === 'panel' ? ' · añadida por ti' : '') + (canc ? (c.estado_previo === 'pendiente' ? ' · RECHAZADA' : ' · ANULADA') : '') + '</small></span>' +
        acts + (canc ? '' : cobroHTML(c)) + '</div>';
    });
    return html ? html + '</div>' : '';
  }
  /* ---------- cobros de las citas ---------- */
  function euros(v){ return v === '' || v == null ? '' : String((+v).toFixed(2)).replace('.', ',').replace(',00', '') + ' €'; }
  function textoCobro(c){
    var p = c.pago || {};
    if (p.estado === 'pagado') return (D.metodos[p.metodo] || p.metodo) + ' · ' + euros(p.importe);
    if (p.metodo === 'online') return p.estado === 'fallido' ? 'Pago online fallido' : 'Pago online pendiente';
    return 'Sin cobrar';
  }
  function cobroHTML(c){
    var p = c.pago || {}, id = esc(c.id);
    if (p.estado === 'pagado' && p.metodo === 'online') return '<div class="cobro-w"><span class="pago">✓ Pagado online · ' + esc(euros(p.importe)) + '</span></div>';
    if (p.estado === 'pagado') return '<div class="cobro-w"><span class="pago">✓ Cobrado: ' + esc(textoCobro(c)) + '</span><button type="button" class="lnk" data-cobro-quitar="' + id + '">Cambiar</button></div>';
    var aviso = p.metodo === 'online' ? '<span class="pago ' + (p.estado === 'fallido' ? 'no' : 'pe') + '">' + esc(textoCobro(c)) + '</span>' : '';
    return '<div class="cobro-w">' + aviso + '<select data-cobro="' + id + '" aria-label="Forma de cobro"><option value="">Marcar cobro…</option><option value="efectivo">Efectivo</option><option value="datafono">Datáfono</option><option value="bizum">Bizum / transferencia</option></select>' +
      '<input data-imp="' + id + '" inputmode="decimal" value="' + esc((D.precio || '').replace('.', ',').replace(',00', '')) + '" aria-label="Importe en euros" placeholder="€"><span class="muted" style="font-size:.8rem">€</span></div>';
  }
  document.addEventListener('change', function(e){
    var s = e.target.closest('[data-cobro]'); if (!s || !s.value) return;
    var id = s.getAttribute('data-cobro'), imp = document.querySelector('[data-imp="' + id + '"]');
    s.disabled = true;
    api('cobro', { id: id, metodo: s.value, importe: imp ? imp.value : '' }).then(function(r){
      s.disabled = false;
      if (!r.ok) { s.value = ''; return toast(errTxt(r)); }
      toast('Cobro guardado.'); cargar();
    });
  });
  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-cobro-quitar]'); if (!b) return;
    if (!confirm('¿Quitar el cobro de esta cita para volver a marcarlo?')) return;
    api('cobro', { id: b.getAttribute('data-cobro-quitar'), metodo: '' }).then(function(r){ if (!r.ok) return toast(errTxt(r)); cargar(); });
  });
  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-cancel]'); if (!b) return;
    var c = D.citas.find(function(x){ return x.id === b.getAttribute('data-cancel'); }); if (!c) return;
    var pend = c.estado === 'pendiente';
    $('dlg-t').textContent = pend ? 'Rechazar solicitud' : 'Anular cita';
    $('dlg-si').textContent = pend ? 'Rechazar' : 'Anular cita';
    $('dlg-txt').textContent = c.nombre + ' ' + (c.apellidos || '') + ' · ' + fechaLarga(c.fecha) + ' a las ' + c.hora + ' (' + MOD[c.modalidad] + '). El hueco vuelve a quedar libre en la web.';
    $('dlg-avisar-w').hidden = !c.email; $('dlg-avisar').checked = !!c.email;
    $('dlg').hidden = false; $('dlg').dataset.id = c.id; $('dlg-no').focus();
  });
  $('dlg-no').addEventListener('click', function(){ $('dlg').hidden = true; });
  $('dlg-si').addEventListener('click', function(){
    var id = $('dlg').dataset.id, b = this; b.disabled = true;
    api('cancelar_cita', { id: id, avisar: $('dlg-avisar').checked }).then(function(r){
      b.disabled = false; $('dlg').hidden = true;
      if (!r.ok) return toast(errTxt(r));
      toast(r.avisado ? 'Hecho. Se ha avisado al paciente y el hueco vuelve a estar libre.' : 'Hecho. El hueco vuelve a estar libre.'); cargar();
    });
  });

  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-confirm]'); if (!b) return;
    b.disabled = true; b.textContent = 'Confirmando…';
    api('confirmar_cita', { id: b.getAttribute('data-confirm') }).then(function(r){
      if (!r.ok) { b.disabled = false; b.textContent = 'Confirmar'; return toast(errTxt(r)); }
      toast(r.avisado ? 'Cita confirmada. Se ha enviado el email de confirmación al paciente.' : 'Cita confirmada. No se ha podido enviar el email: avísale por teléfono.'); cargar();
    });
  });

  $('f-cita').addEventListener('submit', function(e){
    e.preventDefault(); var err = $('c-err'); err.hidden = true;
    api('anadir_cita', { fecha: $('c-fecha').value, hora: $('c-hora').value, modalidad: $('c-mod').value, nombre: $('c-nombre').value, apellidos: $('c-ape').value, telefono: $('c-tel').value, email: $('c-email').value, motivo: $('c-motivo').value })
      .then(function(r){ if (!r.ok) { err.textContent = errTxt(r); err.hidden = false; return; } e.target.reset(); toast('Cita añadida.'); cargar(); });
  });

  /* ---------- ajustes: precio, Redsys y Google Sheets ---------- */
  var CODIGO_GS = [
    '// Nutriendo Hábitos: recibe las citas, ventas y stock del panel de reservas',
    "const CLAVE = '{TOKEN}';",
    '',
    'function doPost(e) {',
    '  const d = JSON.parse(e.postData.contents);',
    '  if (d.token !== CLAVE) return salida({ ok: false });',
    '  const cerrojo = LockService.getScriptLock(); cerrojo.waitLock(20000);',
    '  try {',
    '    const libro = SpreadsheetApp.getActiveSpreadsheet();',
    "    ['Citas', 'Ventas'].forEach(function (n) { volcar(hoja(libro, n, d.cabeceras[n]), d[n] || []); });",
    "    if (d.Stock) { const h = hoja(libro, 'Stock', d.cabeceras.Stock);",
    '      if (h.getLastRow() > 1) h.getRange(2, 1, h.getLastRow() - 1, h.getLastColumn()).clearContent();',
    '      if (d.Stock.length) h.getRange(2, 1, d.Stock.length, d.Stock[0].length).setValues(d.Stock); }',
    '  } finally { cerrojo.releaseLock(); }',
    '  return salida({ ok: true });',
    '}',
    'function hoja(libro, nombre, cab) {',
    '  let h = libro.getSheetByName(nombre) || libro.insertSheet(nombre);',
    "  if (h.getLastRow() === 0) { h.appendRow(cab); h.setFrozenRows(1); h.getRange(1, 1, 1, cab.length).setFontWeight('bold'); }",
    '  return h;',
    '}',
    'function volcar(h, filas) {',
    '  const n = h.getLastRow() - 1;',
    '  const ids = n > 0 ? h.getRange(2, 1, n, 1).getValues().map(function (r) { return String(r[0]); }) : [];',
    '  filas.forEach(function (f) {',
    '    const i = ids.indexOf(String(f[0]));',
    '    if (i >= 0) h.getRange(i + 2, 1, 1, f.length).setValues([f]); else { h.appendRow(f); ids.push(String(f[0])); }',
    '  });',
    '}',
    'function salida(o) { return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }'
  ].join('\n');
  function pintarAjustesPago(){
    if (document.activeElement && /^g-|^s-url/.test(document.activeElement.id)) return;
    $('g-precio').value = (D.precio || '').replace('.', ',');
    $('g-on').checked = !!D.pago_online; $('g-com').value = D.redsys.comercio || ''; $('g-ter').value = D.redsys.terminal || '1'; $('g-ent').value = D.redsys.entorno || 'pruebas';
    $('g-clave').value = ''; $('g-clave').placeholder = D.redsys.clave ? 'Guardada ✓ (escribe solo para cambiarla)' : 'Pégala aquí';
    var est = $('g-estado');
    if (D.pago_online && D.redsys_listo) { est.className = 'msg ok'; est.textContent = 'Pago online ACTIVADO' + (D.redsys.entorno === 'real' ? '' : ' en modo PRUEBAS (no se cobra de verdad)') + '. Los pacientes pueden pagar ' + euros(D.precio) + ' al reservar.'; }
    else { est.className = 'msg'; est.textContent = 'Pago online APAGADO: en la web los pacientes no ven la opción de pagar; pagan en la consulta.'; }
    $('s-code').value = CODIGO_GS.replace('{TOKEN}', D.sheets.token || '');
    $('s-url').value = D.sheets.url || '';
    $('s-estado').textContent = D.sheets.url ? 'Conectada: las citas, cobros y ventas se copian solos en tu hoja de Google.' : 'Sin conectar todavía. Sigue los pasos para que todo se copie solo en tu hoja de Google.';
  }
  $('b-guardar-g').addEventListener('click', function(){
    var b = this, err = $('g-err'); err.hidden = true; b.disabled = true;
    api('guardar_pago', { precio: $('g-precio').value, activo: $('g-on').checked, comercio: $('g-com').value, terminal: $('g-ter').value, clave: $('g-clave').value, entorno: $('g-ent').value }).then(function(r){
      b.disabled = false;
      if (!r.ok) { err.textContent = errTxt(r); err.hidden = false; if (r.error === 'redsys_incompleto') $('g-on').checked = false; return; }
      toast('Guardado.'); cargar();
    });
  });
  $('s-copiar').addEventListener('click', function(){
    var t = $('s-code'); t.select();
    (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.reject()).then(function(){ toast('Código copiado.'); }, function(){ document.execCommand('copy'); toast('Código copiado.'); });
  });
  $('b-sheets').addEventListener('click', function(){
    var b = this, err = $('s-err'); err.hidden = true; b.disabled = true; b.textContent = 'Enviando…';
    api('sheets_guardar', { url: $('s-url').value.trim() }).then(function(r){
      if (!r.ok) throw r;
      if (!$('s-url').value.trim()) return { ok: true, vacio: true };
      return api('sheets_todo');
    }).then(function(r){
      if (!r.ok) throw r;
      toast(r.vacio ? 'Copia en Google Sheets desconectada.' : '¡Conectada! Todo se ha copiado en tu hoja de Google.'); cargar();
    }).catch(function(r){ err.textContent = errTxt(r); err.hidden = false; })
      .then(function(){ b.disabled = false; b.textContent = 'Guardar y enviar todo'; });
  });

  /* ---------- ventas y stock ---------- */
  var MESES_V = null;
  function productosActivos(){ return (D.tienda.productos || []).filter(function(p){ return !p.borrado; }); }
  function pintarVentas(){
    var prods = productosActivos(), sel = $('v-prod'), prev = sel.value;
    sel.innerHTML = prods.length ? prods.map(function(p){ return '<option value="' + esc(p.id) + '"' + (+p.stock < 1 ? ' disabled' : '') + '>' + esc(p.nombre) + ' — ' + esc(euros(p.precio)) + ' (' + (+p.stock) + ' en stock)</option>'; }).join('') : '<option value="">Primero añade un producto →</option>';
    if (prev && prods.some(function(p){ return p.id === prev; })) sel.value = prev;
    if (!$('v-total').dataset.tocado) calcTotal();
    $('dl-clientes').innerHTML = (D.clientes || []).map(function(n){ return '<option value="' + esc(n) + '">'; }).join('');
    $('p-body').innerHTML = prods.length ? prods.map(function(p){
      return '<tr><td>' + esc(p.nombre) + '</td><td>' + esc(euros(p.precio)) + '</td><td class="st' + (+p.stock <= 2 ? ' bajo' : '') + '">' + (+p.stock) + '</td>' +
        '<td><span class="ent"><input type="number" min="1" placeholder="+" data-ent-n="' + esc(p.id) + '" aria-label="Unidades que entran de ' + esc(p.nombre) + '"><button class="btn sec sm" type="button" data-ent="' + esc(p.id) + '">Añadir</button></span></td>' +
        '<td style="white-space:nowrap"><button class="lnk" type="button" data-pedit="' + esc(p.id) + '">Editar</button> · <button class="lnk" type="button" data-pdel="' + esc(p.id) + '">Quitar</button></td></tr>';
    }).join('') : '<tr><td colspan="5" class="muted">Todavía no hay productos. Añádelos abajo con su precio y las unidades que tienes.</td></tr>';
    // meses con ventas
    var ventas = (D.tienda.ventas || []).slice().sort(function(a, b){ return a.fecha < b.fecha ? 1 : -1; });
    var meses = {}; ventas.forEach(function(v){ meses[v.fecha.slice(0, 7)] = 1; }); meses[D.hoy.slice(0, 7)] = 1;
    var ms = Object.keys(meses).sort().reverse(), msel = $('v-mes'), mv = msel.value || D.hoy.slice(0, 7);
    msel.innerHTML = ms.map(function(m){ var t = new Date(m + '-15T12:00:00').toLocaleDateString('es-ES', { month: 'long', year: 'numeric' }); return '<option value="' + m + '">' + t.charAt(0).toUpperCase() + t.slice(1) + '</option>'; }).join('') + '<option value="todas">Todas</option>';
    msel.value = ms.indexOf(mv) >= 0 || mv === 'todas' ? mv : ms[0];
    var lista = ventas.filter(function(v){ return msel.value === 'todas' || v.fecha.slice(0, 7) === msel.value; });
    var tot = { total: 0 }; lista.forEach(function(v){ if (v.anulada) return; tot.total += +v.total; tot[v.metodo] = (tot[v.metodo] || 0) + (+v.total); });
    $('v-totales').innerHTML = '<span>Total<b>' + euros(tot.total) + '</b></span>' + ['efectivo', 'datafono', 'bizum'].filter(function(m){ return tot[m]; }).map(function(m){ return '<span>' + esc(D.metodos[m]) + '<b>' + euros(tot[m]) + '</b></span>'; }).join('');
    $('v-lista').innerHTML = lista.length ? lista.map(function(v){
      return '<div class="venta' + (v.anulada ? ' anulada' : '') + '"><span class="q">' + esc(v.producto) + ' × ' + (+v.unidades) + '<small>' + esc(v.cliente) + ' · ' + esc(new Date(v.fecha).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })) + ' · ' + esc(D.metodos[v.metodo] || v.metodo) + (v.nota ? ' · ' + esc(v.nota) : '') + (v.anulada ? ' · ANULADA' : '') + '</small></span><span class="t">' + esc(euros(v.total)) + '</span>' +
        (v.anulada ? '' : '<button class="btn danger sm" type="button" data-vanular="' + esc(v.id) + '">Anular</button>') + '</div>';
    }).join('') : '<p class="muted">Sin ventas en este mes.</p>';
  }
  function calcTotal(){
    var p = productosActivos().find(function(x){ return x.id === $('v-prod').value; }), n = Math.max(1, +$('v-uds').value || 1);
    $('v-total').value = p ? String((+p.precio * n).toFixed(2)).replace('.', ',') : '';
  }
  $('v-prod').addEventListener('change', function(){ delete $('v-total').dataset.tocado; calcTotal(); });
  $('v-uds').addEventListener('input', function(){ delete $('v-total').dataset.tocado; calcTotal(); });
  $('v-total').addEventListener('input', function(){ this.dataset.tocado = '1'; });
  $('v-mes').addEventListener('change', pintarVentas);
  $('f-venta').addEventListener('submit', function(e){
    e.preventDefault(); var err = $('v-err'); err.hidden = true;
    if (!$('v-prod').value) { err.textContent = 'Primero añade el producto en «Productos y stock».'; err.hidden = false; return; }
    api('venta_registrar', { cliente: $('v-cliente').value.trim(), producto: $('v-prod').value, unidades: +$('v-uds').value, total: $('v-total').value, metodo: $('v-met').value, nota: $('v-nota').value }).then(function(r){
      if (!r.ok) { err.textContent = errTxt(r); err.hidden = false; return; }
      toast('Venta registrada. Stock actualizado.'); $('v-cliente').value = ''; $('v-uds').value = 1; $('v-nota').value = ''; delete $('v-total').dataset.tocado; cargar();
    });
  });
  function limpiarProd(){ $('f-prod').reset(); $('p-id').value = ''; $('p-titulo').textContent = 'Añadir producto'; $('p-btn').textContent = 'Añadir producto'; $('p-cancelar').hidden = true; $('p-err').hidden = true; }
  $('p-cancelar').addEventListener('click', limpiarProd);
  $('f-prod').addEventListener('submit', function(e){
    e.preventDefault(); var err = $('p-err'); err.hidden = true;
    api('producto_guardar', { id: $('p-id').value, nombre: $('p-nombre').value.trim(), precio: $('p-precio').value, stock: +$('p-stock').value }).then(function(r){
      if (!r.ok) { err.textContent = r.error === 'datos' ? 'Revisa el nombre, el precio (por ejemplo 29,90) y las unidades.' : errTxt(r); err.hidden = false; return; }
      toast($('p-id').value ? 'Producto actualizado.' : 'Producto añadido.'); limpiarProd(); cargar();
    });
  });
  document.addEventListener('click', function(e){
    var t;
    if ((t = e.target.closest('[data-pedit]'))) {
      var p = productosActivos().find(function(x){ return x.id === t.dataset.pedit; }); if (!p) return;
      $('p-id').value = p.id; $('p-nombre').value = p.nombre; $('p-precio').value = String(p.precio).replace('.', ','); $('p-stock').value = p.stock;
      $('p-titulo').textContent = 'Editar producto'; $('p-btn').textContent = 'Guardar cambios'; $('p-cancelar').hidden = false; $('p-nombre').focus();
    } else if ((t = e.target.closest('[data-pdel]'))) {
      if (!confirm('¿Quitar este producto de la lista? Las ventas que ya tiene se conservan.')) return;
      api('producto_borrar', { id: t.dataset.pdel }).then(function(r){ if (!r.ok) return toast(errTxt(r)); cargar(); });
    } else if ((t = e.target.closest('[data-ent]'))) {
      var inp = document.querySelector('[data-ent-n="' + t.dataset.ent + '"]'), n = parseInt(inp.value, 10);
      if (!n) { inp.focus(); return; }
      api('producto_entrada', { id: t.dataset.ent, unidades: n }).then(function(r){ if (!r.ok) return toast(errTxt(r)); toast('Stock actualizado: +' + n + '.'); cargar(); });
    } else if ((t = e.target.closest('[data-vanular]'))) {
      if (!confirm('¿Anular esta venta? Las unidades vuelven al stock.')) return;
      api('venta_anular', { id: t.dataset.vanular }).then(function(r){ if (!r.ok) return toast(errTxt(r)); toast('Venta anulada.'); cargar(); });
    }
  });

  /* ---------- recordatorios de mañana ---------- */
  function telWa(t){
    var d = String(t || '').replace(/\D/g, '');
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 9 && /^[6789]/.test(d)) d = '34' + d;
    return d.length >= 10 ? d : '';
  }
  function textoWa(c){
    return 'Hola ' + c.nombre + ', soy Andrea de Nutriendo Hábitos. Te recuerdo tu cita de mañana, ' + fechaLarga(c.fecha) + ', a las ' + c.hora +
      (c.modalidad === 'presencial' ? ', en ' + D.direccion + '.' : ' (consulta online; te mando el enlace de la videollamada).') +
      ' Si no puedes venir, avísame por aquí. ¡Hasta mañana!';
  }
  function pintarManana(){
    var lista = D.citas.filter(function(c){ return c.fecha === D.manana && c.estado === 'confirmada'; });
    $('man-sub').textContent = lista.length ? fechaLarga(D.manana) + '. ' + (D.recordatorios ? 'Los que tienen email reciben el recordatorio automático.' : 'El email automático está desactivado (Ajustes).') + ' Para WhatsApp, pulsa el botón y dale a enviar.' : 'Mañana (' + fechaLarga(D.manana) + ') no hay citas confirmadas.';
    var faltan = 0;
    $('man-list').innerHTML = lista.map(function(c){
      var tel = telWa(c.telefono), rec = '';
      if (c.email) { if (c.recordado) rec += '<span class="ok-tag">✓ Email enviado</span>'; else { faltan++; rec += '<span class="ok-tag" style="background:var(--muted);color:var(--muted-fg)">Email pendiente</span>'; } }
      if (tel) rec += '<a class="btn wa sm' + (c.recordado_wa ? ' hecho' : '') + '" target="_blank" rel="noopener" data-wa="' + esc(c.id) + '" href="https://wa.me/' + tel + '?text=' + encodeURIComponent(textoWa(c)) + '">' + (c.recordado_wa ? '✓ WhatsApp enviado' : 'Recordar por WhatsApp') + '</a>';
      if (!tel && !c.email) rec = '<span class="muted" style="font-size:.8rem">Sin teléfono ni email</span>';
      return '<div class="cita"><span class="h">' + esc(c.hora) + '</span><span class="who">' + esc(c.nombre + ' ' + (c.apellidos || '')) + '<small>' + esc(MOD[c.modalidad]) + (c.telefono ? ' · ' + esc(c.telefono) : '') + '</small></span><span class="rec">' + rec + '</span></div>';
    }).join('');
    $('b-recordar').hidden = !faltan;
  }
  document.addEventListener('click', function(e){
    var a = e.target.closest('[data-wa]'); if (!a) return;
    var id = a.getAttribute('data-wa');
    api('marcar_wa', { id: id }).then(function(){ var c = D.citas.find(function(x){ return x.id === id; }); if (c) c.recordado_wa = 1; a.classList.add('hecho'); a.textContent = '✓ WhatsApp enviado'; });
  });
  $('b-recordar').addEventListener('click', function(){
    var b = this; b.disabled = true;
    api('recordar_ahora').then(function(r){ b.disabled = false; if (!r.ok) return toast(errTxt(r)); toast(r.enviados === 1 ? 'Enviado 1 recordatorio.' : 'Enviados ' + r.enviados + ' recordatorios.'); cargar(); });
  });
  $('r-on').addEventListener('change', function(){
    api('ajuste_recordatorios', { activo: this.checked }).then(function(r){ toast(r.ok ? 'Guardado.' : errTxt(r)); cargar(); });
  });
  $('r-copiar').addEventListener('click', function(){
    var i = $('r-url'); i.select();
    (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).then(function(){ toast('Dirección copiada.'); }, function(){ document.execCommand('copy'); toast('Dirección copiada.'); });
  });

  /* ---------- pasar citas de la agenda ---------- */
  var MESES = { ene: 1, feb: 2, mar: 3, abr: 4, may: 5, jun: 6, jul: 7, ago: 8, sep: 9, set: 9, oct: 10, nov: 11, dic: 12 };
  var IMP = [];
  function fechaDe(d, m, y){
    var hoy = new Date(D.hoy + 'T12:00:00'), anio = y ? (y < 100 ? 2000 + y : y) : hoy.getFullYear();
    var f = new Date(anio, m - 1, d, 12);
    if (f.getMonth() !== m - 1 || f.getDate() !== d) return '';
    if (!y && (hoy - f) / 864e5 > 60) f = new Date(anio + 1, m - 1, d, 12);
    return iso(f);
  }
  function leerAgenda(texto){
    var out = [], dia = '', defMod = $('imp-mod').value;
    texto.split(/\r?\n/).forEach(function(linea, n){
      var l = linea.replace(/[–—]/g, '-').trim(); if (!l) return;
      var f = '', h = '', m, malDia = false;
      // día: 12/11, 12-11-2026 o «12 de noviembre»
      if ((m = l.match(/(^|[^\d:])(\d{1,2})\s*[\/-]\s*(\d{1,2})(?:\s*[\/-]\s*(\d{2,4}))?(?!\d)/))) { f = fechaDe(+m[2], +m[3], m[4] ? +m[4] : 0); if (f) l = l.replace(m[0], m[1] + ' '); else malDia = +m[3] > 0 && +m[3] <= 12; }
      else if ((m = l.match(/(^|[^\d:.])(\d{1,2})\s*(?:de\s+)?(ene(?:ro)?|feb(?:rero)?|mar(?:zo)?|abr(?:il)?|may(?:o)?|jun(?:io)?|jul(?:io)?|ago(?:sto)?|sep(?:tiembre)?|set(?:iembre)?|oct(?:ubre)?|nov(?:iembre)?|dic(?:iembre)?)\.?(?![a-záéíóúñ])(?:\s*(?:de\s+)?(\d{4}))?/i))) { f = fechaDe(+m[2], MESES[m[3].toLowerCase().slice(0, 3)], m[4] ? +m[4] : 0); if (f) l = l.replace(m[0], m[1] + ' '); else malDia = true; }
      // hora: 10:00, 10.30, 10h, 10:00h
      if ((m = l.match(/(^|\D)([01]?\d|2[0-3])\s*(?:[:.h]\s*([0-5]\d))\s*h?(?!\d)|(^|\D)([01]?\d|2[0-3])\s*h(?![a-z])/i))) {
        var hh = m[2] || m[5], mm = m[3] || '00'; h = String(hh).padStart(2, '0') + ':' + mm; l = l.replace(m[0], (m[1] || m[4] || '') + ' ');
      }
      if (f && !h) { dia = f; if (!l.replace(/lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|[\s,.:;-]/gi, '')) return; }
      if (!f && !malDia) f = dia;
      var email = ''; if ((m = l.match(/[^\s,;]+@[^\s,;]+\.[a-z]{2,}/i))) { email = m[0]; l = l.replace(m[0], ' '); }
      var tel = ''; if ((m = l.match(/\+?\d[\d\s.-]{7,15}\d/)) && m[0].replace(/\D/g, '').length >= 9) { tel = m[0].trim(); l = l.replace(m[0], ' '); }
      var mod = defMod;
      if (/\b(online|on-line|videollamada|video)\b/i.test(l)) mod = 'online'; else if (/\bpresencial\b/i.test(l)) mod = 'presencial';
      l = l.replace(/\b(online|on-line|videollamada|video|presencial|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|tel[eé]fono|tlf|tfno|m[oó]vil)\b\.?/gi, ' ')
           .replace(/[|·,;:()\[\]-]+/g, ' ').replace(/\s+/g, ' ').trim();
      var partes = l.split(' ').filter(Boolean);
      if (h && !partes.length && !tel && !email) return;   // hueco vacío de la agenda (solo la hora)
      var c = { linea: n + 1, original: linea.trim(), fecha: f, hora: h, modalidad: mod, nombre: partes[0] || '', apellidos: partes.slice(1).join(' '), telefono: tel, email: email, motivo: $('imp-motivo').value };
      c.error = malDia ? 'Ese día no existe' : !h ? 'Falta la hora' : !f ? 'Falta el día' : !c.nombre ? 'Falta el nombre' : f < D.hoy ? 'Es un día pasado' : '';
      if (!c.error && D.citas.some(function(x){ return x.fecha === f && x.hora === h && x.estado !== 'cancelada'; })) c.error = 'Ya hay una cita a esa hora';
      if (!c.error && out.some(function(x){ return !x.error && x.fecha === f && x.hora === h; })) c.error = 'Repetida en la lista';
      out.push(c);
    });
    return out;
  }
  /* ---------- leer la foto de la agenda (en el propio navegador, sin enviarla a ningún sitio) ---------- */
  var OCR = null;
  function cargarOCR(){
    if (OCR) return OCR;
    OCR = new Promise(function(ok, ko){ var sc = document.createElement('script'); sc.src = 'ocr/tesseract.min.js'; sc.onload = ok; sc.onerror = ko; document.head.appendChild(sc); })
      .then(function(){
        var base = location.href.replace(/[^\/]*$/, '') + 'ocr/';
        return Tesseract.createWorker('spa', 1, {
          workerPath: base + 'worker.min.js', corePath: base + 'core', langPath: base + 'lang', workerBlobURL: false, gzip: true, cacheMethod: 'none',
          logger: function(m){ if (m.status === 'recognizing text') progreso(m.progress, 'Leyendo la letra…'); else if (/load|initiali/.test(m.status)) progreso(.05, 'Preparando el lector (solo tarda la primera vez)…'); }
        });
      });
    OCR.catch(function(){ OCR = null; });
    return OCR;
  }
  function progreso(p, t){ $('foto-barra').style.width = Math.round(Math.max(.03, p) * 100) + '%'; if (t) $('foto-est').textContent = t; }
  function prepararFoto(file){
    // reduce el tamaño, pasa a gris y sube el contraste para que se lea mejor
    return new Promise(function(ok, ko){
      var img = new Image(); img.onload = function(){
        var esc_ = Math.min(1, 2200 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * esc_); c.height = Math.round(img.height * esc_);
        var x = c.getContext('2d'); x.drawImage(img, 0, 0, c.width, c.height);
        var d = x.getImageData(0, 0, c.width, c.height), a = d.data, min = 255, max = 0, i, g;
        for (i = 0; i < a.length; i += 4) { g = a[i] * .299 + a[i + 1] * .587 + a[i + 2] * .114; a[i] = g; if (g < min) min = g; if (g > max) max = g; }
        var r = Math.max(1, max - min);
        for (i = 0; i < a.length; i += 4) { g = Math.min(255, Math.max(0, (a[i] - min) * 255 / r)); g = g > 200 ? 255 : g; a[i] = a[i + 1] = a[i + 2] = g; }
        x.putImageData(d, 0, 0); URL.revokeObjectURL(img.src); ok(c);
      };
      img.onerror = ko; img.src = URL.createObjectURL(file);
    });
  }
  $('imp-foto').addEventListener('change', function(){
    var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    $('foto-prev').hidden = false; $('foto-img').src = URL.createObjectURL(f);
    $('foto-txt').textContent = ''; progreso(.02, 'Abriendo la foto…');
    Promise.all([prepararFoto(f), cargarOCR()]).then(function(r){
      progreso(.1, 'Leyendo la letra…');
      return r[1].recognize(r[0]);
    }).then(function(res){
      var lineas = String(res.data.text || '').split(/\r?\n/).map(function(l){ return l.replace(/[|_~«»"“”]/g, ' ').replace(/\s+/g, ' ').trim(); }).filter(function(l){ return /\d/.test(l) || /[a-záéíóúñ]{3,}/i.test(l); });
      var dia = $('imp-dia').value, cab = dia ? dia.split('-').reverse().slice(0, 2).join('/') : '';
      var txt = (cab ? cab + '\n' : '') + lineas.join('\n');
      var ta = $('imp-txt'); ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + txt;
      progreso(1, lineas.length ? 'Foto leída.' : 'No he podido leer texto en la foto.');
      $('foto-txt').textContent = lineas.length ? 'Revisa la lista de abajo: si algo está mal leído, corrígelo en el recuadro de texto y pulsa «Revisar citas» otra vez.' : 'Prueba con más luz, la página recta y sin sombras, o escribe las citas a mano.';
      if (lineas.length) $('imp-rev').click();
    }).catch(function(){
      progreso(0, 'No se ha podido leer la foto.');
      $('foto-txt').textContent = 'Comprueba la conexión y vuelve a probar. Si sigue fallando, escribe o pega las citas en el recuadro.';
    });
  });

  $('imp-rev').addEventListener('click', function(){
    IMP = leerAgenda($('imp-txt').value);
    var buenas = IMP.filter(function(c){ return !c.error; }).length, msg = $('imp-msg');
    $('imp-body').innerHTML = IMP.map(function(c){
      return '<tr class="' + (c.error ? 'mal' : '') + '"><td>' + (c.fecha ? esc(fechaCorta(c.fecha)) : '—') + '</td><td>' + esc(c.hora || '—') + '</td><td>' + esc((c.nombre + ' ' + c.apellidos).trim() || '—') + '<small>' + esc([c.telefono, c.email].filter(Boolean).join(' · ')) + '</small></td><td>' + esc(MOD[c.modalidad]) + '</td><td class="ie">' + (c.error ? '⚠ ' + esc(c.error) + '<small>Línea ' + c.linea + ': ' + esc(c.original) + '</small>' : '✓') + '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">No hay ninguna cita en el texto.</td></tr>';
    $('imp-prev').hidden = false;
    msg.hidden = !IMP.length; msg.className = 'msg' + (buenas < IMP.length ? ' err' : '');
    msg.textContent = buenas < IMP.length ? (IMP.length - buenas) + ' línea(s) con algo que revisar: corrígelas arriba y pulsa «Revisar» otra vez, o añade solo las correctas.' : 'Todo correcto. Revisa que los nombres estén bien escritos.';
    $('imp-ok').disabled = !buenas; $('imp-ok').textContent = buenas === 1 ? 'Añadir 1 cita' : 'Añadir ' + buenas + ' citas';
  });
  $('imp-ok').addEventListener('click', function(){
    var b = this, buenas = IMP.filter(function(c){ return !c.error; }); if (!buenas.length) return;
    b.disabled = true;
    api('anadir_citas', { citas: buenas }).then(function(r){
      b.disabled = false; if (!r.ok) return toast(errTxt(r));
      var mal = buenas.filter(function(c, i){ return r.resultados[i] !== 'ok'; });
      toast(r.anadidas === 1 ? '1 cita añadida.' : r.anadidas + ' citas añadidas.');
      $('imp-txt').value = mal.map(function(c){ return c.original; }).concat(IMP.filter(function(c){ return c.error; }).map(function(c){ return c.original; })).join('\n');
      $('imp-prev').hidden = true; cargar();
    });
  });

  /* ---------- horario habitual (igual para presencial y online) ---------- */
  function unir(h){
    var u = {};
    for (var d = 1; d <= 7; d++) { var p = (h.presencial || {})[d] || [], o = (h.online || {})[d] || []; u[d] = JSON.parse(JSON.stringify(p.length ? p : o)); }
    return u;
  }
  function tramosHTML(tramos, attrs, etiqueta){
    return tramos.map(function(t, i){
      return '<div class="tramo"><input type="time" step="300" aria-label="' + etiqueta + ' desde" value="' + t[0] + '" ' + attrs + ' data-i="' + i + '" data-k="0"> a ' +
        '<input type="time" step="300" aria-label="' + etiqueta + ' hasta" value="' + t[1] + '" ' + attrs + ' data-i="' + i + '" data-k="1">' +
        '<button class="x" type="button" title="Quitar" aria-label="Quitar horas" ' + attrs.replace(/data-(\w+)=/g, 'data-del$1=') + ' data-deli="' + i + '">×</button></div>';
    }).join('');
  }
  function nuevoTramo(l){ var last = l[l.length - 1]; l.push(last ? [last[1] < '16:00' ? '16:00' : last[1], last[1] < '16:00' ? '20:00' : '21:00'] : ['09:30', '14:00']); }
  function pintarHorario(){
    var html = '';
    for (var d = 1; d <= 7; d++) {
      var tramos = H[d] || [];
      html += '<div class="wd"><b>' + DIAS[d] + '</b><div class="tramos">' + tramosHTML(tramos, 'data-hd="' + d + '"', DIAS[d]) +
        (tramos.length ? '' : '<span class="muted" style="padding-top:.35rem">Cerrado</span>') +
        '<button class="add" type="button" data-addhd="' + d + '">+ Añadir horas</button></div></div>';
    }
    $('w-habitual').innerHTML = html;
  }
  document.addEventListener('input', function(e){
    var t = e.target; if (t.type !== 'time') return;
    if (t.dataset.hd) { H[t.dataset.hd][+t.dataset.i][+t.dataset.k] = t.value; $('h-msg').textContent = 'Cambios sin guardar'; }
    if (t.dataset.wf) { W[t.dataset.wf][+t.dataset.i][+t.dataset.k] = t.value; marcarSucio(); }
  });
  document.addEventListener('click', function(e){
    var a = e.target.closest('[data-addhd]'), x = e.target.closest('[data-delhd]');
    if (a) { nuevoTramo(H[a.dataset.addhd] = H[a.dataset.addhd] || []); pintarHorario(); $('h-msg').textContent = 'Cambios sin guardar'; }
    if (x) { H[x.dataset.delhd].splice(+x.dataset.deli, 1); pintarHorario(); $('h-msg').textContent = 'Cambios sin guardar'; }
  });
  function guardarHorario(btn, msg){
    btn.disabled = true;
    return api('guardar_horario', { horario: { presencial: H, online: H }, antelacion_horas: +$('a-ant').value, dias_vista: +$('a-vista').value, abierto_hasta: $('h-abierto').value }).then(function(r){
      btn.disabled = false;
      if (!r.ok) { msg.textContent = errTxt(r) + (r.detalle ? ' (' + r.detalle + ')' : ''); return; }
      msg.textContent = 'Guardado'; toast('Guardado. El calendario de la web ya está actualizado.'); cargar();
    });
  }
  $('b-guardar-h').addEventListener('click', function(){ guardarHorario(this, $('h-msg')); });
  $('b-guardar-a').addEventListener('click', function(){ guardarHorario(this, $('a-msg')); });

  /* ---------- reservas abiertas hasta ---------- */
  function pintarLimite(){
    var v = $('h-abierto').value;
    $('lim-txt').textContent = v ? 'Ahora se puede reservar hasta el ' + fechaLarga(v) + '.' + (v < D.hoy ? ' Esa fecha ya ha pasado: nadie puede reservar.' : '')
      : 'Sin fecha puesta: se puede reservar hasta ' + D.dias_vista + ' días por delante (lo cambias en Ajustes).';
  }
  function finDeMes(y, m){ var d = new Date(y, m + 1, 0); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-mas]'); if (!b) return;
    var base = $('h-abierto').value && $('h-abierto').value >= D.hoy ? $('h-abierto').value : D.hoy;
    var d = new Date(base + 'T12:00:00');
    // Abre hasta el final del mes siguiente (+1) o del de después (+2)
    $('h-abierto').value = finDeMes(d.getFullYear(), d.getMonth() + (+b.dataset.mas));
    pintarLimite(); $('h-msg').textContent = 'Cambios sin guardar · pulsa «Guardar horario»';
  });
  $('h-abierto').addEventListener('input', function(){ pintarLimite(); $('h-msg').textContent = 'Cambios sin guardar · pulsa «Guardar horario»'; });

  /* ---------- horario de cada semana ---------- */
  var SEM = null, W = {}, SUCIO = false;
  function iso(d){ return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function dia(f){ return new Date(f + 'T12:00:00'); }
  function sumar(f, n){ var d = dia(f); d.setDate(d.getDate() + n); return iso(d); }
  function lunesDe(f){ var d = dia(f); return sumar(f, -((d.getDay() + 6) % 7)); }
  function dowDe(f){ return dia(f).getDay() || 7; }
  function habitual(f){ return unir(D.horario)[dowDe(f)] || []; }
  function especialDe(f){ return (D.especiales || []).find(function(e){ return e.fecha === f && e.modalidad === 'presencial'; }) || (D.especiales || []).find(function(e){ return e.fecha === f; }); }
  function tramosDe(f){ var e = especialDe(f); return e ? e.tramos : habitual(f); }
  function bloqueoDe(f){ return (D.bloqueos || []).find(function(b){ return f >= b.desde && f <= b.hasta; }); }
  function txtTramos(t){ return t.map(function(x){ return x[0] + '–' + x[1]; }).join(' y '); }
  function igual(a, b){ return JSON.stringify(a) === JSON.stringify(b); }
  function mesDeSemana(lun){ return sumar(lun, 3).slice(0, 7); }   // el mes al que pertenece el jueves
  function marcarSucio(){ SUCIO = true; $('s-msg').textContent = 'Cambios sin guardar'; pintarTags(); }
  function abrirSemana(lun){
    SEM = lun < lunesDe(D.hoy) ? lunesDe(D.hoy) : lun; W = {}; SUCIO = false; $('s-msg').textContent = '';
    for (var i = 0; i < 7; i++) { var f = sumar(SEM, i); W[f] = JSON.parse(JSON.stringify(tramosDe(f))); }
    pintarSemana();
  }
  function pintarMeses(){
    var sel = $('s-mes'), h = dia(D.hoy), html = '';
    for (var i = 0; i < 13; i++) { var d = new Date(h.getFullYear(), h.getMonth() + i, 1), v = iso(d).slice(0, 7), t = d.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' }); html += '<option value="' + v + '">' + t.charAt(0).toUpperCase() + t.slice(1) + '</option>'; }
    sel.innerHTML = html;
  }
  function pintarSemana(){
    if (!SEM) { pintarMeses(); return abrirSemana(lunesDe(D.hoy)); }
    var dom = sumar(SEM, 6), a = dia(SEM), b = dia(dom), mesSel = mesDeSemana(SEM);
    if ($('s-mes').value !== mesSel) $('s-mes').value = mesSel;
    $('s-titulo').textContent = 'Semana del lunes ' + a.getDate() + (a.getMonth() !== b.getMonth() ? ' de ' + a.toLocaleDateString('es-ES', { month: 'long' }) : '') + ' al domingo ' + b.getDate() + ' de ' + b.toLocaleDateString('es-ES', { month: 'long' });
    $('s-prev').disabled = SEM <= lunesDe(D.hoy);
    var html = '';
    for (var i = 0; i < 7; i++) {
      var f = sumar(SEM, i), pas = f < D.hoy, bl = bloqueoDe(f), d = dia(f), t = W[f];
      html += '<div class="sd' + (pas ? ' pas' : '') + (bl ? ' blo' : '') + (f.slice(0, 7) !== mesSel ? ' otro' : '') + '" data-sd="' + f + '">' +
        '<div class="dn">' + DIAS[i + 1] + ' ' + d.getDate() + '<small>' + esc(d.toLocaleDateString('es-ES', { month: 'long' })) + '</small></div>' +
        '<div class="tramos">' + (pas ? '<span class="muted">' + (t.length ? esc(txtTramos(t)) : 'Cerrado') + '</span>' :
          tramosHTML(t, 'data-wf="' + f + '"', DIAS[i + 1] + ' ' + d.getDate()) + (t.length ? '' : '<span class="muted" style="padding-top:.35rem">Cerrado</span>') +
          '<button class="add" type="button" data-addwf="' + f + '">+ Añadir horas</button>') + '</div>' +
        '<div class="side"><span class="tag" data-tag="' + f + '"></span>' + (pas ? '' : (igual(t, habitual(f)) ? '' : '<button class="lnk" type="button" data-hab="' + f + '">Poner horario habitual</button>') + (t.length ? '<button class="lnk" type="button" data-cerrar="' + f + '">Cerrar este día</button>' : '')) + '</div></div>';
    }
    $('s-dias').innerHTML = html;
    pintarTags();
    var lim = D.limite || '', av = $('s-aviso');
    if (lim && dom > lim) { av.hidden = false; av.innerHTML = 'Los días después del ' + esc(fechaLarga(lim)) + ' aún no se pueden reservar en la web. <button class="btn sec sm" type="button" id="s-abrir" style="margin-left:.3rem">Abrir reservas hasta el ' + esc(fechaCorta(finDeMes(b.getFullYear(), b.getMonth()))) + '</button>'; }
    else av.hidden = true;
  }
  function pintarTags(){
    Object.keys(W).forEach(function(f){
      var el = document.querySelector('[data-tag="' + f + '"]'); if (!el) return;
      var bl = bloqueoDe(f), t = W[f], hab = igual(t, habitual(f));
      el.className = 'tag' + (bl ? ' cer' : !t.length ? ' cer' : !hab ? ' cam' : '');
      el.textContent = bl ? 'Bloqueado' + (bl.nota ? ': ' + bl.nota : '') : !t.length ? 'Cerrado' : hab ? 'Horario habitual' : 'Horario especial';
    });
  }
  function cambiosSemana(fechas, origen){
    var dias = {};
    fechas.forEach(function(f){ if (f < D.hoy) return; var t = (origen || W)[f]; dias[f] = igual(t, habitual(f)) ? null : t; });
    return dias;
  }
  function guardarDias(dias, texto){
    var err = $('s-err'); err.hidden = true;
    if (!Object.keys(dias).length) return Promise.resolve(true);
    for (var f in dias) { var t = dias[f] || []; for (var i = 0; i < t.length; i++) if (!t[i][0] || !t[i][1] || t[i][0] >= t[i][1]) { err.textContent = 'Revisa las horas del ' + fechaLarga(f) + ': la hora de fin tiene que ser después de la de inicio.'; err.hidden = false; return Promise.resolve(false); } }
    return api('guardar_dias', { dias: dias }).then(function(r){
      if (!r.ok) { err.textContent = errTxt(r) + (r.detalle ? ' (' + r.detalle + ')' : ''); err.hidden = false; return false; }
      SUCIO = false; $('s-msg').textContent = 'Guardado'; if (texto) toast(texto);
      return cargarSinPintarSemana().then(function(){ return true; });
    });
  }
  function cargarSinPintarSemana(){ var s = SEM; return cargar().then(function(){ abrirSemana(s); }); }
  function irA(lun){
    if (!SUCIO) return abrirSemana(lun);
    guardarDias(cambiosSemana(Object.keys(W)), 'Semana guardada.').then(function(ok){ if (ok) abrirSemana(lun); });
  }
  $('s-prev').addEventListener('click', function(){ irA(sumar(SEM, -7)); });
  $('s-next').addEventListener('click', function(){ irA(sumar(SEM, 7)); });
  $('s-mes').addEventListener('change', function(){ var l = lunesDe(this.value + '-01'); if (mesDeSemana(l) !== this.value) l = sumar(l, 7); irA(l); });
  $('s-guardar').addEventListener('click', function(){ guardarDias(cambiosSemana(Object.keys(W)), 'Semana guardada. La web ya muestra estas horas.'); });
  $('s-repetir').addEventListener('click', function(){
    var mes = mesDeSemana(SEM), dias = cambiosSemana(Object.keys(W)), n = 0, f = sumar(SEM, 7);
    while (f.slice(0, 7) <= mes) {
      if (f.slice(0, 7) === mes) { var orig = sumar(SEM, (dowDe(f) - 1)); var t = W[orig]; dias[f] = igual(t, habitual(f)) ? null : JSON.parse(JSON.stringify(t)); n++; }
      f = sumar(f, 1);
    }
    if (!n) return toast('Esta es la última semana del mes.');
    guardarDias(dias, 'Guardado. Las semanas siguientes de ' + dia(mes + '-01').toLocaleDateString('es-ES', { month: 'long' }) + ' tienen ahora este horario.');
  });
  document.addEventListener('click', function(e){
    var a = e.target.closest('[data-addwf]'), x = e.target.closest('[data-delwf]'), h = e.target.closest('[data-hab]'), c = e.target.closest('[data-cerrar]');
    if (a) { nuevoTramo(W[a.dataset.addwf]); }
    else if (x) { W[x.dataset.delwf].splice(+x.dataset.deli, 1); }
    else if (h) { W[h.dataset.hab] = JSON.parse(JSON.stringify(habitual(h.dataset.hab))); }
    else if (c) { W[c.dataset.cerrar] = []; }
    else return;
    pintarSemana(); marcarSucio();
  });
  document.addEventListener('click', function(e){
    if (!e.target.closest('#s-abrir')) return;
    var b = dia(sumar(SEM, 6)); $('h-abierto').value = finDeMes(b.getFullYear(), b.getMonth());
    var pend = SUCIO ? guardarDias(cambiosSemana(Object.keys(W))) : Promise.resolve(true);
    pend.then(function(ok){ if (ok) guardarHorario($('b-guardar-h'), $('h-msg')).then(function(){ setTimeout(function(){ abrirSemana(SEM); }, 400); }); });
  });

  /* ---------- bloqueos ---------- */
  function pintarBloqueos(){
    var L = { todas: 'Presencial y online', presencial: 'Solo presencial', online: 'Solo online' };
    var futuros = D.bloqueos.filter(function(b){ return b.hasta >= D.hoy; });
    $('bloq-list').innerHTML = futuros.length ? futuros.map(function(b){
      return '<li><span><b>' + esc(b.desde === b.hasta ? fechaCorta(b.desde) : fechaCorta(b.desde) + ' – ' + fechaCorta(b.hasta)) + '</b><br><span class="muted">' + esc(L[b.modalidad]) + (b.nota ? ' · ' + esc(b.nota) : '') + '</span></span>' +
        '<button class="btn sec sm" data-unbloq="' + esc(b.id) + '">Desbloquear</button></li>';
    }).join('') : '<li class="muted">No hay días bloqueados.</li>';
  }
  $('f-bloq').addEventListener('submit', function(e){
    e.preventDefault(); var err = $('b-err'); err.hidden = true;
    api('anadir_bloqueo', { desde: $('b-desde').value, hasta: $('b-hasta').value, modalidad: $('b-mod').value, nota: $('b-nota').value })
      .then(function(r){ if (!r.ok) { err.textContent = errTxt(r); err.hidden = false; return; } e.target.reset(); toast('Días bloqueados.'); cargar(); });
  });
  document.addEventListener('click', function(e){
    var b = e.target.closest('[data-unbloq]'); if (!b) return;
    api('quitar_bloqueo', { id: b.getAttribute('data-unbloq') }).then(function(r){ if (!r.ok) return toast(errTxt(r)); toast('Desbloqueado.'); cargar(); });
  });

  /* ---------- historial ---------- */
  var HIST = null;
  function norm(t){ return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' '); }
  function cargarHistorial(){
    return api('historial').then(function(r){ if (!r.ok) return toast(errTxt(r)); HIST = r; pintarHistorial(); });
  }
  function resaltar(texto, q){
    var t = esc(texto); if (!q) return t;
    var i = norm(texto).indexOf(q); if (i < 0) return t;
    return esc(texto.slice(0, i)) + '<mark>' + esc(texto.slice(i, i + q.length)) + '</mark>' + esc(texto.slice(i + q.length));
  }
  function pintarHistorial(){
    if (!HIST) return;
    var q = norm($('h-q').value.trim()), est = $('h-est').value, cuando = $('h-cuando').value;
    var lista = HIST.citas.filter(function(c){
      if (est !== 'todas' && c.estado !== est) return false;
      if (cuando === 'pasadas' && c.fecha >= HIST.hoy) return false;
      if (cuando === 'proximas' && c.fecha < HIST.hoy) return false;
      if (!q) return true;
      return norm([c.nombre, c.apellidos, c.telefono, c.email].join(' ')).indexOf(q) >= 0 || norm(c.telefono).replace(/\D/g, '').indexOf(q.replace(/\D/g, '') || '§') >= 0;
    });
    var n = function(e){ return lista.filter(function(c){ return c.estado === e; }).length; };
    var conf = n('confirmada'), pe = n('pendiente'), an = n('cancelada');
    $('h-count').textContent = lista.length ? lista.length + (lista.length === 1 ? ' cita' : ' citas') + ' · ' + conf + ' confirmada' + (conf === 1 ? '' : 's') + ', ' + pe + ' pendiente' + (pe === 1 ? '' : 's') + ', ' + an + ' anulada' + (an === 1 ? '' : 's') : '';
    $('h-body').innerHTML = lista.length ? lista.map(function(c){
      var ok = c.estado !== 'cancelada', nombre = (c.nombre + ' ' + (c.apellidos || '')).trim();
      var EST = { pendiente: ['pe', 'Pendiente'], confirmada: ['', 'Confirmada'], cancelada: ['no', c.estado_previo === 'pendiente' ? 'Rechazada' : 'Anulada'] }[c.estado] || ['', c.estado];
      return '<tr class="' + (ok ? '' : 'anulada') + '"><td class="n">' + esc(fechaCorta(c.fecha)) + '</td><td class="n">' + esc(c.hora) + '</td><td>' + esc(MOD[c.modalidad]) + '</td>' +
        '<td class="p">' + resaltar(nombre, q) + (c.motivo ? '<span class="motivo">' + esc(c.motivo) + '</span>' : '') + (c.origen === 'panel' ? '<span class="muted" style="display:block">Añadida por ti</span>' : '') + '</td>' +
        '<td>' + resaltar(c.telefono || '', q) + (c.email ? '<br>' + resaltar(c.email, q) : '') + '</td>' +
        '<td><span class="est ' + EST[0] + '">' + EST[1] + '</span></td><td>' + (ok ? esc(textoCobro(c)) : '') + '</td></tr>';
    }).join('') : '<tr><td colspan="7" class="muted" style="text-align:center;padding:1.5rem">' + (HIST.citas.length ? 'No hay citas que coincidan con la búsqueda.' : 'Todavía no hay ninguna cita.') + '</td></tr>';
  }
  ['h-q', 'h-est', 'h-cuando'].forEach(function(id){ $(id).addEventListener('input', pintarHistorial); });
  document.querySelector('[data-tab="historial"]').addEventListener('click', cargarHistorial);

  /* ---------- contraseña ---------- */
  $('f-pass').addEventListener('submit', function(e){
    e.preventDefault(); var m = $('p-msg');
    api('cambiar_password', { actual: $('p-act').value, nueva: $('p-new').value }).then(function(r){
      m.hidden = false; m.className = 'msg ' + (r.ok ? 'ok' : 'err'); m.textContent = r.ok ? 'Contraseña cambiada.' : errTxt(r); if (r.ok) { ponerToken(r.token); e.target.reset(); }
    });
  });

  $('h-excel').addEventListener('click', function(){
    var b = this; b.disabled = true;
    fetch('admin.php', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ accion: 'exportar', _t: TOKEN }) })
      .then(function(r){ if (!r.ok) throw 0; return r.blob(); })
      .then(function(bl){ var a = document.createElement('a'); a.href = URL.createObjectURL(bl); a.download = 'citas-nutriendo-habitos-' + D.hoy + '.csv'; document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 1000); })
      .catch(function(){ toast('No se ha podido descargar. Vuelve a entrar y prueba otra vez.'); })
      .then(function(){ b.disabled = false; });
  });

  /* ---------- inicio ---------- */
  api('estado').then(function(r){
    if (!r.ok) { $('l-err').textContent = errTxt(r); $('l-err').hidden = false; mostrarLogin(true); return; }
    if (r.dentro) cargar(); else { ponerToken(''); mostrarLogin(r.configurado); }
    if (r.escribible === false) { $('l-err').textContent = ERR.escritura; $('l-err').hidden = false; }
  });
})();
</script>
</body>
</html>
