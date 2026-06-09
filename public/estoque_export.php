<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/stock.php';
$user = require_login();
store_scan_sync_all_reports();
$rows = stock_balance();
$filename = 'estoque_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Produto', 'SKU', 'Entrou', 'Reservado', 'Enviado', 'Saldo', 'Disponivel'], ';');
foreach ($rows as $r) {
    fputcsv($out, [$r['product_name'], $r['sku'], (int)$r['in'], (int)$r['reserved'], (int)$r['sent'], (int)$r['balance'], (int)$r['available']], ';');
}
fclose($out);
