<?php
// Módulo de Estoque. Reaproveita a bipagem: pendentes = reservado, enviadas = baixa.
// O estoque só rastreia ENTRADAS (com dia/data/hora); saldo e reserva são derivados.
require_once __DIR__ . '/store.php';

function stock_file() { return data_file('stock_entries.json'); }
function stock_default_deposit() { return 'Depósito Principal'; }

function stock_entries_all() {
    store_init();
    $e = json_read_file(stock_file(), []);
    return is_array($e) ? $e : [];
}
function stock_save_entries($entries) { json_write_file(stock_file(), array_values($entries)); }

// Zera TODO o estoque (apaga as entradas). Saldo volta a zero.
function stock_clear() { stock_save_entries([]); return true; }

// Registra uma entrada de estoque com dia/data/hora.
function stock_add_entry($productName, $sku, $qty, $type = 'entrada', $note = '', $userId = 0, $deposit = '') {
    $qty = (int)$qty;
    if ($qty === 0) { return false; }
    $name = store_product_display_name($productName);
    $key = store_product_key($name, $sku);
    $entries = stock_entries_all();
    $entries[] = [
        'id' => bin2hex(random_bytes(6)),
        'deposit' => ($deposit !== '') ? $deposit : stock_default_deposit(),
        'product_key' => $key,
        'product_name' => $name,
        'sku' => trim((string)$sku),
        'qty' => $qty,
        'type' => in_array($type, ['inicial', 'entrada', 'ajuste'], true) ? $type : 'entrada',
        'datetime' => date('c'),
        'user_id' => (int)$userId,
        'note' => trim((string)$note),
    ];
    stock_save_entries($entries);
    return true;
}

// Estoque inicial em lote: cada linha "Produto ; quantidade" (aceita ; , ou tab).
function stock_initial_bulk($text, $userId = 0, $deposit = '') {
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $count = 0;
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') { continue; }
        if (preg_match('/^(.*\S)\s*[;,\t]\s*(-?\d+)\s*$/u', $line, $m)) {
            $name = trim($m[1]);
            $qty = (int)$m[2];
            if ($name !== '' && $qty !== 0) {
                stock_add_entry($name, '', $qty, 'inicial', 'Estoque inicial (lote)', $userId, $deposit);
                $count++;
            }
        }
    }
    return $count;
}

function stock_entries_sum_by_product() {
    $map = [];
    foreach (stock_entries_all() as $e) {
        $k = (string)($e['product_key'] ?? '');
        if ($k === '') { continue; }
        if (!isset($map[$k])) { $map[$k] = ['product_name' => $e['product_name'] ?? '', 'sku' => $e['sku'] ?? '', 'in' => 0]; }
        $map[$k]['in'] += (int)($e['qty'] ?? 0);
        if (($e['sku'] ?? '') !== '' && ($map[$k]['sku'] ?? '') === '') { $map[$k]['sku'] = $e['sku']; }
    }
    return $map;
}

// Saldo por produto: entrou, reservado (pendentes), enviado (baixa), saldo físico, disponível.
function stock_balance($q = '') {
    $entries = stock_entries_sum_by_product();
    $byKey = [];
    foreach (store_products_control(100000) as $c) {
        $byKey[store_product_key($c['product_name'], $c['sku'] ?? '')] = $c;
    }
    $keys = array_unique(array_merge(array_keys($entries), array_keys($byKey)));
    $rows = [];
    $qn = $q !== '' ? (function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q)) : '';
    foreach ($keys as $k) {
        $name = $entries[$k]['product_name'] ?? ($byKey[$k]['product_name'] ?? '');
        $sku  = $entries[$k]['sku'] ?? ($byKey[$k]['sku'] ?? '');
        if ($qn !== '') {
            $hay = mb_strtolower($name . ' ' . $sku, 'UTF-8');
            if (strpos($hay, $qn) === false) { continue; }
        }
        $in       = (int)($entries[$k]['in'] ?? 0);
        $sent     = (int)($byKey[$k]['sent'] ?? 0);
        $reserved = (int)($byKey[$k]['pending'] ?? 0);
        $balance  = $in - $sent;
        $rows[] = [
            'product_name' => $name, 'sku' => $sku,
            'in' => $in, 'sent' => $sent, 'reserved' => $reserved,
            'balance' => $balance, 'available' => $balance - $reserved,
        ];
    }
    usort($rows, function ($a, $b) { return strcasecmp($a['product_name'], $b['product_name']); });
    return $rows;
}

function stock_totals() {
    $t = ['produtos' => 0, 'in' => 0, 'reserved' => 0, 'sent' => 0, 'balance' => 0, 'negativos' => 0];
    foreach (stock_balance() as $r) {
        $t['produtos']++;
        $t['in'] += $r['in']; $t['reserved'] += $r['reserved']; $t['sent'] += $r['sent']; $t['balance'] += $r['balance'];
        if ($r['available'] < 0) { $t['negativos']++; }
    }
    return $t;
}

function stock_recent_entries($limit = 50) {
    $e = stock_entries_all();
    usort($e, function ($a, $b) { return strtotime($b['datetime'] ?? 'now') <=> strtotime($a['datetime'] ?? 'now'); });
    return array_slice($e, 0, (int)$limit);
}

// Relatório de entradas de produtos por período (dia/semana/mês), com data/hora.
function stock_entries_period($granularity = 'day', $limit = 120) {
    $g = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';
    $rows = [];
    foreach (stock_entries_all() as $e) {
        if ((int)($e['qty'] ?? 0) <= 0) { continue; }
        $k = period_bucket_key($e['datetime'] ?? date('c'), $g);
        if (!isset($rows[$k])) { $rows[$k] = ['key' => $k, 'label' => period_bucket_label($k, $g), 'qty' => 0, 'count' => 0]; }
        $rows[$k]['qty'] += (int)$e['qty'];
        $rows[$k]['count']++;
    }
    krsort($rows);
    return array_slice(array_values($rows), 0, (int)$limit);
}
