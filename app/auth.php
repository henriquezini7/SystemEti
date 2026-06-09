<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

store_init();

function auth_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = store_find_user_by_id((int)$_SESSION['user_id']);
    if (!$user) {
        return null;
    }
    return [
        'id' => (int)$user['id'],
        'name' => $user['name'] ?? 'Administrador',
        'email' => $user['email'] ?? 'admin@local',
        'role' => $user['role'] ?? 'admin',
    ];
}

function require_login() {
    $user = auth_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function attempt_login($email, $password) {
    $user = store_find_user_by_email($email);
    if ($user && password_verify($password, $user['password_hash'] ?? '')) {
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }
    return false;
}

function logout_user() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
