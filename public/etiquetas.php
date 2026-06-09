<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
store_scan_sync_all_reports();
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$labels = store_labels_filtered($status, $q, 500);

$tabs = ['' => 'Todas', 'pending' => 'Pendentes', 'sent' => 'Enviadas', 'returned' => 'Devolvidas'];
$chipMap = ['sent' => ['saida', 'SAÍDA'], 'pending' => ['pend', 'PENDENTE'], 'returned' => ['dev', 'DEVOLUÇÃO']];
m_head('Etiquetas');
?>
<form class="m-search" method="get">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <span>&#128269;</span>
  <input name="q" value="<?= e($q) ?>" placeholder="Buscar por rastreio, cliente, produto...">
</form>
<div class="m-tabs">
  <?php foreach ($tabs as $k => $label): $qs = 'status=' . urlencode($k) . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>
    <a class="<?= $status === $k ? 'active' : '' ?>" href="etiquetas.php?<?= $qs ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php foreach ($labels as $l): $st = $l['status'] ?? 'pending'; $chip = $chipMap[$st] ?? ['pend', 'PENDENTE']; ?>
  <a class="lbl-item" href="etiqueta.php?key=<?= urlencode($l['key'] ?? '') ?>">
    <div class="qr">&#9636;</div>
    <div class="info">
      <div class="code"><?= e(($l['tracking_code'] ?? '') ?: ($l['key'] ?? '-')) ?></div>
      <div class="desc"><?= e(strtok(store_label_products_text($l), "\n") ?: ($l['recipient'] ?? '-')) ?></div>
      <div class="when"><?= e(platform_label($l['platform'] ?? '')) ?><?php if (!empty($l['sent_at'])): ?> · <?= e(app_date_from_iso($l['sent_at'])) ?><?php endif; ?></div>
    </div>
    <span class="chip <?= $chip[0] ?>"><?= $chip[1] ?></span>
  </a>
<?php endforeach; ?>
<?php if (!$labels): ?><div class="empty-m">Nenhuma etiqueta encontrada.</div><?php endif; ?>
<?php m_foot('etiquetas'); ?>
