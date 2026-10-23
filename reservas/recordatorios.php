<?php
/**
 * Recordatorios del día antes.
 * Lo llama cada día la «Tarea programada (cron)» del hosting con la dirección que aparece en
 * el panel → Ajustes → Recordatorios automáticos (incluye una clave aleatoria guardada en datos/).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$cfg = cargar_config();
$clave = (string)($_GET['clave'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', (string)$cfg['clave_cron']) || !hash_equals($cfg['clave_cron'], $clave)) {
    http_response_code(403);
    echo "Clave incorrecta.\n";
    exit;
}
if (empty($cfg['recordatorios'])) {
    echo "Recordatorios desactivados en el panel.\n";
    exit;
}
$r = enviar_recordatorios();
echo "Citas de {$r['fecha']}: {$r['enviados']} recordatorios enviados de {$r['pendientes']}.\n";
