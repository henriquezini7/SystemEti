<?php
require_once __DIR__ . '/helpers.php';

function data_dir() {
    $dir = base_path('storage/data');
    ensure_dir($dir);
    return $dir;
}

function data_file($name) {
    return data_dir() . DIRECTORY_SEPARATOR . $name;
}

function json_read_file($file, $default = []) {
    if (!file_exists($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function json_write_file($file, $data) {
    $dir = dirname($file);
    ensure_dir($dir);
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Falha ao codificar dados JSON.');
    }
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Falha ao salvar arquivo temporário de dados.');
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Falha ao atualizar arquivo de dados.');
    }
}

function store_init() {
    ensure_dir(base_path('storage'));
    ensure_dir(base_path('storage/uploads'));
    ensure_dir(base_path('storage/text'));
    ensure_dir(base_path('storage/exports'));
    ensure_dir(data_dir());

    $usersFile = data_file('users.json');
    if (!file_exists($usersFile)) {
        json_write_file($usersFile, [[
            'id' => 1,
            'name' => 'Administrador',
            'email' => 'admin@local',
            'password_hash' => '$2y$12$lQjUHjib0OF3weut9lcwyOrlrhVHiXUaom8vVTg.85PL8VrNKSYsK',
            'role' => 'admin',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]]);
    }

    $reportsFile = data_file('reports.json');
    if (!file_exists($reportsFile)) {
        json_write_file($reportsFile, []);
    }

    $counterFile = data_file('counter.json');
    if (!file_exists($counterFile)) {
        json_write_file($counterFile, ['report_id' => 0]);
    }

    $labelsFile = data_file('labels_registry.json');
    if (!file_exists($labelsFile)) {
        json_write_file($labelsFile, []);
    }

    $eventsFile = data_file('scan_events.json');
    if (!file_exists($eventsFile)) {
        json_write_file($eventsFile, []);
    }
}

function store_users() {
    store_init();
    return json_read_file(data_file('users.json'), []);
}

function store_save_users($users) {
    json_write_file(data_file('users.json'), array_values($users));
}

function store_find_user_by_id($id) {
    foreach (store_users() as $user) {
        if ((int)$user['id'] === (int)$id) {
            return $user;
        }
    }
    return null;
}

function store_find_user_by_email($email) {
    $email = strtolower(trim((string)$email));
    foreach (store_users() as $user) {
        if (strtolower($user['email'] ?? '') === $email) {
            return $user;
        }
    }
    return null;
}

function store_update_user($id, $name, $email, $password = '') {
    $users = store_users();
    $found = false;
    foreach ($users as &$user) {
        if ((int)$user['id'] === (int)$id) {
            $user['name'] = trim($name);
            $user['email'] = trim($email);
            if ($password !== '') {
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $user['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($user);
    if (!$found) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    store_save_users($users);
}

function store_next_report_id() {
    store_init();
    $file = data_file('counter.json');
    $fh = fopen($file, 'c+');
    if (!$fh) {
        throw new RuntimeException('Não consegui abrir o contador de relatórios.');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Não consegui bloquear o contador de relatórios.');
        }
        $raw = stream_get_contents($fh);
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) { $data = []; }
        $next = (int)($data['report_id'] ?? 0) + 1;
        $data['report_id'] = $next;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $next;
    } catch (Throwable $e) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw $e;
    }
}

function store_reports($limit = null, $includeDuplicates = false) {
    store_init();
    $reports = json_read_file(data_file('reports.json'), []);
    if (!$includeDuplicates) {
        $reports = array_values(array_filter($reports, function($r) {
            return !store_report_is_duplicate($r);
        }));
    }
    usort($reports, function($a, $b) {
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });
    if ($limit !== null) {
        return array_slice($reports, 0, (int)$limit);
    }
    return $reports;
}

function store_save_reports_index($reports) {
    usort($reports, function($a, $b) {
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });
    json_write_file(data_file('reports.json'), array_values($reports));
}


/* v14 - Controle anti-duplicidade por hash do PDF */
function store_reports_raw_index() {
    store_init();
    $reports = json_read_file(data_file('reports.json'), []);
    return is_array($reports) ? $reports : [];
}

function store_compute_report_file_hash($report) {
    $stored = basename((string)($report['stored_filename'] ?? ''));
    if ($stored === '') { return ''; }
    $path = storage_path('uploads/' . $stored);
    if (!is_file($path)) { return ''; }
    $hash = @hash_file('sha256', $path);
    return is_string($hash) ? strtolower($hash) : '';
}

function store_deduplicate_existing_reports() {
    store_init();
    $reports = store_reports_raw_index();
    if (!$reports) { return ['changed' => false, 'duplicates' => 0]; }
    usort($reports, function($a, $b) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });
    $seen = [];
    $changed = false;
    $dups = 0;
    foreach ($reports as &$report) {
        $hash = trim((string)($report['file_hash'] ?? ''));
        if ($hash === '') {
            $hash = store_compute_report_file_hash($report);
            if ($hash !== '') {
                $report['file_hash'] = $hash;
                $report['file_size'] = (int)@filesize(storage_path('uploads/' . basename((string)($report['stored_filename'] ?? ''))));
                $changed = true;
            }
        }
        if ($hash === '') { continue; }
        if (isset($seen[$hash])) {
            $originalId = (int)$seen[$hash];
            if ((int)($report['duplicate_of'] ?? 0) !== $originalId) {
                $report['duplicate_of'] = $originalId;
                $report['ignored_from_totals'] = true;
                $report['duplicate_marked_at'] = date('c');
                $report['warnings'] = array_values(array_unique(array_merge($report['warnings'] ?? [], ['Arquivo duplicado: não entra nos totais. Original: relatório #' . $originalId . '.'])));
                $changed = true;
            }
            $dups++;
            store_unregister_report_labels((int)($report['id'] ?? 0));
        } else {
            $seen[$hash] = (int)($report['id'] ?? 0);
            if (!empty($report['duplicate_of'])) {
                unset($report['duplicate_of'], $report['ignored_from_totals'], $report['duplicate_marked_at']);
                $changed = true;
            }
        }
    }
    unset($report);
    if ($changed) { store_save_reports_index($reports); }
    return ['changed' => $changed, 'duplicates' => $dups];
}

function store_find_report_by_file_hash($hash) {
    $hash = strtolower(trim((string)$hash));
    if ($hash === '') { return null; }
    store_deduplicate_existing_reports();
    foreach (store_reports_raw_index() as $report) {
        if (!empty($report['duplicate_of']) || !empty($report['ignored_from_totals'])) { continue; }
        if (strtolower(trim((string)($report['file_hash'] ?? ''))) === $hash) {
            return $report;
        }
    }
    return null;
}

function store_report_is_duplicate($report) {
    return !empty($report['duplicate_of']) || !empty($report['ignored_from_totals']);
}

function store_save_report($userId, $originalFilename, $storedFilename, array $result, $rawTextRelPath) {
    $id = store_next_report_id();
    $report = [
        'id' => $id,
        'user_id' => (int)$userId,
        'original_filename' => $originalFilename,
        'stored_filename' => $storedFilename,
        'file_hash' => strtolower(trim((string)($result['_file_hash'] ?? ''))),
        'file_size' => (int)($result['_file_size'] ?? 0),
        'platform' => $result['platform'] ?? 'generico',
        'total_pages' => (int)($result['pages'] ?? 0),
        'total_labels' => (int)($result['total_labels'] ?? 0),
        'total_orders' => (int)($result['total_orders'] ?? 0),
        'total_units' => (int)($result['total_units'] ?? 0),
        'raw_text_path' => $rawTextRelPath,
        'warnings' => $result['warnings'] ?? [],
        'created_at' => date('c'),
    ];

    $items = array_values($result['items'] ?? []);
    $orders = array_values($result['orders'] ?? []);
    json_write_file(data_file('report_' . $id . '.json'), [
        'report' => $report,
        'items' => $items,
        'orders' => $orders,
    ]);

    $reports = store_reports();
    $reports[] = $report;
    store_save_reports_index($reports);

    store_register_report_labels($id, $report, $orders);
    return $id;
}

function store_get_report_bundle($id) {
    store_init();
    $file = data_file('report_' . (int)$id . '.json');
    if (!file_exists($file)) {
        return null;
    }
    $bundle = json_read_file($file, null);
    if (!is_array($bundle) || empty($bundle['report'])) {
        return null;
    }
    $bundle['items'] = $bundle['items'] ?? [];
    $bundle['orders'] = $bundle['orders'] ?? [];
    return $bundle;
}

function store_totals() {
    $totals = ['reports' => 0, 'labels' => 0, 'orders' => 0, 'units' => 0];
    foreach (store_reports() as $report) {
        $totals['reports']++;
        $totals['labels'] += (int)($report['total_labels'] ?? 0);
        $totals['orders'] += (int)($report['total_orders'] ?? 0);
        $totals['units'] += (int)($report['total_units'] ?? 0);
    }
    return $totals;
}


function store_product_display_name($name) {
    $name = str_replace(["\u{E016}", "\u{FB01}", "�"], 'f', (string)$name);
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    if ($name === '') { return 'Produto não identificado'; }
    $replacements = [
        '/\bPerfiume\b/iu' => 'Perfume',
        '/\bParfium\b/iu' => 'Parfum',
        '/\bLattafia\b/iu' => 'Lattafa',
        '/\bArmafi\b/iu' => 'Armaf',
        '/\bAfinan\b/iu' => 'Afnan',
        '/\bLollection\b/iu' => 'Collection',
        '/\bEau De Perfume\b/iu' => 'Eau de Parfum',
        '/\bEdp\b/u' => 'EDP',
        '/\bEdt\b/u' => 'EDT',
    ];
    foreach ($replacements as $pattern => $replace) {
        $name = preg_replace($pattern, $replace, $name);
    }
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name === '' ? 'Produto não identificado' : $name;
}

function store_order_unique_key($o, $fallback = '') {
    $sale = trim((string)($o['sale_id'] ?? ''));
    $pack = trim((string)($o['pack_id'] ?? ''));
    $tracking = trim((string)($o['tracking_code'] ?? ''));
    $ship = trim((string)($o['shipment_id'] ?? ''));
    if ($sale !== '') { return 'sale:' . $sale; }
    if ($pack !== '') { return 'pack:' . $pack; }
    if ($tracking !== '') { return 'track:' . $tracking; }
    if ($ship !== '') { return 'ship:' . $ship; }
    return 'row:' . $fallback;
}

function store_product_key($name, $sku = '') {
    $sku = trim((string)$sku);
    $name = store_product_display_name($name);
    // Para a lista de separação, o que manda é o nome do produto. SKU diferente não pode quebrar o total na bancada.
    $base = ($name !== '' && $name !== 'Produto não identificado') ? 'name:' . $name : 'sku:' . $sku;
    return function_exists('mb_strtolower') ? mb_strtolower($base, 'UTF-8') : strtolower($base);
}

function store_separation_from_orders($orders) {
    $map = [];
    $row = 0;
    foreach (($orders ?: []) as $o) {
        $row++;
        $name = store_product_display_name($o['product_name'] ?? 'Produto não identificado');
        $sku = trim((string)($o['sku'] ?? ''));
        $qty = max(1, (int)($o['quantity'] ?? 1));
        $key = store_product_key($name, $sku);
        if (!isset($map[$key])) {
            $map[$key] = [
                'product_name' => $name,
                'sku' => $sku,
                'orders_count' => 0,
                'quantity' => 0,
                '_order_keys' => [],
                'recipients' => [],
            ];
        }
        $orderKey = store_order_unique_key($o, (string)$row);
        $map[$key]['_order_keys'][$orderKey] = true;
        $map[$key]['quantity'] += $qty;
        $recipient = trim((string)($o['recipient'] ?? ''));
        if ($recipient !== '' && count($map[$key]['recipients']) < 8) {
            $map[$key]['recipients'][] = $recipient;
        }
    }
    foreach ($map as &$item) {
        $item['orders_count'] = count($item['_order_keys']);
        unset($item['_order_keys']);
    }
    unset($item);
    usort($map, function($a, $b) {
        if ((int)$a['orders_count'] === (int)$b['orders_count']) {
            if ((int)$a['quantity'] === (int)$b['quantity']) {
                return strcasecmp($a['product_name'], $b['product_name']);
            }
            return (int)$b['quantity'] <=> (int)$a['quantity'];
        }
        return (int)$b['orders_count'] <=> (int)$a['orders_count'];
    });
    return array_values($map);
}

function store_top_products($limit = 8) {
    $summary = store_general_summary();
    return array_slice($summary['items'] ?? [], 0, (int)$limit);
}

/* v17 - Controle de produtos: por produto, quanto entrou x saiu (bipado) x falta */
function store_products_control($limit = 1000) {
    store_init();
    $map = [];
    foreach (store_scan_labels_all() as $l) {
        $status = $l['status'] ?? 'pending';
        foreach (($l['products'] ?? []) as $p) {
            $name = store_product_display_name($p['product_name'] ?? 'Produto não identificado');
            $sku = trim((string)($p['sku'] ?? ''));
            $key = store_product_key($name, $sku);
            if (!isset($map[$key])) {
                $map[$key] = ['product_name' => $name, 'sku' => $sku, 'total' => 0, 'sent' => 0, 'pending' => 0, 'returned' => 0, 'labels' => 0];
            }
            $q = max(1, (int)($p['quantity'] ?? 1));
            $map[$key]['total'] += $q;
            $map[$key]['labels'] += 1;
            if ($status === 'sent') { $map[$key]['sent'] += $q; }
            elseif ($status === 'returned') { $map[$key]['returned'] += $q; }
            else { $map[$key]['pending'] += $q; }
        }
    }
    usort($map, function ($a, $b) {
        if ($a['total'] === $b['total']) { return strcasecmp($a['product_name'], $b['product_name']); }
        return $b['total'] <=> $a['total'];
    });
    return array_slice(array_values($map), 0, (int)$limit);
}

function store_products_totals() {
    $t = ['produtos' => 0, 'total' => 0, 'sent' => 0, 'pending' => 0];
    foreach (store_products_control() as $p) {
        $t['produtos']++;
        $t['total'] += (int)$p['total'];
        $t['sent'] += (int)$p['sent'];
        $t['pending'] += (int)$p['pending'];
    }
    return $t;
}

function store_report_day($report) {
    return report_day_key($report['created_at'] ?? date('c'));
}

function store_reports_by_date($date) {
    $date = preg_replace('/[^0-9\-]/', '', (string)$date);
    $out = [];
    foreach (store_reports() as $report) {
        if (store_report_day($report) === $date) {
            $out[] = $report;
        }
    }
    usort($out, function($a, $b) {
        return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
    });
    return $out;
}


function store_aggregate_reports($reports) {
    $summary = [
        'reports_count' => 0,
        'labels' => 0,
        'orders' => 0,
        'units' => 0,
        'items' => [],
        'orders_list' => [],
    ];
    $labelsMap = [];
    $orderKeys = [];
    $ordersList = [];

    foreach ($reports as $report) {
        $summary['reports_count']++;
        $bundle = store_get_report_bundle((int)($report['id'] ?? 0));
        if (!$bundle) { continue; }

        foreach (($bundle['orders'] ?? []) as $idx => $o) {
            $tracking = trim((string)($o['tracking_code'] ?? ''));
            $qty = max(1, (int)($o['quantity'] ?? 1));
            $name = store_product_display_name($o['product_name'] ?? 'Produto não identificado');
            $o['product_name'] = $name;
            if ($tracking !== '') { $labelsMap[$tracking] = true; }
            $orderKey = store_order_unique_key($o, (string)((int)($report['id'] ?? 0)) . '-' . $idx);
            $orderKeys[$orderKey] = true;
            $summary['units'] += $qty;
            $o['_report_id'] = (int)($report['id'] ?? 0);
            $o['_report_date'] = $report['created_at'] ?? '';
            $ordersList[] = $o;
        }
    }

    $summary['labels'] = count($labelsMap);
    if ($summary['labels'] === 0) {
        foreach ($reports as $r) { $summary['labels'] += (int)($r['total_labels'] ?? 0); }
    }
    $summary['orders'] = count($orderKeys);
    if ($summary['orders'] === 0) {
        foreach ($reports as $r) { $summary['orders'] += (int)($r['total_orders'] ?? 0); }
    }
    if ($summary['units'] === 0) {
        foreach ($reports as $r) { $summary['units'] += (int)($r['total_units'] ?? 0); }
    }

    $summary['orders_list'] = $ordersList;
    $summary['items'] = store_separation_from_orders($ordersList);
    return $summary;
}

function store_day_summary($date = null) {
    if ($date === null || $date === '') {
        $date = app_date('Y-m-d');
    }
    return store_aggregate_reports(store_reports_by_date($date));
}

function store_general_summary() {
    return store_aggregate_reports(store_reports());
}

function store_daily_index($limit = 30) {
    $days = [];
    foreach (store_reports() as $report) {
        $day = store_report_day($report);
        if (!isset($days[$day])) {
            $days[$day] = ['date' => $day, 'reports' => 0, 'labels' => 0, 'orders' => 0, 'units' => 0];
        }
        $days[$day]['reports']++;
        $days[$day]['labels'] += (int)($report['total_labels'] ?? 0);
        $days[$day]['orders'] += (int)($report['total_orders'] ?? 0);
        $days[$day]['units'] += (int)($report['total_units'] ?? 0);
    }
    krsort($days);
    return array_slice(array_values($days), 0, (int)$limit);
}

/* v16 - Relatórios por período: dia / semana / mês */
function period_bucket_key($iso, $granularity) {
    try {
        $dt = new DateTime((string)$iso);
        $dt->setTimezone(new DateTimeZone(app_timezone()));
    } catch (Throwable $e) {
        $dt = new DateTime('now', new DateTimeZone(app_timezone()));
    }
    if ($granularity === 'month') { return $dt->format('Y-m'); }
    if ($granularity === 'week')  { return $dt->format('o-\WW'); } // ano ISO + semana
    return $dt->format('Y-m-d');
}

function period_bucket_label($key, $granularity) {
    if ($granularity === 'month') {
        $meses = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho','07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];
        $p = explode('-', (string)$key);
        return ($meses[$p[1] ?? ''] ?? ($p[1] ?? '')) . '/' . ($p[0] ?? '');
    }
    if ($granularity === 'week') {
        $p = explode('-W', (string)$key);
        return 'Semana ' . ($p[1] ?? '') . ' · ' . ($p[0] ?? '');
    }
    return br_date_from_key($key);
}

// Agrega entrada (por data do PDF) e saída (por data da bipagem) em buckets de dia/semana/mês.
function store_period_report($granularity = 'day', $limit = 90) {
    $g = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';
    $rows = [];
    $blank = function ($k) { return ['key' => $k, 'reports' => 0, 'labels' => 0, 'orders' => 0, 'units' => 0, 'sent' => 0, 'units_sent' => 0]; };

    foreach (store_reports() as $r) {
        $k = period_bucket_key($r['created_at'] ?? date('c'), $g);
        if (!isset($rows[$k])) { $rows[$k] = $blank($k); }
        $rows[$k]['reports']++;
        $rows[$k]['labels'] += (int)($r['total_labels'] ?? 0);
        $rows[$k]['orders'] += (int)($r['total_orders'] ?? 0);
        $rows[$k]['units']  += (int)($r['total_units'] ?? 0);
    }
    foreach (store_scan_labels_all() as $l) {
        if (($l['status'] ?? '') === 'sent' && !empty($l['sent_at'])) {
            $k = period_bucket_key($l['sent_at'], $g);
            if (!isset($rows[$k])) { $rows[$k] = $blank($k); }
            $rows[$k]['sent']++;
            $rows[$k]['units_sent'] += max(1, (int)($l['units_total'] ?? 1));
        }
    }
    krsort($rows);
    foreach ($rows as &$row) { $row['label'] = period_bucket_label($row['key'], $g); }
    unset($row);
    return array_slice(array_values($rows), 0, (int)$limit);
}

function store_safe_unlink($path) {
    $path = (string)$path;
    if ($path !== '' && file_exists($path) && is_file($path)) {
        @unlink($path);
    }
}

function store_delete_report($id) {
    store_init();
    $id = (int)$id;
    if ($id <= 0) { return false; }
    $reports = store_reports(null, true);
    $target = null;
    $remaining = [];
    foreach ($reports as $report) {
        if ((int)($report['id'] ?? 0) === $id) {
            $target = $report;
        } else {
            $remaining[] = $report;
        }
    }
    if (!$target) { return false; }

    $bundleFile = data_file('report_' . $id . '.json');
    store_safe_unlink($bundleFile);

    $stored = basename((string)($target['stored_filename'] ?? ''));
    if ($stored !== '') {
        store_safe_unlink(storage_path('uploads/' . $stored));
        store_safe_unlink(storage_path('text/' . preg_replace('/\.pdf$/i', '.txt', $stored)));
    }

    $raw = (string)($target['raw_text_path'] ?? '');
    if ($raw !== '') {
        $raw = ltrim(str_replace(['..', '\\'], ['', '/'], $raw), '/');
        store_safe_unlink(base_path($raw));
    }

    // Limpa exports relacionados, se existirem em versões futuras.
    foreach (glob(storage_path('exports/*_' . $id . '.*')) ?: [] as $file) {
        store_safe_unlink($file);
    }

    store_unregister_report_labels($id);
    store_save_reports_index($remaining);
    return true;
}

function store_delete_reports_by_date($date) {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ? $date : app_date('Y-m-d');
    $count = 0;
    foreach (store_reports_by_date($date) as $report) {
        if (store_delete_report((int)($report['id'] ?? 0))) {
            $count++;
        }
    }
    return $count;
}

function store_delete_all_reports() {
    $count = 0;
    foreach (store_reports(null, true) as $report) {
        if (store_delete_report((int)($report['id'] ?? 0))) {
            $count++;
        }
    }
    return $count;
}

// Zera tudo para começar produção limpa: relatórios, etiquetas registradas e auditoria de bipagem.
// Mantém o usuário/senha. Útil depois dos testes.
function store_reset_all() {
    store_init();
    store_delete_all_reports();
    store_save_scan_labels([]);
    store_save_scan_events([]);
    return true;
}

/* v12 - Registro e bipagem de etiquetas */
function store_labels_file() { return data_file('labels_registry.json'); }
function store_events_file() { return data_file('scan_events.json'); }

function scan_normalize_code($code) {
    $code = strtoupper(trim((string)$code));
    $code = str_replace(["\r", "\n", "\t", ' '], '', $code);
    $code = preg_replace('/^(TRACK|RASTREIO|CODIGO|CÓDIGO|ETIQUETA|BARRA)[:\-]*/iu', '', $code);
    $code = preg_replace('/[^A-Z0-9\$\-]/', '', $code);
    return $code ?: '';
}

function scan_aliases_from_value($value) {
    $out = [];
    $n = scan_normalize_code($value);
    if ($n === '') { return $out; }
    $out[$n] = true;

    if (strpos($n, '$') !== false) {
        $parts = explode('$', $n);
        if (!empty($parts[0])) { $out[scan_normalize_code($parts[0])] = true; }
    }
    if (preg_match('/^(BR[A-Z0-9]{13})SPXLM/i', $n, $m)) {
        $out[scan_normalize_code($m[1])] = true;
    }
    if (preg_match('/^([A-Z]{2}[0-9A-Z]{9,13}[A-Z])SPXLM/i', $n, $m)) {
        $out[scan_normalize_code($m[1])] = true;
    }
    // Alguns leitores adicionam sufixos internos depois do código principal.
    if (preg_match('/^([A-Z]{2}[0-9]{9}[A-Z]{2})/', $n, $m)) {
        $out[scan_normalize_code($m[1])] = true;
    }
    return array_keys($out);
}

function scan_aliases_from_order($o) {
    $fields = ['tracking_code','shipment_id','sale_id','pack_id','nf','dace_number'];
    $aliases = [];
    foreach ($fields as $f) {
        if (!empty($o[$f])) {
            foreach (scan_aliases_from_value($o[$f]) as $a) { $aliases[$a] = true; }
        }
    }
    return array_keys($aliases);
}

function scan_label_key_from_order($o, $fallback = '') {
    foreach (['tracking_code','shipment_id','sale_id','pack_id'] as $f) {
        $n = scan_normalize_code($o[$f] ?? '');
        if ($n !== '') {
            $aliases = scan_aliases_from_value($n);
            return $aliases[0] ?? $n;
        }
    }
    return 'ROW-' . scan_normalize_code($fallback ?: uniqid('', true));
}

function store_scan_labels_all() {
    store_init();
    $labels = json_read_file(store_labels_file(), []);
    return is_array($labels) ? $labels : [];
}

function store_save_scan_labels($labels) {
    json_write_file(store_labels_file(), array_values($labels));
}

function store_scan_events($limit = null) {
    store_init();
    $events = json_read_file(store_events_file(), []);
    usort($events, function($a, $b) { return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now'); });
    if ($limit !== null) { return array_slice($events, 0, (int)$limit); }
    return $events;
}

function store_save_scan_events($events) {
    usort($events, function($a, $b) { return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now'); });
    json_write_file(store_events_file(), array_values($events));
}

function store_label_status_label($status) {
    $map = [
        'pending' => 'Pendente',
        'sent' => 'Enviado',
        'returned' => 'Devolvido',
        'unknown' => 'Não cadastrado',
    ];
    return $map[$status] ?? 'Pendente';
}

function store_register_report_labels($reportId, $report, $orders) {
    store_init();
    $labels = store_scan_labels_all();
    $byKey = [];
    foreach ($labels as $i => $label) {
        $key = (string)($label['key'] ?? '');
        if ($key !== '') { $byKey[$key] = $i; }
    }

    $now = date('c');
    $row = 0;
    foreach (($orders ?: []) as $o) {
        $row++;
        $key = scan_label_key_from_order($o, $reportId . '-' . $row);
        if ($key === '') { continue; }
        $aliases = scan_aliases_from_order($o);
        $product = [
            'product_name' => store_product_display_name($o['product_name'] ?? 'Produto não identificado'),
            'sku' => trim((string)($o['sku'] ?? '')),
            'quantity' => max(1, (int)($o['quantity'] ?? 1)),
            'item_value' => trim((string)($o['item_value'] ?? '')),
        ];

        if (!isset($byKey[$key])) {
            $labels[] = [
                'key' => $key,
                'aliases' => array_values(array_unique(array_merge([$key], $aliases))),
                'status' => 'pending',
                'report_id' => (int)$reportId,
                'report_file' => $report['original_filename'] ?? '',
                'platform' => $report['platform'] ?? '',
                'registered_at' => $now,
                'tracking_code' => trim((string)($o['tracking_code'] ?? '')),
                'shipment_id' => trim((string)($o['shipment_id'] ?? '')),
                'sale_id' => trim((string)($o['sale_id'] ?? '')),
                'pack_id' => trim((string)($o['pack_id'] ?? '')),
                'recipient' => trim((string)($o['recipient'] ?? '')),
                'recipient_address' => trim((string)($o['recipient_address'] ?? '')),
                'recipient_city' => trim((string)($o['recipient_city'] ?? '')),
                'recipient_cep' => trim((string)($o['recipient_cep'] ?? '')),
                'sender_name' => trim((string)($o['sender_name'] ?? '')),
                'sender_city' => trim((string)($o['sender_city'] ?? '')),
                'nf' => trim((string)($o['nf'] ?? '')),
                'service' => trim((string)($o['service'] ?? '')),
                'weight' => trim((string)($o['weight'] ?? '')),
                'dace_number' => trim((string)($o['dace_number'] ?? '')),
                'products' => [$product],
                'units_total' => $product['quantity'],
                'scan_count' => 0,
                'sent_at' => '',
                'returned_at' => '',
                'last_scan_at' => '',
                'history' => [],
            ];
            $byKey[$key] = count($labels) - 1;
        } else {
            $idx = $byKey[$key];
            $existing = $labels[$idx];
            $labels[$idx]['aliases'] = array_values(array_unique(array_merge($existing['aliases'] ?? [], [$key], $aliases)));
            foreach (['tracking_code','shipment_id','sale_id','pack_id','recipient','recipient_address','recipient_city','recipient_cep','sender_name','sender_city','nf','service','weight','dace_number'] as $f) {
                if (empty($labels[$idx][$f]) && !empty($o[$f])) { $labels[$idx][$f] = trim((string)$o[$f]); }
            }
            $productKey = store_product_key($product['product_name'], $product['sku']);
            $foundProd = false;
            foreach (($labels[$idx]['products'] ?? []) as $pi => $p) {
                if (store_product_key($p['product_name'] ?? '', $p['sku'] ?? '') === $productKey) {
                    $labels[$idx]['products'][$pi]['quantity'] = (int)($labels[$idx]['products'][$pi]['quantity'] ?? 0) + $product['quantity'];
                    $foundProd = true;
                    break;
                }
            }
            if (!$foundProd) { $labels[$idx]['products'][] = $product; }
            $labels[$idx]['units_total'] = 0;
            foreach (($labels[$idx]['products'] ?? []) as $p) { $labels[$idx]['units_total'] += max(1, (int)($p['quantity'] ?? 1)); }
        }
    }
    store_save_scan_labels($labels);
}

function store_unregister_report_labels($reportId) {
    $reportId = (int)$reportId;
    $labels = store_scan_labels_all();
    $remaining = [];
    foreach ($labels as $label) {
        if ((int)($label['report_id'] ?? 0) !== $reportId) { $remaining[] = $label; }
    }
    store_save_scan_labels($remaining);
}

function store_scan_sync_all_reports() {
    // Adiciona ao registro etiquetas de relatórios antigos, sem apagar status já bipado.
    store_deduplicate_existing_reports();
    foreach (store_reports() as $r) {
        $bundle = store_get_report_bundle((int)($r['id'] ?? 0));
        if ($bundle) { store_register_report_labels((int)$r['id'], $bundle['report'], $bundle['orders'] ?? []); }
    }
}

function store_find_label_index_by_scan($code, &$labels) {
    $aliasesToFind = scan_aliases_from_value($code);
    if (!$aliasesToFind) { return -1; }
    $wanted = array_fill_keys($aliasesToFind, true);
    foreach ($labels as $i => $label) {
        foreach (($label['aliases'] ?? []) as $alias) {
            $a = scan_normalize_code($alias);
            if ($a !== '' && isset($wanted[$a])) { return $i; }
        }
    }
    return -1;
}

function store_scan_register($code, $mode, $userId = 0) {
    store_init();
    $mode = $mode === 'return' ? 'return' : 'sent';
    $norm = scan_normalize_code($code);
    if ($norm === '') { throw new RuntimeException('Bipe uma etiqueta/rastreio válido.'); }

    $labels = store_scan_labels_all();
    $idx = store_find_label_index_by_scan($norm, $labels);
    $now = date('c');
    $event = [
        'id' => bin2hex(random_bytes(8)),
        'code' => $norm,
        'mode' => $mode,
        'user_id' => (int)$userId,
        'created_at' => $now,
        'found' => false,
        'message' => '',
    ];

    if ($idx < 0) {
        $event['status'] = 'unknown';
        $event['message'] = 'Etiqueta não cadastrada em nenhum PDF.';
        $events = store_scan_events();
        $events[] = $event;
        store_save_scan_events($events);
        return ['ok' => false, 'type' => 'unknown', 'message' => $event['message'], 'event' => $event, 'label' => null];
    }

    $label = $labels[$idx];
    $old = $label['status'] ?? 'pending';
    $event['found'] = true;
    $event['label_key'] = $label['key'] ?? '';
    $event['old_status'] = $old;

    if ($mode === 'sent') {
        if ($old === 'sent') {
            $event['status'] = 'duplicate_sent';
            $event['message'] = 'Essa etiqueta já tinha sido bipada como enviada.';
        } else {
            $labels[$idx]['status'] = 'sent';
            $labels[$idx]['sent_at'] = $labels[$idx]['sent_at'] ?: $now;
            $event['status'] = 'sent';
            $event['message'] = 'Envio conferido com sucesso.';
        }
    } else {
        if ($old === 'returned') {
            $event['status'] = 'duplicate_return';
            $event['message'] = 'Essa etiqueta já tinha sido registrada como devolvida.';
        } else {
            $labels[$idx]['status'] = 'returned';
            $labels[$idx]['returned_at'] = $now;
            $event['status'] = 'returned';
            $event['message'] = 'Devolução registrada com sucesso.';
        }
    }

    $labels[$idx]['scan_count'] = (int)($labels[$idx]['scan_count'] ?? 0) + 1;
    $labels[$idx]['last_scan_at'] = $now;
    $labels[$idx]['history'][] = [
        'at' => $now,
        'mode' => $mode,
        'code' => $norm,
        'old_status' => $old,
        'new_status' => $labels[$idx]['status'] ?? $old,
        'user_id' => (int)$userId,
    ];

    $event['new_status'] = $labels[$idx]['status'] ?? $old;
    $event['recipient'] = $labels[$idx]['recipient'] ?? '';
    $event['products'] = $labels[$idx]['products'] ?? [];
    $event['report_id'] = $labels[$idx]['report_id'] ?? 0;

    store_save_scan_labels($labels);
    $events = store_scan_events();
    $events[] = $event;
    store_save_scan_events($events);

    return ['ok' => true, 'type' => $event['status'], 'message' => $event['message'], 'event' => $event, 'label' => $labels[$idx]];
}

function store_scan_summary($date = null) {
    $labels = store_scan_labels_all();
    $summary = ['total'=>0,'pending'=>0,'sent'=>0,'returned'=>0,'unknown'=>0,'sent_today'=>0,'returned_today'=>0,'units_pending'=>0,'units_sent'=>0,'units_returned'=>0];
    $day = $date ?: app_date('Y-m-d');
    foreach ($labels as $l) {
        $summary['total']++;
        $status = $l['status'] ?? 'pending';
        if (!isset($summary[$status])) { $summary[$status] = 0; }
        $summary[$status]++;
        $units = max(1, (int)($l['units_total'] ?? 1));
        if ($status === 'pending') { $summary['units_pending'] += $units; }
        if ($status === 'sent') { $summary['units_sent'] += $units; }
        if ($status === 'returned') { $summary['units_returned'] += $units; }
        if (!empty($l['sent_at']) && report_day_key($l['sent_at']) === $day) { $summary['sent_today']++; }
        if (!empty($l['returned_at']) && report_day_key($l['returned_at']) === $day) { $summary['returned_today']++; }
    }
    foreach (store_scan_events() as $ev) {
        if (!empty($ev['status']) && $ev['status'] === 'unknown') { $summary['unknown']++; }
    }
    return $summary;
}

function store_labels_filtered($status = '', $q = '', $limit = 500) {
    $status = trim((string)$status);
    $qNorm = scan_normalize_code($q);
    $qText = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$q), 'UTF-8') : strtolower(trim((string)$q));
    $out = [];
    foreach (store_scan_labels_all() as $l) {
        if ($status !== '' && ($l['status'] ?? 'pending') !== $status) { continue; }
        if ($qText !== '') {
            $hay = json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hayLow = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
            $matchText = strpos($hayLow, $qText) !== false;
            $matchCode = false;
            if ($qNorm !== '') {
                foreach (($l['aliases'] ?? []) as $a) { if (scan_normalize_code($a) === $qNorm) { $matchCode = true; break; } }
            }
            if (!$matchText && !$matchCode) { continue; }
        }
        $out[] = $l;
    }
    usort($out, function($a, $b) {
        $aw = ['pending'=>0,'sent'=>1,'returned'=>2][$a['status'] ?? 'pending'] ?? 3;
        $bw = ['pending'=>0,'sent'=>1,'returned'=>2][$b['status'] ?? 'pending'] ?? 3;
        if ($aw === $bw) { return strtotime($b['registered_at'] ?? 'now') <=> strtotime($a['registered_at'] ?? 'now'); }
        return $aw <=> $bw;
    });
    return array_slice($out, 0, (int)$limit);
}

function store_label_products_text($label) {
    $parts = [];
    foreach (($label['products'] ?? []) as $p) {
        $name = store_product_display_name($p['product_name'] ?? 'Produto não identificado');
        $qty = max(1, (int)($p['quantity'] ?? 1));
        $sku = trim((string)($p['sku'] ?? ''));
        $parts[] = $name . ' x' . $qty . ($sku ? ' · SKU ' . $sku : '');
    }
    return implode("\n", $parts);
}
