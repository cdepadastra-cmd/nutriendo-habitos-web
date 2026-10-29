<?php
// El panel se ha movido a /admin/admin.php
$destino = '../admin/admin.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
header('Location: ' . $destino, true, 301);
header('X-Robots-Tag: noindex');
exit;
