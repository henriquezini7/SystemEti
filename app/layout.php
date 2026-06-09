<?php
require_once __DIR__ . '/helpers.php';

function render_header($title, $user = null) {
    $config = include __DIR__ . '/config.php';
    $appName = $config['app_name'];
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=12.0.0">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-icon">SE</div>
            <div>
                <strong><?= e($appName) ?></strong>
                <span>ML & Shopee • relatórios diários</span>
            </div>
        </div>
        <nav class="nav-menu">
            <a class="<?= active_class('index.php') ?>" href="index.php"><span>🏠</span> Dashboard</a>
            <a class="<?= active_class('upload.php') ?>" href="upload.php"><span>📤</span> Enviar PDF</a>
            <a class="<?= active_class('reports.php') ?>" href="reports.php"><span>📦</span> Relatórios</a>
            <a class="<?= active_class('daily_report.php') ?>" href="daily_report.php"><span>📅</span> Relatório do dia</a>
            <a class="<?= active_class('separation.php') ?>" href="separation.php"><span>🧴</span> Separação</a>
            <a class="<?= active_class('scan.php') ?>" href="scan.php"><span>📲</span> Bipagem</a>
            <a class="<?= active_class('conferencia.php') ?>" href="conferencia.php"><span>✔️</span> Conferência</a>
            <a class="<?= active_class('labels.php') ?>" href="labels.php"><span>🏷️</span> Etiquetas</a>
            <a class="<?= active_class('settings.php') ?>" href="settings.php"><span>⚙️</span> Configurações</a>
        </nav>
        <div class="sidebar-footer">
            <div class="mini-card">
                <b>Leitura inteligente</b>
                <small>Conta etiquetas, produtos, unidades, SKU e rastreios. Dados em JSON local, com total por dia e acumulado.</small>
            </div>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <button class="hamburger" type="button" id="hamburger" aria-label="Abrir menu"><span></span><span></span><span></span></button>
            <div class="top-title">
                <h1><?= e($title) ?></h1>
                <p>Conferência automática de PDFs de etiquetas.</p>
            </div>
            <?php if ($user): ?>
            <div class="user-pill">
                <div class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
                <div>
                    <strong><?= e($user['name']) ?></strong>
                    <a href="logout.php">Sair</a>
                </div>
            </div>
            <?php endif; ?>
        </header>
        <section class="content-area">
    <?php
}

function render_footer() {
    ?>
        </section>
    </main>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<script src="assets/js/app.js?v=12.0.0"></script>
</body>
</html>
    <?php
}
