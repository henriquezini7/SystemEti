<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reports.php');
}
csrf_check();
$id = (int)($_POST['id'] ?? 0);
$return = $_POST['return'] ?? 'reports.php';
if ($id > 0) {
    store_delete_report($id);
}
if (!preg_match('/^[a-zA-Z0-9_\.\?=\-&]+$/', $return)) {
    $return = 'reports.php';
}
redirect($return);
