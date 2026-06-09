<?php
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function app_config() {
    static $config = null;
    if ($config === null) {
        $config = include __DIR__ . '/config.php';
    }
    return $config;
}

function app_timezone() {
    $config = app_config();
    return $config['timezone'] ?? 'America/Sao_Paulo';
}

function app_date($format, $timestamp = null) {
    $tz = new DateTimeZone(app_timezone());
    if ($timestamp === null) {
        $dt = new DateTime('now', $tz);
    } else {
        $dt = new DateTime('@' . (int)$timestamp);
        $dt->setTimezone($tz);
    }
    return $dt->format($format);
}

function app_date_from_iso($iso, $format = 'd/m/Y H:i') {
    try {
        $dt = new DateTime((string)$iso);
        $dt->setTimezone(new DateTimeZone(app_timezone()));
        return $dt->format($format);
    } catch (Throwable $e) {
        return date($format, strtotime((string)$iso));
    }
}

function report_day_key($iso) {
    try {
        $dt = new DateTime((string)$iso);
        $dt->setTimezone(new DateTimeZone(app_timezone()));
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return date('Y-m-d', strtotime((string)$iso));
    }
}

function br_date_from_key($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('d/m/Y', $ts) : (string)$date;
}


function app_url($path = '') {
    $base = rtrim(getenv('APP_URL') ?: '', '/');
    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }
    return '/' . ltrim($path, '/');
}

function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function moneyless_number($n) {
    return number_format((float)$n, 0, ',', '.');
}

function ensure_dir($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['_csrf'];
}

function csrf_check() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    if (!$token || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        http_response_code(419);
        die('Sessão expirada. Volte e tente novamente.');
    }
}

function current_route_name() {
    return basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
}

function active_class($file) {
    return current_route_name() === $file ? 'active' : '';
}

function base_path($path = '') {
    $base = realpath(__DIR__ . '/..');
    if (!$base) {
        $base = dirname(__DIR__);
    }
    return $base . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
}

function storage_path($path = '') {
    $storage = base_path('storage');
    ensure_dir($storage);
    return $storage . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
}

function platform_label($platform) {
    if ($platform === 'mercado_livre') return 'Mercado Livre';
    if ($platform === 'shopee') return 'Shopee';
    if ($platform === 'jadlog_danfe') return 'Jadlog / DANFE';
    return 'Genérico';
}
