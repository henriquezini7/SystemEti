<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
m_head('Configurações');
?>
<style>
.set-group{font-size:12px;color:var(--muted);font-weight:700;margin:18px 4px 8px;text-transform:uppercase;letter-spacing:.4px}
.set-row{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--line);border-bottom:0;padding:15px 16px;text-decoration:none;color:var(--ink);font-size:15px}
.set-row:first-of-type{border-radius:14px 14px 0 0}
.set-row:last-of-type{border-radius:0 0 14px 14px;border-bottom:1px solid var(--line)}
.set-row .ic{font-size:18px;width:24px;text-align:center}
.set-row .lab{flex:1}
.set-row .arr{color:var(--muted)}
.sw{width:46px;height:27px;border-radius:20px;background:#cbd5e1;position:relative;transition:.2s;flex-shrink:0}
.sw.on{background:var(--blue)}
.sw::after{content:"";position:absolute;top:3px;left:3px;width:21px;height:21px;border-radius:50%;background:#fff;transition:.2s}
.sw.on::after{left:22px}
</style>

<div class="set-group">Conta</div>
<a class="set-row" href="settings.php"><span class="ic">&#128100;</span><span class="lab">Perfil do usuário<br><small style="color:var(--muted)"><?= e($user['email']) ?></small></span><span class="arr">&#8250;</span></a>

<div class="set-group">Aplicativo</div>
<div class="set-row"><span class="ic">&#128247;</span><span class="lab">Leitura automática</span><div class="sw on" data-key="autoread"></div></div>
<div class="set-row"><span class="ic">&#128266;</span><span class="lab">Som de bip</span><div class="sw on" data-key="beep"></div></div>
<div class="set-row"><span class="ic">&#128243;</span><span class="lab">Vibração</span><div class="sw on" data-key="vibrate"></div></div>

<div class="set-group">Entrada de etiquetas</div>
<a class="set-row" href="upload.php"><span class="ic">&#128228;</span><span class="lab">Enviar PDF (ML / Shopee)</span><span class="arr">&#8250;</span></a>
<a class="set-row" href="conferencia.php"><span class="ic">&#9989;</span><span class="lab">Conferência (sem furo)</span><span class="arr">&#8250;</span></a>

<div class="set-group">Sistema</div>
<a class="set-row" href="settings.php"><span class="ic">&#9881;&#65039;</span><span class="lab">Configurações avançadas</span><span class="arr">&#8250;</span></a>
<a class="set-row" href="logout.php"><span class="ic">&#128682;</span><span class="lab" style="color:var(--red)">Sair</span><span class="arr">&#8250;</span></a>

<p style="text-align:center;color:var(--muted);font-size:12px;margin-top:18px">SystemETI · Versão 1.0.0</p>
<script>
document.querySelectorAll('.sw').forEach(function(sw){
  var k='set_'+sw.dataset.key;
  if(localStorage.getItem(k)==='0') sw.classList.remove('on');
  sw.addEventListener('click',function(){ sw.classList.toggle('on'); localStorage.setItem(k, sw.classList.contains('on')?'1':'0'); });
});
</script>
<?php m_foot('ajustes'); ?>
