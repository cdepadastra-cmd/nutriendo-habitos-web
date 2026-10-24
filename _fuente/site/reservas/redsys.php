<?php
/**
 * Aviso de Redsys (notificación online): el banco llama aquí cuando se paga una cita.
 * Solo se acepta si la firma es correcta (clave secreta del comercio, guardada en datos/).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
$cfg = cargar_config();
$p64 = (string)($_POST['Ds_MerchantParameters'] ?? '');
$firma = (string)($_POST['Ds_Signature'] ?? '');
if ($p64 === '' || $firma === '' || (string)$cfg['redsys']['clave'] === '') {
    http_response_code(400);
    exit("KO\n");
}
$params = json_decode((string)base64_decode(strtr($p64, '-_', '+/')), true);
$pedido = (string)($params['Ds_Order'] ?? $params['DS_ORDER'] ?? '');
$calculada = strtr(redsys_firma($p64, $pedido, (string)$cfg['redsys']['clave']), '+/', '-_');
if (!is_array($params) || $pedido === '' || !hash_equals($calculada, strtr($firma, '+/', '-_'))) {
    http_response_code(403);
    error_log('Reservas Nutriendo Hábitos: aviso de Redsys con firma incorrecta');
    exit("KO\n");
}
$respuesta = (int)($params['Ds_Response'] ?? 9999);
$autorizado = $respuesta >= 0 && $respuesta <= 99;
$importe = number_format(((int)($params['Ds_Amount'] ?? 0)) / 100, 2, '.', '');

$cita = con_cerrojo(function () use ($pedido, $autorizado, $importe, $params, $respuesta) {
    $rs = cargar_reservas();
    foreach ($rs as &$r) {
        if (($r['pago']['pedido'] ?? '') !== $pedido) {
            continue;
        }
        if (($r['pago']['estado'] ?? '') === 'pagado') {
            return null;   // aviso repetido: ya estaba pagada
        }
        if ($autorizado && $importe === ($r['pago']['importe'] ?? '')) {
            $r['pago']['estado'] = 'pagado';
            $r['pago']['fecha'] = date('c');
            $r['pago']['autorizacion'] = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($params['Ds_AuthorisationCode'] ?? '')), 0, 12);
        } else {
            $r['pago']['estado'] = 'fallido';
            $r['pago']['respuesta'] = (string)$respuesta;
        }
        guardar_datos('reservas', $rs);
        return $r;
    }
    return null;
});

if ($cita && ($cita['pago']['estado'] ?? '') === 'pagado') {
    enviar_correo(DESTINO, 'PAGO RECIBIDO · RESERVA WEB',
        "Se ha pagado online una cita (" . texto_importe($cita['pago']['importe']) . ").\n\n" . texto_cita($cita) . "\n"
        . "Paciente: {$cita['nombre']} {$cita['apellidos']} · {$cita['telefono']} · {$cita['email']}\n"
        . "Autorización: {$cita['pago']['autorizacion']}\n\nPanel: " . url_panel() . "\n", $cita['email']);
}
if ($cita) {
    sheets_cita($cita['id']);
}
echo "OK\n";
