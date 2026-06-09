<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reports.php');
}
csrf_check();
$scope = $_POST['scope'] ?? '';
$date = $_POST['date'] ?? '';
if ($scope === 'day') {
    store_delete_reports_by_date($date);
    redirect('reports.php');
}
if ($scope === 'all') {
    store_delete_all_reports();
    redirect('reports.php');
}
redirect('reports.php');
