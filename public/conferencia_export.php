<?php
// Exporta a conferência (todas as etiquetas + status) em CSV/Excel.
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_scan_sync_all_reports();

$labels = store_labels_filtered('', '', 100000);
$filename = 'conferencia_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
fputcsv($out, ['Status', 'Rastreio', 'Cliente', 'Cidade/CEP', 'Produto(s)', 'Unidades', 'Plataforma', 'Relatorio', 'Enviado em', 'Devolvido em'], ';');
foreach ($labels as $l) {
    fputcsv($out, [
        store_label_status_label($l['status'] ?? 'pending'),
        ($l['tracking_code'] ?? '') ?: ($l['key'] ?? ''),
        $l['recipient'] ?? '',
        trim(($l['recipient_city'] ?? '') . ' ' . ($l['recipient_cep'] ?? '')),
        str_replace("\n", ' | ', store_label_products_text($l)),
        (int)($l['units_total'] ?? 1),
        platform_label($l['platform'] ?? ''),
        '#' . (int)($l['report_id'] ?? 0),
        !empty($l['sent_at']) ? app_date_from_iso($l['sent_at']) : '',
        !empty($l['returned_at']) ? app_date_from_iso($l['returned_at']) : '',
    ], ';');
}
fclose($out);
