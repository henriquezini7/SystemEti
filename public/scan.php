<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_scan_sync_all_reports();
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'sent') === 'return' ? 'return' : 'sent';
$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $result = store_scan_register($_POST['code'] ?? '', $mode, $user['id'] ?? 0);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$summary = store_scan_summary();
$recent = store_scan_events(12);
$pending = store_labels_filtered('pending', '', 12);
render_header('Bipagem de Envio', $user);
?>
<div class="report-hero no-print">
    <div>
        <span class="badge big">Modo inteligente de conferência</span>
        <h2>Bipe a etiqueta/rastreio antes de despachar</h2>
        <p>Toda etiqueta lida dos PDFs fica registrada. Ao bipar, o sistema muda de pendente para enviada ou devolvida.</p>
    </div>
    <div class="hero-actions">
        <a class="btn <?= $mode === 'sent' ? 'btn-primary' : 'btn-light' ?>" href="scan.php?mode=sent">Conferir envio</a>
        <a class="btn <?= $mode === 'return' ? 'btn-primary' : 'btn-light' ?>" href="scan.php?mode=return">Registrar devolução</a>
        <a class="btn btn-light" href="labels.php">Ver etiquetas</a>
    </div>
</div>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>🏷️</span><small>Etiquetas registradas</small><strong><?= (int)$summary['total'] ?></strong></div>
    <div class="metric-card soft-orange"><span>⏳</span><small>Pendentes</small><strong><?= (int)$summary['pending'] ?></strong></div>
    <div class="metric-card soft-green"><span>✅</span><small>Enviadas</small><strong><?= (int)$summary['sent'] ?></strong></div>
    <div class="metric-card soft-purple"><span>↩️</span><small>Devolvidas</small><strong><?= (int)$summary['returned'] ?></strong></div>
</div>

<div class="grid two-cols">
    <div class="panel-card scan-card">
        <div class="card-head"><div><h2><?= $mode === 'return' ? 'Registrar devolução' : 'Conferir envio' ?></h2><p>Use leitor de código de barras, pistola USB, câmera com teclado ou digite o rastreio manualmente.</p></div></div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($result): ?>
            <div class="scan-result <?= $result['ok'] ? 'ok' : 'bad' ?>">
                <strong><?= e($result['message']) ?></strong>
                <?php if (!empty($result['label'])): $label = $result['label']; ?>
                    <span><?= e(($label['recipient'] ?? '') ?: 'Destinatário não identificado') ?> · <?= e(($label['tracking_code'] ?? $label['key'] ?? '') ?: '-') ?></span>
                    <small><?= nl2br(e(store_label_products_text($label))) ?></small>
                <?php else: ?>
                    <span>Código: <?= e(scan_normalize_code($_POST['code'] ?? '')) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="scan-form" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <label for="scanCode">Código da etiqueta / rastreio / venda / pack</label>
            <input class="scan-input" id="scanCode" name="code" placeholder="Bipe aqui e pressione Enter" autofocus required>
            <button class="btn btn-primary btn-full" type="submit"><?= $mode === 'return' ? 'Registrar devolução' : 'Confirmar envio' ?></button>
        </form>

        <div class="cam-wrap">
            <button type="button" class="btn btn-light btn-full" id="camToggle">📷 Ler pela câmera (código de barras / QR)</button>
            <div id="camBox" hidden>
                <div id="reader"></div>
                <div id="camResult" class="scan-cam-result"></div>
                <button type="button" class="btn btn-danger btn-full" id="camStop">Parar câmera</button>
            </div>
        </div>
        <style>
            #reader{width:100%;max-width:440px;margin:12px auto;border-radius:12px;overflow:hidden}
            .scan-cam-result{margin:10px 0;padding:0;min-height:8px}
            .scan-cam-result.ok,.scan-cam-result.bad{padding:12px 14px;border-radius:10px;font-size:14px}
            .scan-cam-result.ok{background:#dcfce7;color:#166534}
            .scan-cam-result.bad{background:#fee2e2;color:#991b1b}
            .scan-cam-result small{display:block;opacity:.85;margin-top:4px}
        </style>

        <div class="notice-box">Regra de conferência: se o código não estiver em nenhum PDF enviado, o painel avisa como <b>etiqueta não cadastrada</b>. Isso evita despachar pacote que não entrou no controle.</div>
    </div>

    <div class="panel-card">
        <div class="card-head"><div><h2>Últimas bipagens</h2><p>Auditoria rápida do que foi conferido agora.</p></div></div>
        <div class="timeline-list">
            <?php foreach ($recent as $ev): ?>
                <div class="timeline-item <?= !empty($ev['found']) ? 'found' : 'unknown' ?>">
                    <strong><?= e($ev['message'] ?? '-') ?></strong>
                    <span><?= e($ev['code'] ?? '-') ?> · <?= e(app_date_from_iso($ev['created_at'] ?? date('c'))) ?></span>
                    <?php if (!empty($ev['recipient'])): ?><small><?= e($ev['recipient']) ?></small><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$recent): ?><div class="empty">Nenhuma bipagem ainda.</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head"><div><h2>Próximas pendentes</h2><p>Etiquetas que entraram pelos PDFs e ainda não foram bipadas como enviadas.</p></div><a class="btn btn-light" href="labels.php?status=pending">Ver todas</a></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Etiqueta/Rastreio</th><th>Destinatário</th><th>Produto</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $l): ?>
                <tr>
                    <td><strong><?= e(($l['tracking_code'] ?? '') ?: ($l['key'] ?? '-')) ?></strong><br><small><?= e(($l['sale_id'] ?? '') ?: ($l['pack_id'] ?? '')) ?></small></td>
                    <td><?= e(($l['recipient'] ?? '') ?: '-') ?><br><small><?= e(trim(($l['recipient_city'] ?? '') . ' ' . ($l['recipient_cep'] ?? ''))) ?></small></td>
                    <td><?= nl2br(e(store_label_products_text($l))) ?></td>
                    <td><span class="status-chip pending">Pendente</span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pending): ?><tr><td colspan="4" class="empty">Nenhuma etiqueta pendente.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function(){
  const input = document.getElementById('scanCode');
  if (input) { setTimeout(()=>input.focus(), 150); }
  <?php if ($result): ?>
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain); gain.connect(ctx.destination);
    osc.frequency.value = <?= $result['ok'] ? 880 : 220 ?>;
    gain.gain.value = 0.08; osc.start();
    setTimeout(()=>{ osc.stop(); ctx.close(); }, 130);
  } catch(e) {}
  <?php endif; ?>
})();
</script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  const toggle=document.getElementById('camToggle');
  const box=document.getElementById('camBox');
  const stopBtn=document.getElementById('camStop');
  const resultEl=document.getElementById('camResult');
  const csrf=<?= json_encode(csrf_token()) ?>;
  const mode=<?= json_encode($mode) ?>;
  if(!toggle){ return; }
  if(!window.Html5Qrcode){ toggle.style.display='none'; return; }

  let scanner=null, lastCode='', lastTime=0, busy=false;

  function beep(ok){ try{ const c=new (window.AudioContext||window.webkitAudioContext)(); const o=c.createOscillator(),g=c.createGain(); o.connect(g);g.connect(c.destination); o.frequency.value=ok?880:220; g.gain.value=.08; o.start(); setTimeout(()=>{o.stop();c.close();},130);}catch(e){} }
  function show(html,cls){ resultEl.className='scan-cam-result '+cls; resultEl.innerHTML=html; }

  async function onScan(code){
    const now=Date.now();
    if(busy) return;
    if(code===lastCode && (now-lastTime)<2500) return; // ignora o mesmo código em sequência
    lastCode=code; lastTime=now; busy=true;
    try{
      const fd=new FormData(); fd.append('_csrf',csrf); fd.append('mode',mode); fd.append('code',code);
      const r=await fetch('scan_api.php',{method:'POST',body:fd});
      const j=await r.json();
      beep(!!j.ok);
      const extra=j.tracking?('<small>'+((j.recipient||'')+' · '+j.tracking)+'</small>'):'';
      show('<strong>'+(j.message||'Lido: '+code)+'</strong>'+extra, j.ok?'ok':'bad');
    }catch(e){ beep(false); show('<strong>Erro de conexão. Tente de novo.</strong>','bad'); }
    finally{ setTimeout(()=>{busy=false;},700); }
  }

  async function start(){
    box.hidden=false; toggle.hidden=true;
    const fmts=[Html5QrcodeSupportedFormats.QR_CODE,Html5QrcodeSupportedFormats.CODE_128,Html5QrcodeSupportedFormats.CODE_39,Html5QrcodeSupportedFormats.CODE_93,Html5QrcodeSupportedFormats.EAN_13,Html5QrcodeSupportedFormats.EAN_8,Html5QrcodeSupportedFormats.ITF,Html5QrcodeSupportedFormats.UPC_A,Html5QrcodeSupportedFormats.CODABAR];
    scanner=new Html5Qrcode("reader",{formatsToSupport:fmts,verbose:false});
    try{
      await scanner.start({facingMode:"environment"},{fps:10,qrbox:{width:280,height:170}},onScan,()=>{});
    }catch(e){ show('<strong>Não consegui abrir a câmera. Permita o acesso (e use HTTPS).</strong>','bad'); }
  }
  async function stop(){
    if(scanner){ try{ await scanner.stop(); await scanner.clear(); }catch(e){} scanner=null; }
    box.hidden=true; toggle.hidden=false; resultEl.className='scan-cam-result'; resultEl.innerHTML='';
  }
  toggle.addEventListener('click',start);
  stopBtn.addEventListener('click',stop);
})();
</script>
<?php render_footer(); ?>
