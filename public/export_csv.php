<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();

function csv_header_download($filename) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
}
function csv_write_items($out, $items, $check = false) {
    $head = ['Produto', 'SKU', 'Total de pedidos', 'Total de unidades'];
    if ($check) { $head[] = 'Separado'; }
    fputcsv($out, $head, ';');
    foreach ($items as $item) {
        $row = [$item['product_name'] ?? '', $item['sku'] ?? '', $item['orders_count'] ?? 0, $item['quantity'] ?? 0];
        if ($check) { $row[] = ''; }
        fputcsv($out, $row, ';');
    }
}
function csv_write_orders($out, $orders, $includeReport = false) {
    $head = $includeReport
        ? ['Relatório', 'Data', 'Rastreio', 'SHP', 'Venda', 'Pack ID', 'Destinatário', 'Cidade destinatário', 'CEP destinatário', 'Endereço destinatário', 'Remetente', 'Cidade remetente', 'Endereço remetente', 'NF', 'Serviço', 'Peso', 'DACE', 'Chave DC-e', 'Produto', 'SKU', 'Quantidade', 'Valor item']
        : ['Rastreio', 'SHP', 'Venda', 'Pack ID', 'Destinatário', 'Cidade destinatário', 'CEP destinatário', 'Endereço destinatário', 'Remetente', 'Cidade remetente', 'Endereço remetente', 'NF', 'Serviço', 'Peso', 'DACE', 'Chave DC-e', 'Produto', 'SKU', 'Quantidade', 'Valor item'];
    fputcsv($out, $head, ';');
    foreach ($orders as $o) {
        $row = [$o['tracking_code'] ?? '', $o['shipment_id'] ?? '', $o['sale_id'] ?? '', $o['pack_id'] ?? '', $o['recipient'] ?? '', $o['recipient_city'] ?? '', $o['recipient_cep'] ?? '', $o['recipient_address'] ?? '', $o['sender_name'] ?? '', $o['sender_city'] ?? '', $o['sender_address'] ?? '', $o['nf'] ?? '', $o['service'] ?? '', $o['weight'] ?? '', $o['dace_number'] ?? '', $o['dce_key'] ?? '', $o['product_name'] ?? '', $o['sku'] ?? '', $o['quantity'] ?? 1, $o['item_value'] ?? ''];
        if ($includeReport) { array_unshift($row, '#' . (int)($o['_report_id'] ?? 0), !empty($o['_report_date']) ? app_date_from_iso($o['_report_date']) : ''); }
        fputcsv($out, $row, ';');
    }
}

$separation = isset($_GET['separation']);
$scope = $_GET['scope'] ?? '';
$date = $_GET['date'] ?? '';

if ($separation) {
    if ($scope === 'all') {
        $summary = store_general_summary();
        $filename = 'separacao_total_geral.csv';
        $label = 'Total geral acumulado';
    } else {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
        $summary = store_day_summary($date);
        $filename = 'separacao_' . $date . '.csv';
        $label = br_date_from_key($date);
    }
    csv_header_download($filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, ['LISTA DE SEPARAÇÃO'], ';');
    fputcsv($out, ['Período', $label], ';');
    fputcsv($out, ['PDFs somados', $summary['reports_count']], ';');
    fputcsv($out, ['Produtos diferentes', count($summary['items'] ?? [])], ';');
    fputcsv($out, ['Total de pedidos', $summary['orders']], ';');
    fputcsv($out, ['Total de unidades', $summary['units']], ';');
    fputcsv($out, [], ';');
    csv_write_items($out, $summary['items'] ?? [], true);
    fclose($out);
    exit;
}

if ($date !== '') {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
    $summary = store_day_summary($date);
    $reports = store_reports_by_date($date);
    csv_header_download('relatorio_do_dia_' . $date . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['RELATÓRIO DO DIA'], ';');
    fputcsv($out, ['Data', br_date_from_key($date)], ';');
    fputcsv($out, ['PDFs adicionados', $summary['reports_count']], ';');
    fputcsv($out, ['Etiquetas totais', $summary['labels']], ';');
    fputcsv($out, ['Pedidos totais', $summary['orders']], ';');
    fputcsv($out, ['Unidades totais', $summary['units']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['LISTA DE SEPARAÇÃO DO DIA'], ';');
    csv_write_items($out, $summary['items'], true);
    fputcsv($out, [], ';');
    fputcsv($out, ['PDFS QUE ENTRARAM NESSE DIA'], ';');
    fputcsv($out, ['Relatório', 'Arquivo', 'Plataforma', 'Etiquetas', 'Pedidos', 'Unidades', 'Data/hora'], ';');
    foreach ($reports as $r) { fputcsv($out, ['#' . (int)$r['id'], $r['original_filename'], platform_label($r['platform']), $r['total_labels'], $r['total_orders'], $r['total_units'], app_date_from_iso($r['created_at'])], ';'); }
    fputcsv($out, [], ';');
    fputcsv($out, ['DETALHAMENTO DO DIA'], ';');
    csv_write_orders($out, $summary['orders_list'], true);
    fclose($out);
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

csv_header_download('relatorio_pdf_' . $id . '.csv');
$out = fopen('php://output', 'w');
fputcsv($out, ['RELATÓRIO DO PDF'], ';');
fputcsv($out, ['Relatório', '#' . $id], ';');
fputcsv($out, ['Arquivo', $report['original_filename']], ';');
fputcsv($out, ['Plataforma', platform_label($report['platform'])], ';');
fputcsv($out, ['Processado em', app_date_from_iso($report['created_at'])], ';');
fputcsv($out, [], ';');
fputcsv($out, ['TOTAIS DESTE PDF'], ';');
fputcsv($out, ['Etiquetas', $report['total_labels']], ';');
fputcsv($out, ['Pedidos', $report['total_orders']], ';');
fputcsv($out, ['Unidades', $report['total_units']], ';');
fputcsv($out, [], ';');
fputcsv($out, ['LISTA DE SEPARAÇÃO DESTE PDF'], ';');
csv_write_items($out, $pdfItems, true);
fputcsv($out, [], ';');
fputcsv($out, ['TOTAL DO DIA ' . br_date_from_key($day)], ';');
fputcsv($out, ['PDFs no dia', $daySummary['reports_count']], ';');
fputcsv($out, ['Etiquetas no dia', $daySummary['labels']], ';');
fputcsv($out, ['Pedidos no dia', $daySummary['orders']], ';');
fputcsv($out, ['Unidades no dia', $daySummary['units']], ';');
fputcsv($out, [], ';');
fputcsv($out, ['PRODUTOS DO DIA - AGRUPADO'], ';');
csv_write_items($out, $daySummary['items'], true);
fputcsv($out, [], ';');
fputcsv($out, ['TOTAL GERAL ACUMULADO'], ';');
fputcsv($out, ['PDFs no painel', $generalSummary['reports_count']], ';');
fputcsv($out, ['Etiquetas totais', $generalSummary['labels']], ';');
fputcsv($out, ['Pedidos totais', $generalSummary['orders']], ';');
fputcsv($out, ['Unidades totais', $generalSummary['units']], ';');
fputcsv($out, [], ';');
fputcsv($out, ['DETALHAMENTO DESTE PDF'], ';');
csv_write_orders($out, $bundle['orders'], false);
fclose($out);
