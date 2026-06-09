<?php
// Endpoint JSON para bipagem por câmera (barcode/QR) sem recarregar a página.
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sessão expirada. Entre novamente.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método inválido.']);
    exit;
}
$token = $_POST['_csrf'] ?? '';
if (!$token || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
    exit;
}

$mode = (($_POST['mode'] ?? 'sent') === 'return') ? 'return' : 'sent';
try {
    $res = store_scan_register($_POST['code'] ?? '', $mode, $user['id'] ?? 0);
    $label = $res['label'] ?? null;
    echo json_encode([
        'ok'        => !empty($res['ok']),
        'type'      => $res['type'] ?? '',
        'message'   => $res['message'] ?? '',
        'recipient' => $label['recipient'] ?? '',
        'tracking'  => ($label['tracking_code'] ?? '') ?: ($label['key'] ?? ''),
        'products'  => $label ? store_label_products_text($label) : '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
