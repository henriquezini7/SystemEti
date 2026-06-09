<?php
require_once __DIR__ . '/helpers.php';

function m_head($title, $opts = []) {
    $back = $opts['back'] ?? '';
    $right = $opts['right'] ?? '';
    ?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title><?= e($title) ?> · SystemETI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/mobile.css?v=2">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#13294b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="icon" type="image/png" href="/assets/img/icon-192.png">
</head><body class="app"><div class="app-wrap">
<div class="m-top">
  <?php if ($back): ?><a class="ic left" href="<?= e($back) ?>">&#8249;</a><?php else: ?><span class="ic"></span><?php endif; ?>
  <h1><?= e($title) ?></h1>
  <?php if ($right): ?><?= $right ?><?php else: ?><span class="ic"></span><?php endif; ?>
</div>
<div class="m-body">
<?php }

function m_foot($active = '') {
    $bar = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5v14M7 5v14M11 5v10M11 17v2M15 5v14M19 5v14M21 5v14"/></svg>';
    $items = [
        ['/home.php', '&#127968;', 'Home', 'home', false],
        ['/etiquetas.php', '&#127991;&#65039;', 'Etiquetas', 'etiquetas', false],
        ['/bipar.php', $bar, 'BIPAGEM', 'bipar', true],
        ['/relatorios_app.php', '&#128202;', 'Relatórios', 'relatorios', false],
        ['/ajustes.php', '&#9881;&#65039;', 'Ajustes', 'ajustes', false],
    ];
    ?></div>
<nav class="m-nav">
<?php foreach ($items as $it): $cls = ($it[3] === $active ? 'active' : '') . ($it[4] ? ' center' : ''); ?>
  <a class="<?= trim($cls) ?>" href="<?= $it[0] ?>"><span class="ic"><?= $it[1] ?></span><?= $it[2] ?></a>
<?php endforeach; ?>
</nav>
<script>if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('/sw.js').catch(function(){});});}</script>
</div></body></html><?php }
