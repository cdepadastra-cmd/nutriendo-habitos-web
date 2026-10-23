<?php
/**
 * Panel de reservas de Nutriendo Hábitos (solo para Andrea).
 * La primera vez que se abre pide crear una contraseña; se guarda cifrada en reservas/datos/.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('nh_panel');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => $seguro, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

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
    $dentro = !empty($_SESSION['dentro']);

    if ($accion === 'estado') {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        json_salida(['ok' => true, 'configurado' => $configurado, 'dentro' => $dentro, 'csrf' => $_SESSION['csrf']]);
    }

    // A partir de aquí, todas las acciones exigen el token de la sesión
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        json_salida(['ok' => false, 'error' => 'sesion'], 403);
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
        session_regenerate_id(true);
        $_SESSION['dentro'] = true;
        json_salida(['ok' => true]);
    }

    if ($accion === 'entrar') {
        if (!limitar('login', 6, 900)) {
            json_salida(['ok' => false, 'error' => 'limite'], 429);
        }
        if (!$configurado || !password_verify((string)($in['password'] ?? ''), $cfg['password'])) {
            json_salida(['ok' => false, 'error' => 'password'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['dentro'] = true;
        json_salida(['ok' => true]);
    }

    if (!$dentro) {
        json_salida(['ok' => false, 'error' => 'no_autorizado'], 401);
    }

    switch ($accion) {
        case 'salir':
            $_SESSION = [];
            session_destroy();
            json_salida(['ok' => true]);

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
                'direccion' => DIRECCION, 'telefono' => TELEFONO]);

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
                            json_salida(['ok' => false, 'error' => 'tramo', 'detalle' => "$m día $d"], 422);
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
            json_salida(['ok' => true]);

        case 'anadir_citas':
            // Varias citas a la vez (pasadas desde la agenda de papel)
            $lista = array_slice((array)($in['citas'] ?? []), 0, 200);
            $res = con_cerrojo(function () use ($lista) {
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
                        $out[] = 'ok';
                    }
                }
                guardar_datos('reservas', $rs);
                return $out;
            });
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
            json_salida(['ok' => true, 'avisado' => correo_confirmacion($cita)]);

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
            json_salida(['ok' => true, 'avisado' => $avisado]);

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
            json_salida(['ok' => true]);
    }
    json_salida(['ok' => false, 'error' => 'accion'], 400);
}

/* ======================= Descargar citas (Excel / CSV) ======================= */
if (isset($_GET['exportar'])) {
    if (empty($_SESSION['dentro'])) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Tienes que entrar en el panel para descargar las citas.';
        exit;
    }
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
    fputcsv($out, ['Fecha', 'Día', 'Hora', 'Consulta', 'Motivo', 'Nombre', 'Apellidos', 'Teléfono', 'Email', 'Estado', 'Origen', 'Reservada el', 'Confirmada el', 'Anulada el'], ';');
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
.card.manana{border-color:color-mix(in oklab,var(--primary) 35%,var(--border))}
.cita .rec{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center}
.ok-tag{font-size:.75rem;color:var(--secondary-fg);background:var(--secondary);border-radius:999px;padding:.15rem .55rem}
.btn.wa{background:#2E7D4F;border-color:#2E7D4F;color:#fff}
.btn.wa.hecho{background:transparent;color:#2E7D4F}
.ej summary{cursor:pointer;font-size:.88rem;color:var(--primary);margin-bottom:.4rem}
.ej pre{background:var(--muted);border-radius:.6rem;padding:.6rem .75rem;font-size:.8rem;white-space:pre-wrap;margin:0}
#imp-txt{width:100%;font:inherit;font-size:.9rem;border:1px solid var(--border);border-radius:.7rem;padding:.6rem .75rem;background:var(--card);resize:vertical;margin-top:.6rem}
.tbl-wrap{overflow-x:auto;margin:.8rem 0}
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
.cal-wrap{display:grid;gap:1.25rem;align-items:start}
@media(min-width:960px){.cal-wrap{grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)}.apl-card{position:sticky;top:1rem}}
.cal-head{display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem}
.cal-head h2::first-letter{text-transform:uppercase}
.cal-dow,.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.3rem}
.cal-dow button{border:0;background:var(--muted);border-radius:.5rem;padding:.35rem 0;font-size:.75rem;font-weight:600;letter-spacing:.06em;color:var(--muted-fg);cursor:pointer}
.cal-dow button:hover{background:var(--secondary);color:var(--secondary-fg)}
.cal-day{position:relative;min-height:5.4rem;border:1.5px solid var(--border);border-radius:.7rem;background:var(--card);padding:.3rem .35rem;text-align:left;cursor:pointer;display:flex;flex-direction:column;gap:.15rem;font-size:.68rem;line-height:1.25;color:var(--fg);overflow:hidden}
.cal-day .n{font-size:.85rem;font-weight:600;font-variant-numeric:tabular-nums}
.cal-day .t{display:block;border-radius:.3rem;padding:.05rem .25rem;font-variant-numeric:tabular-nums}
.cal-day .t b{font-weight:600;margin-right:.15rem}
.cal-day .t i{font-style:normal;display:block}
.cal-day .t.p{background:var(--secondary);color:var(--secondary-fg)}
.cal-day .t.o{background:#ECE8E3;color:#5E554F}
.cal-day .t.c{color:var(--muted-fg)}
.cal-day.esp{border-color:#C98A2B}
.cal-day.esp::after{content:"";position:absolute;top:.35rem;right:.35rem;width:.45rem;height:.45rem;border-radius:999px;background:#C98A2B}
.cal-day.blo{background:repeating-linear-gradient(135deg,#F3E4E1 0 6px,#fff 6px 12px)}
.cal-day.pas{opacity:.4;cursor:default}
.cal-day.fuera{opacity:.55}
.cal-day.sel{border-color:var(--primary);box-shadow:0 0 0 2px var(--primary) inset;background:color-mix(in oklab,var(--secondary) 50%,var(--card))}
.cal-day:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.cal-leg{display:flex;flex-wrap:wrap;gap:.4rem 1rem;margin-top:.8rem;font-size:.78rem;color:var(--muted-fg)}
.cal-leg span{display:flex;align-items:center;gap:.35rem}
.lg{display:inline-block;width:.8rem;height:.8rem;border-radius:.25rem}
.lg.p{background:var(--secondary)}.lg.o{background:#ECE8E3}.lg.x{border:2px solid #C98A2B;border-radius:999px}.lg.b{background:repeating-linear-gradient(135deg,#F3E4E1 0 3px,#fff 3px 6px);border:1px solid #E8C9C3}
.cal-sel{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.8rem}
.apl-card{display:grid;gap:.8rem}
@media(max-width:640px){.cal-day{min-height:3.6rem;padding:.25rem}.cal-day .t{padding:0 .2rem;font-size:.62rem}.cal-day .t i{display:none}.cal-dow,.cal-grid{gap:.2rem}}
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
            <p class="muted" style="margin:0 0 .6rem">Pega aquí las citas que tienes apuntadas (una por línea) y se crean todas de golpe. Puedes copiar el texto de la foto de tu agenda con el móvil: <b>iPhone</b> → abre la foto en Fotos, mantén pulsado el texto y «Copiar»; <b>Android</b> → Google Fotos o Google Lens → «Texto» → «Copiar».</p>
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

      <!-- Calendario del mes: horario día a día -->
      <h2 style="font-size:1.3rem;margin:1.6rem 0 .3rem">Calendario: horario de cada día</h2>
      <p class="muted" style="margin-top:0">Aquí ves qué horas tiene cada día. Los días sin marca siguen tu <b>horario habitual</b> (más abajo). Selecciona uno o varios días, pon las horas y pulsa «Poner estas horas». Así puedes cambiar un lunes concreto, cerrar un día o abrir un sábado suelto.</p>
      <div class="cal-wrap">
        <div class="card cal-card">
          <div class="cal-head">
            <button class="btn sec sm" type="button" id="m-prev" aria-label="Mes anterior">‹</button>
            <h2 id="m-titulo" style="font-size:1.25rem;text-align:center;flex:1"></h2>
            <button class="btn sec sm" type="button" id="m-next" aria-label="Mes siguiente">›</button>
          </div>
          <div class="cal-dow" id="m-dow"></div>
          <div class="cal-grid" id="m-grid"></div>
          <div class="cal-leg"><span><i class="lg p"></i>Presencial</span><span><i class="lg o"></i>Online</span><span><i class="lg x"></i>Cambiado a mano</span><span><i class="lg b"></i>Bloqueado</span></div>
          <div class="cal-sel">
            <button class="btn sec sm" type="button" id="m-todo">Seleccionar todo el mes</button>
            <button class="btn sec sm" type="button" id="m-nada">Quitar selección</button>
            <span class="muted">Pulsa L, M, X… para elegir todos esos días del mes.</span>
          </div>
        </div>
        <div class="card apl-card">
          <h2 style="font-size:1.2rem">Días seleccionados: <span id="m-nsel">0</span></h2>
          <p class="muted" id="m-sel-txt" style="margin:0">Pulsa en los días del calendario para elegirlos.</p>
          <label for="m-mod">Consulta<select id="m-mod"><option value="ambas">Presencial y online (las mismas horas)</option><option value="presencial">Solo presencial</option><option value="online">Solo online</option></select></label>
          <div class="row">
            <label for="m-a1">Mañana desde<input id="m-a1" type="time" step="300" value="09:30"></label>
            <label for="m-b1">hasta<input id="m-b1" type="time" step="300" value="13:15"></label>
          </div>
          <div class="row">
            <label for="m-a2">Tarde desde<input id="m-a2" type="time" step="300"></label>
            <label for="m-b2">hasta<input id="m-b2" type="time" step="300"></label>
          </div>
          <p class="muted" style="margin:-.3rem 0 0;font-size:.8rem">Deja la tarde vacía si solo es por la mañana (o al revés).</p>
          <p class="msg err" id="m-err" hidden></p>
          <p class="msg" id="m-aviso" hidden></p>
          <div style="display:grid;gap:.5rem">
            <button class="btn" type="button" id="m-aplicar">Poner estas horas</button>
            <button class="btn danger" type="button" id="m-cerrar">Cerrar estos días (sin citas)</button>
            <button class="btn sec" type="button" id="m-reset">Volver al horario habitual</button>
          </div>
        </div>
      </div>

      <h2 style="font-size:1.3rem;margin:2rem 0 .3rem">Horario habitual</h2>
      <p class="muted" style="margin-top:0">Lo normal de cada semana. Se usa en todos los días que no hayas cambiado en el calendario de arriba. La web divide las horas en huecos de <span class="dur2">45</span> minutos. Una cita presencial también ocupa ese hueco en online, y al revés. Si prefieres marcarlo todo en el calendario, puedes dejar esto vacío.</p>
      <div class="grid2">
        <div class="card"><h2 style="font-size:1.3rem;margin-bottom:.5rem">Presencial en Valladolid</h2><div class="week" id="w-presencial"></div></div>
        <div class="card"><h2 style="font-size:1.3rem;margin-bottom:.5rem">Online</h2><div class="week" id="w-online"></div></div>
      </div>
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
          <a class="btn" href="admin.php?exportar=1" id="h-excel" style="flex:0 0 auto">Descargar en Excel</a>
        </div>
        <p class="muted" id="h-count" style="margin:0"></p>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>Fecha</th><th>Hora</th><th>Consulta</th><th>Paciente</th><th>Contacto</th><th>Estado</th></tr></thead>
          <tbody id="h-body"></tbody>
        </table></div>
        <p class="muted" style="margin:0">El archivo de Excel incluye todas las citas, también las anuladas. Guárdalo de vez en cuando como copia de seguridad.</p>
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
  var CSRF = '', D = null, H = null;
  var DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
  var MOD = { presencial: 'Presencial', online: 'Online' };
  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function api(accion, datos){
    var body = Object.assign({ accion: accion }, datos || {});
    return fetch('admin.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body) })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'red' }; }); });
  }
  function toast(t){ var e = $('toast'); e.textContent = t; e.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(function(){ e.hidden = true; }, 2600); }
  function fechaLarga(f){
    var d = new Date(f + 'T12:00:00');
    return d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }
  function fechaCorta(f){ var d = new Date(f + 'T12:00:00'); return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }); }
  var ERR = { password: 'Contraseña incorrecta.', limite: 'Demasiados intentos. Espera 15 minutos y vuelve a probar.', corta: 'La contraseña debe tener al menos 8 caracteres.', ocupado: 'Ese hueco ya tiene una cita.', datos: 'Revisa los datos: falta algo o no es válido.', red: 'No hay conexión con el servidor.', sesion: 'La sesión ha caducado. Recarga la página.', no_autorizado: 'La sesión ha caducado. Recarga la página.', tramo: 'Hay un tramo con la hora de fin antes que la de inicio.' };
  function errTxt(r){ return ERR[r && r.error] || 'No se ha podido guardar. Prueba otra vez.'; }

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
      $('l-pass').value = ''; $('l-pass2').value = ''; cargar();
    });
  });
  $('b-salir').addEventListener('click', function(){ api('salir').then(function(){ location.reload(); }); });

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
      if (!r.ok) { if (r.error === 'no_autorizado') return mostrarLogin(true); toast(errTxt(r)); return; }
      D = r; H = JSON.parse(JSON.stringify(r.horario));
      $('v-login').hidden = true; $('v-panel').hidden = false;
      $('dur').textContent = r.duracion; document.querySelectorAll('.dur2').forEach(function(e){ e.textContent = r.duracion; });
      ['c-motivo', 'imp-motivo'].forEach(function(id){ if ($(id).options.length < 2) (r.motivos || []).forEach(function(m){ var o = document.createElement('option'); o.value = o.textContent = m; $(id).appendChild(o); }); });
      $('r-on').checked = !!r.recordatorios; $('r-url').value = r.url_cron || '';
      pintarManana();
      pintarCitas(); pintarHorario(); pintarBloqueos();
      $('a-ant').value = r.antelacion_horas; $('a-vista').value = r.dias_vista;
      $('h-abierto').value = r.abierto_hasta || ''; $('h-abierto').min = r.hoy; pintarLimite(); pintarEspeciales();
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
        acts + '</div>';
    });
    return html ? html + '</div>' : '';
  }
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
      else if ((m = l.match(/(^|\D)(\d{1,2})\s*(?:de\s+)?(ene|feb|mar|abr|may|jun|jul|ago|sep|set|oct|nov|dic)[a-zé]*\.?(?:\s*(?:de\s+)?(\d{4}))?/i))) { f = fechaDe(+m[2], MESES[m[3].toLowerCase()], m[4] ? +m[4] : 0); if (f) l = l.replace(m[0], m[1] + ' '); else malDia = true; }
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
      var c = { linea: n + 1, original: linea.trim(), fecha: f, hora: h, modalidad: mod, nombre: partes[0] || '', apellidos: partes.slice(1).join(' '), telefono: tel, email: email, motivo: $('imp-motivo').value };
      c.error = malDia ? 'Ese día no existe' : !h ? 'Falta la hora' : !f ? 'Falta el día' : !c.nombre ? 'Falta el nombre' : f < D.hoy ? 'Es un día pasado' : '';
      if (!c.error && D.citas.some(function(x){ return x.fecha === f && x.hora === h && x.estado !== 'cancelada'; })) c.error = 'Ya hay una cita a esa hora';
      if (!c.error && out.some(function(x){ return !x.error && x.fecha === f && x.hora === h; })) c.error = 'Repetida en la lista';
      out.push(c);
    });
    return out;
  }
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

  /* ---------- horario ---------- */
  function pintarHorario(){
    ['presencial', 'online'].forEach(function(m){
      var cont = $('w-' + m), html = '';
      for (var d = 1; d <= 7; d++) {
        var tramos = H[m][d] || [];
        html += '<div class="wd"><b>' + DIAS[d] + '</b><div class="tramos">' +
          tramos.map(function(t, i){
            return '<div class="tramo"><input type="time" step="300" aria-label="' + DIAS[d] + ' desde" value="' + t[0] + '" data-m="' + m + '" data-d="' + d + '" data-i="' + i + '" data-k="0"> a ' +
              '<input type="time" step="300" aria-label="' + DIAS[d] + ' hasta" value="' + t[1] + '" data-m="' + m + '" data-d="' + d + '" data-i="' + i + '" data-k="1">' +
              '<button class="x" type="button" title="Quitar tramo" aria-label="Quitar tramo" data-del="' + m + '|' + d + '|' + i + '">×</button></div>';
          }).join('') +
          (tramos.length ? '' : '<span class="muted" style="padding-top:.35rem">Cerrado</span>') +
          '<button class="add" type="button" data-add="' + m + '|' + d + '">+ Añadir horas</button></div></div>';
      }
      cont.innerHTML = html;
    });
  }
  document.addEventListener('input', function(e){
    var t = e.target; if (!t.dataset || !t.dataset.m || t.type !== 'time') return;
    H[t.dataset.m][t.dataset.d][+t.dataset.i][+t.dataset.k] = t.value; $('h-msg').textContent = 'Cambios sin guardar';
  });
  document.addEventListener('click', function(e){
    var a = e.target.closest('[data-add]'), x = e.target.closest('[data-del]');
    if (a) { var p = a.dataset.add.split('|'); var l = H[p[0]][p[1]] = H[p[0]][p[1]] || []; var last = l[l.length - 1];
      l.push(last ? [last[1] < '16:00' ? '16:00' : last[1], last[1] < '16:00' ? '20:00' : '21:00'] : ['09:30', '14:00']); pintarHorario(); $('h-msg').textContent = 'Cambios sin guardar'; }
    if (x) { var q = x.dataset.del.split('|'); H[q[0]][q[1]].splice(+q[2], 1); pintarHorario(); $('h-msg').textContent = 'Cambios sin guardar'; }
  });
  function guardarHorario(btn, msg){
    btn.disabled = true;
    return api('guardar_horario', { horario: H, antelacion_horas: +$('a-ant').value, dias_vista: +$('a-vista').value, abierto_hasta: $('h-abierto').value }).then(function(r){
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

  /* ---------- calendario: horario de cada día ---------- */
  var MES = null, SEL = {};
  function iso(d){ return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function bloqueado(f, m){ return (D.bloqueos || []).some(function(b){ return f >= b.desde && f <= b.hasta && (b.modalidad === 'todas' || b.modalidad === m); }); }
  function especial(f, m){ return (D.especiales || []).find(function(e){ return e.fecha === f && e.modalidad === m; }); }
  function tramosDe(f, m){
    var e = especial(f, m); if (e) return e.tramos;
    var dow = new Date(f + 'T12:00:00').getDay() || 7;
    return (D.horario[m] && D.horario[m][dow]) || [];
  }
  function txtTramos(t){ return t.map(function(x){ return x[0].replace(/^0/, '') + '–' + x[1].replace(/^0/, ''); }).join(' · '); }
  function pintarEspeciales(){
    if (!MES) { var h = new Date(D.hoy + 'T12:00:00'); MES = new Date(h.getFullYear(), h.getMonth(), 1); }
    $('m-titulo').textContent = MES.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
    var hoyMes = D.hoy.slice(0, 7);
    $('m-prev').disabled = iso(MES).slice(0, 7) <= hoyMes;
    $('m-dow').innerHTML = ['L','M','X','J','V','S','D'].map(function(l, i){ return '<button type="button" data-dow="' + (i + 1) + '" title="Seleccionar todos los ' + DIAS[i + 1].toLowerCase() + ' del mes">' + l + '</button>'; }).join('');
    var y = MES.getFullYear(), m = MES.getMonth(), n = new Date(y, m + 1, 0).getDate(), off = (new Date(y, m, 1).getDay() + 6) % 7, html = '';
    var lim = D.limite || '';
    for (var i = 0; i < off; i++) html += '<span></span>';
    for (var d = 1; d <= n; d++) {
      var f = iso(new Date(y, m, d)), pas = f < D.hoy, lineas = '', esp = false, blo = true;
      ['presencial', 'online'].forEach(function(mod){
        if (especial(f, mod)) esp = true;
        if (bloqueado(f, mod)) { lineas += '<span class="t c">' + (mod === 'presencial' ? 'P' : 'O') + ' bloqueado</span>'; return; }
        blo = false;
        var t = tramosDe(f, mod);
        lineas += t.length ? '<span class="t ' + (mod === 'presencial' ? 'p' : 'o') + '"><b>' + (mod === 'presencial' ? 'P' : 'O') + '</b>' + t.map(function(x){ return '<i>' + txtTramos([x]) + '</i>'; }).join('') + '</span>' : '';
      });
      if (!lineas) lineas = '<span class="t c">Cerrado</span>';
      var cls = 'cal-day' + (esp ? ' esp' : '') + (blo ? ' blo' : '') + (pas ? ' pas' : '') + (SEL[f] ? ' sel' : '') + (lim && f > lim ? ' fuera' : '');
      html += '<button type="button" class="' + cls + '" data-dia="' + f + '"' + (pas ? ' disabled' : '') + ' aria-pressed="' + !!SEL[f] + '" aria-label="' + esc(fechaLarga(f)) + '"><span class="n">' + d + '</span>' + lineas + '</button>';
    }
    $('m-grid').innerHTML = html;
    pintarSel();
  }
  function pintarSel(){
    var fs = Object.keys(SEL).sort(); $('m-nsel').textContent = fs.length;
    if (fs.length === 1) {
      var f1 = fs[0], det = ['presencial', 'online'].map(function(m){ var t = bloqueado(f1, m) ? null : tramosDe(f1, m); return (m === 'presencial' ? 'Presencial: ' : 'Online: ') + (t === null ? 'bloqueado' : t.length ? txtTramos(t) : 'cerrado'); }).join(' · ');
      $('m-sel-txt').textContent = fechaLarga(f1) + ' — ' + det;
    } else $('m-sel-txt').textContent = fs.length ? (fs.length <= 4 ? fs.map(function(f){ return fechaCorta(f); }).join(', ') : fechaCorta(fs[0]) + ' … ' + fechaCorta(fs[fs.length - 1])) : 'Pulsa en los días del calendario para elegirlos.';
    var lim = D.limite || '', fuera = fs.filter(function(f){ return lim && f > lim; });
    var av = $('m-aviso');
    if (fuera.length) { av.hidden = false; av.className = 'msg err'; av.innerHTML = 'Hay días después de «Reservas abiertas hasta» (' + esc(fechaCorta(lim)) + '): los pacientes aún no los verán. <button class="btn sec sm" type="button" id="m-ampliar" style="margin-top:.4rem">Abrir reservas hasta el ' + esc(fechaCorta(fs[fs.length - 1])) + '</button>'; }
    else av.hidden = true;
    ['m-aplicar', 'm-cerrar', 'm-reset'].forEach(function(id){ $(id).disabled = !fs.length; });
  }
  $('m-grid').addEventListener('click', function(e){
    var b = e.target.closest('[data-dia]'); if (!b || b.disabled) return;
    var f = b.dataset.dia; if (SEL[f]) delete SEL[f]; else SEL[f] = true;
    b.classList.toggle('sel', !!SEL[f]); b.setAttribute('aria-pressed', !!SEL[f]); pintarSel();
  });
  $('m-dow').addEventListener('click', function(e){
    var b = e.target.closest('[data-dow]'); if (!b) return;
    var dow = +b.dataset.dow, botones = [].slice.call(document.querySelectorAll('#m-grid [data-dia]:not([disabled])')).filter(function(x){ return (new Date(x.dataset.dia + 'T12:00:00').getDay() || 7) === dow; });
    var todos = botones.every(function(x){ return SEL[x.dataset.dia]; });
    botones.forEach(function(x){ if (todos) delete SEL[x.dataset.dia]; else SEL[x.dataset.dia] = true; });
    pintarEspeciales();
  });
  $('m-todo').addEventListener('click', function(){ document.querySelectorAll('#m-grid [data-dia]:not([disabled])').forEach(function(x){ SEL[x.dataset.dia] = true; }); pintarEspeciales(); });
  $('m-nada').addEventListener('click', function(){ SEL = {}; pintarEspeciales(); });
  $('m-prev').addEventListener('click', function(){ MES = new Date(MES.getFullYear(), MES.getMonth() - 1, 1); pintarEspeciales(); });
  $('m-next').addEventListener('click', function(){ MES = new Date(MES.getFullYear(), MES.getMonth() + 1, 1); pintarEspeciales(); });
  function aplicar(accion, tramos, texto){
    var err = $('m-err'); err.hidden = true;
    var fechas = Object.keys(SEL).sort(); if (!fechas.length) return;
    api(accion, { fechas: fechas, modalidad: $('m-mod').value, tramos: tramos }).then(function(r){
      if (!r.ok) { err.textContent = r.error === 'tramo' ? 'Revisa las horas: la hora de fin tiene que ser después de la de inicio.' : errTxt(r); err.hidden = false; return; }
      SEL = {}; toast(texto[r.dias === 1 ? 1 : 0].replace('{n}', r.dias)); cargar();
    });
  }
  $('m-aplicar').addEventListener('click', function(){
    var t = [[$('m-a1').value, $('m-b1').value], [$('m-a2').value, $('m-b2').value]].filter(function(x){ return x[0] || x[1]; });
    if (!t.length) { $('m-err').textContent = 'Pon al menos un tramo de horas, o usa «Cerrar estos días».'; $('m-err').hidden = false; return; }
    aplicar('aplicar_dias', t, ['Horario puesto en {n} días.', 'Horario puesto en 1 día.']);
  });
  $('m-cerrar').addEventListener('click', function(){ aplicar('aplicar_dias', [], ['{n} días cerrados: no se podrá reservar.', 'Día cerrado: no se podrá reservar.']); });
  $('m-reset').addEventListener('click', function(){ aplicar('restablecer_dias', [], ['{n} días vuelven al horario habitual.', 'El día vuelve al horario habitual.']); });
  document.addEventListener('click', function(e){
    if (!e.target.closest('#m-ampliar')) return;
    var fs = Object.keys(SEL).sort(); $('h-abierto').value = fs[fs.length - 1];
    guardarHorario($('b-guardar-h'), $('h-msg'));
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
        '<td><span class="est ' + EST[0] + '">' + EST[1] + '</span></td></tr>';
    }).join('') : '<tr><td colspan="6" class="muted" style="text-align:center;padding:1.5rem">' + (HIST.citas.length ? 'No hay citas que coincidan con la búsqueda.' : 'Todavía no hay ninguna cita.') + '</td></tr>';
  }
  ['h-q', 'h-est', 'h-cuando'].forEach(function(id){ $(id).addEventListener('input', pintarHistorial); });
  document.querySelector('[data-tab="historial"]').addEventListener('click', cargarHistorial);

  /* ---------- contraseña ---------- */
  $('f-pass').addEventListener('submit', function(e){
    e.preventDefault(); var m = $('p-msg');
    api('cambiar_password', { actual: $('p-act').value, nueva: $('p-new').value }).then(function(r){
      m.hidden = false; m.className = 'msg ' + (r.ok ? 'ok' : 'err'); m.textContent = r.ok ? 'Contraseña cambiada.' : errTxt(r); if (r.ok) e.target.reset();
    });
  });

  /* ---------- inicio ---------- */
  api('estado').then(function(r){
    if (!r.ok) { mostrarLogin(true); return; }
    CSRF = r.csrf;
    if (r.dentro) cargar(); else mostrarLogin(r.configurado);
  });
})();
</script>
</body>
</html>
