<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();

function line_txt($char = '-', $len = 62) { return str_repeat($char, $len) . "\n"; }
function clean_txt($value) {
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    return $value === '' ? '-' : $value;
}
function write_product_lines($items) {
    $n = 1;
    foreach ($items as $item) {
        $sku = trim((string)($item['sku'] ?? ''));
        echo str_pad((string)$n . '.', 4, ' ', STR_PAD_RIGHT) . clean_txt($item['product_name'] ?? 'Produto não identificado') . "\n";
        echo "     Total de pedidos: " . (int)($item['orders_count'] ?? 0)
            . " | Total de unidades: " . (int)($item['quantity'] ?? 0)
            . ($sku !== '' ? " | SKU: " . $sku : '') . "\n\n";
        $n++;
    }
    if (!$items) { echo "Nenhum produto identificado.\n\n"; }
}
function write_separation_lines($items) {
    $n = 1;
    foreach ($items as $item) {
        $sku = trim((string)($item['sku'] ?? ''));
        echo $n . ") " . clean_txt($item['product_name'] ?? 'Produto não identificado') . "\n";
        echo "   TOTAL: " . (int)($item['orders_count'] ?? 0) . " pedidos | " . (int)($item['quantity'] ?? 0) . " unidades" . ($sku !== '' ? " | SKU: " . $sku : '') . "\n";
        echo "   Separado: [  ]\n\n";
        $n++;
    }
    if (!$items) { echo "Nenhum produto identificado.\n"; }
}
function write_order_lines($orders, $limit = 300, $includeReport = false) {
    $i = 0;
    foreach ($orders as $o) {
        $i++;
        if ($i > $limit) { echo "... mostrando apenas os primeiros {$limit} itens. Use CSV para ver tudo.\n"; break; }
        $prefix = $includeReport ? ('Relatório #' . (int)($o['_report_id'] ?? 0) . ' | ') : '';
        echo $i . ') ' . $prefix . 'Qtd ' . (int)($o['quantity'] ?? 1) . ' - ' . clean_txt($o['product_name'] ?? 'Produto não identificado') . "\n";
        echo "   Rastreio: " . clean_txt($o['tracking_code'] ?? '') . " | SHP: " . clean_txt($o['shipment_id'] ?? '') . " | Venda: " . clean_txt($o['sale_id'] ?? '') . " | Pack ID: " . clean_txt($o['pack_id'] ?? '') . "\n";
        echo "   Destinatário: " . clean_txt($o['recipient'] ?? '') . " | Cidade/Bairro: " . clean_txt($o['recipient_city'] ?? '') . " | CEP: " . clean_txt($o['recipient_cep'] ?? '') . "\n";
        if (!empty($o['recipient_address'])) { echo "   Endereço destinatário: " . clean_txt($o['recipient_address']) . "\n"; }
        if (!empty($o['sender_name']) || !empty($o['sender_address'])) { echo "   Remetente: " . clean_txt($o['sender_name'] ?? '') . " | " . clean_txt($o['sender_address'] ?? '') . "\n"; }
        if (!empty($o['nf']) || !empty($o['service']) || !empty($o['weight']) || !empty($o['dace_number']) || !empty($o['item_value'])) { echo "   NF: " . clean_txt($o['nf'] ?? '') . " | Serviço: " . clean_txt($o['service'] ?? '') . " | Peso: " . clean_txt($o['weight'] ?? '') . " | DACE: " . clean_txt($o['dace_number'] ?? '') . " | Valor item: " . clean_txt($o['item_value'] ?? '') . "\n"; }
        echo "\n";
    }
    if (!$orders) { echo "Nenhum detalhe encontrado.\n"; }
}

$separation = isset($_GET['separation']);
$scope = $_GET['scope'] ?? '';
$date = $_GET['date'] ?? '';

if ($separation) {
    if ($scope === 'all') {
        $summary = store_general_summary();
        $label = 'TOTAL GERAL ACUMULADO';
        $filename = 'separacao_total_geral.txt';
    } else {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
        $summary = store_day_summary($date);
        $label = 'DIA ' . br_date_from_key($date);
        $filename = 'separacao_' . $date . '.txt';
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "LISTA DE SEPARAÇÃO DE PRODUTOS\n";
    echo line_txt('=');
    echo "Período: " . $label . "\n";
    echo "PDFs somados: " . (int)$summary['reports_count'] . "\n";
    echo "Produtos diferentes: " . count($summary['items'] ?? []) . "\n";
    echo "Total de pedidos: " . (int)$summary['orders'] . "\n";
    echo "Total de unidades: " . (int)$summary['units'] . "\n";
    echo line_txt();
    write_separation_lines($summary['items'] ?? []);
    exit;
}

if ($date !== '') {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
    $summary = store_day_summary($date);
    $reports = store_reports_by_date($date);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_do_dia_' . $date . '.txt"');
    echo "RELATÓRIO DO DIA - CONFERIDOR DE ETIQUETAS\n";
    echo line_txt('=');
    echo "Data: " . br_date_from_key($date) . "\n";
    echo "PDFs adicionados: " . (int)$summary['reports_count'] . "\n";
    echo "Etiquetas totais: " . (int)$summary['labels'] . "\n";
    echo "Pedidos totais: " . (int)$summary['orders'] . "\n";
    echo "Unidades totais: " . (int)$summary['units'] . "\n";
    echo line_txt();
    echo "LISTA DE SEPARAÇÃO DO DIA\n";
    echo line_txt();
    write_separation_lines($summary['items']);
    echo line_txt();
    echo "PDFS QUE ENTRARAM NESSE DIA\n";
    echo line_txt();
    foreach ($reports as $r) {
        echo '#' . (int)$r['id'] . ' | ' . clean_txt($r['original_filename']) . ' | ' . platform_label($r['platform']) . ' | Unidades: ' . (int)$r['total_units'] . ' | ' . app_date_from_iso($r['created_at']) . "\n";
    }
    if (!$reports) { echo "Nenhum PDF nesta data.\n"; }
    echo "\n" . line_txt();
    echo "DETALHAMENTO DO DIA\n";
    echo line_txt();
    write_order_lines($summary['orders_list'], 500, true);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$bundle = store_get_report_bundle($id);
if (!$bundle) { http_response_code(404); die('Relatório não encontrado.'); }
$report = $bundle['report'];
$day = store_report_day($report);
$daySummary = store_day_summary($day);
$generalSummary = store_general_summary();
$pdfItems = store_separation_from_orders($bundle['orders'] ?? []);

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="relatorio_pdf_' . $id . '.txt"');

echo "RELATÓRIO DO PDF - CONFERIDOR DE ETIQUETAS\n";
echo line_txt('=');
echo "Relatório: #" . (int)$id . "\n";
echo "Arquivo: " . clean_txt($report['original_filename']) . "\n";
echo "Plataforma: " . platform_label($report['platform']) . "\n";
echo "Processado em: " . app_date_from_iso($report['created_at']) . "\n";
echo line_txt();
echo "TOTAIS DESTE PDF\n";
echo "Etiquetas: " . (int)$report['total_labels'] . "\n";
echo "Pedidos: " . (int)$report['total_orders'] . "\n";
echo "Unidades: " . (int)$report['total_units'] . "\n";
echo line_txt();
echo "LISTA DE SEPARAÇÃO DESTE PDF\n";
echo line_txt();
write_separation_lines($pdfItems);
echo line_txt();
echo "TOTAL DO DIA " . br_date_from_key($day) . "\n";
echo "PDFs no dia: " . (int)$daySummary['reports_count'] . "\n";
echo "Etiquetas no dia: " . (int)$daySummary['labels'] . "\n";
echo "Pedidos no dia: " . (int)$daySummary['orders'] . "\n";
echo "Unidades no dia: " . (int)$daySummary['units'] . "\n";
echo line_txt();
echo "PRODUTOS ACUMULADOS DO DIA\n";
echo line_txt();
write_product_lines($daySummary['items']);
echo line_txt();
echo "TOTAL GERAL ACUMULADO NO PAINEL\n";
echo "PDFs no painel: " . (int)$generalSummary['reports_count'] . "\n";
echo "Etiquetas totais: " . (int)$generalSummary['labels'] . "\n";
echo "Pedidos totais: " . (int)$generalSummary['orders'] . "\n";
echo "Unidades totais: " . (int)$generalSummary['units'] . "\n";
echo line_txt();
echo "DETALHAMENTO DESTE PDF\n";
echo line_txt();
write_order_lines($bundle['orders'], 500, false);
