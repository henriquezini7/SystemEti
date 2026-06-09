<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
$key = (string)($_GET['key'] ?? '');
$label = null;
foreach (store_scan_labels_all() as $l) {
    if (($l['key'] ?? '') === $key) { $label = $l; break; }
}
if (!$label) { m_head('Etiqueta', ['back' => 'etiquetas.php']); echo '<div class="empty-m">Etiqueta não encontrada.</div>'; m_foot('etiquetas'); exit; }

$st = $label['status'] ?? 'pending';
$statusTxt = store_label_status_label($st);
m_head('Consultar Etiqueta', ['back' => 'etiquetas.php']);
?>
<div class="m-card" style="text-align:center">
  <div class="qr" style="width:120px;height:120px;font-size:60px;margin:0 auto 10px;border-radius:14px;background:#f1f5f9;display:flex;align-items:center;justify-content:center">&#9636;</div>
  <span class="chip <?= $st==='sent'?'saida':($st==='returned'?'dev':'pend') ?>"><?= e(strtoupper($statusTxt)) ?></span>
</div>

<div class="m-card">
  <div class="kv"><span class="k">Rastreio</span><span class="v"><?= e(($label['tracking_code'] ?? '') ?: ($label['key'] ?? '-')) ?></span></div>
  <div class="kv"><span class="k">Venda</span><span class="v"><?= e(($label['sale_id'] ?? '') ?: '-') ?></span></div>
  <div class="kv"><span class="k">Destinatário</span><span class="v"><?= e(($label['recipient'] ?? '') ?: '-') ?></span></div>
  <div class="kv"><span class="k">Cidade</span><span class="v"><?= e(trim(($label['recipient_city'] ?? '') . ' ' . ($label['recipient_cep'] ?? '')) ?: '-') ?></span></div>
  <div class="kv"><span class="k">Produto</span><span class="v"><?= e(strtok(store_label_products_text($label), "\n") ?: '-') ?></span></div>
  <div class="kv"><span class="k">Plataforma</span><span class="v"><?= e(platform_label($label['platform'] ?? '')) ?></span></div>
  <div class="kv"><span class="k">Enviado em</span><span class="v"><?= !empty($label['sent_at']) ? e(app_date_from_iso($label['sent_at'])) : '-' ?></span></div>
</div>

<div class="m-card">
  <h2>Histórico de movimentações</h2>
  <div class="timeline">
    <?php $hist = array_reverse($label['history'] ?? []); foreach ($hist as $h): ?>
      <div class="ti">
        <div class="t"><?= $h['mode'] === 'return' ? 'Devolução' : 'Saída (envio)' ?></div>
        <div class="s"><?= e(app_date_from_iso($h['at'] ?? date('c'))) ?> · <?= e($h['code'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
    <div class="ti"><div class="t">Etiqueta registrada</div><div class="s"><?= e(app_date_from_iso($label['registered_at'] ?? date('c'))) ?> · PDF #<?= (int)($label['report_id'] ?? 0) ?></div></div>
  </div>
</div>
<?php m_foot('etiquetas'); ?>
