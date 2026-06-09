<?php
require_once __DIR__ . '/../app/auth.php';
$error = '';
if (auth_user()) {
    redirect('index.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($email, $password)) {
        redirect('index.php');
    }
    $error = 'E-mail ou senha inválidos.';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar - SystemETI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=3.0.0">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="assets/img/icon-192.png">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="brand login-brand">
            <div class="brand-icon">SE</div>
            <div>
                <strong>SystemETI</strong>
                <span>Mercado Livre & Shopee • sem MySQL</span>
            </div>
        </div>
        <h1>Entrar no painel</h1>
        <p>Faça upload do PDF e receba a contagem de etiquetas, pedidos, produtos e unidades. Dados salvos em JSON local.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>E-mail</label>
            <input class="input" type="email" name="email" value="admin@local" required>
            <label>Senha</label>
            <input class="input" type="password" name="password" value="admin123" required>
            <button class="btn btn-primary" type="submit">Entrar</button>
        </form>
        <small class="muted">Primeiro acesso: admin@local / admin123. Troque a senha depois.</small>
    </div>
</body>
</html>
