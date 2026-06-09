<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
if (auth_user()) { redirect('home.php'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (attempt_login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) {
        redirect('home.php');
    }
    $error = 'Usuário ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Entrar · SystemETI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/mobile.css?v=2">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#13294b">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<style>
.login-hero{background:var(--navy);color:#fff;text-align:center;padding:46px 24px 30px}
.login-hero .logo{width:74px;height:74px;border-radius:18px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 16px}
.login-hero h1{font-size:21px;margin:0 0 6px;letter-spacing:.5px}
.login-hero p{opacity:.8;font-size:13px;margin:0}
.login-body{padding:24px}
</style>
</head><body class="app"><div class="app-wrap" style="padding-bottom:0">
<div class="login-hero">
  <div class="logo">&#128230;</div>
  <h1>CONTROLE DE SAÍDA<br>DE ETIQUETAS</h1>
  <p>Controle, rastreabilidade e segurança.</p>
</div>
<div class="login-body">
  <?php if ($error): ?><div class="scan-result bad" style="margin-bottom:14px"><strong><?= e($error) ?></strong></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="m-field"><label>Usuário (e-mail)</label><input class="m-input" type="email" name="email" placeholder="admin@local" required></div>
    <div class="m-field"><label>Senha</label>
      <div style="position:relative">
        <input class="m-input" type="password" name="password" id="pw" placeholder="Digite sua senha" required style="padding-right:46px">
        <span id="eye" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:18px;color:var(--muted)">&#128065;</span>
      </div>
    </div>
    <button class="m-btn primary" type="submit">ENTRAR</button>
  </form>
  <p style="text-align:center;color:var(--muted);font-size:12px;margin-top:24px">Versão 1.0.0</p>
  <script>
    var eye=document.getElementById('eye'),pw=document.getElementById('pw');
    eye.addEventListener('click',function(){ pw.type = pw.type==='password'?'text':'password'; eye.style.color = pw.type==='text'?'#1d4ed8':''; });
  </script>
</div>
</div>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('/sw.js').catch(function(){});}</script>
</body></html>
