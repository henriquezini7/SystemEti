<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
store_scan_sync_all_reports();
$g = $_GET['g'] ?? 'day';
if (!in_array($g, ['day', 'week', 'month'], true)) { $g = 'day'; }
$rows = store_period_report($g, 120);
$tot = ['reports' => 0, 'labels' => 0, 'orders' => 0, 'units' => 0, 'sent' => 0];
foreach ($rows as $r) { foreach ($tot as $k => $_) { $tot[$k] += (int)($r[$k] ?? 0); } }
$right = '<a class="ic" href="conferencia_export.php">&#11015;</a>';
m_head('Relatórios', ['right' => $right]);
?>
<div class="m-tabs">
  <a class="<?= $g==='day'?'active':'' ?>" href="relatorios_app.php?g=day">Diário</a>
  <a class="<?= $g==='week'?'active':'' ?>" href="relatorios_app.php?g=week">Semanal</a>
  <a class="<?= $g==='month'?'active':'' ?>" href="relatorios_app.php?g=month">Mensal</a>
</div>

<div class="m-card">
  <h2>Total no período</h2>
  <div class="m-metrics">
    <div class="m-metric g"><div class="n"><?= (int)$tot['sent'] ?></div><div class="l">Saídas</div></div>
    <div class="m-metric"><div class="n"><?= (int)$tot['labels'] ?></div><div class="l">Etiquetas</div></div>
    <div class="m-metric"><div class="n"><?= (int)$tot['orders'] ?></div><div class="l">Pedidos</div></div>
    <div class="m-metric o"><div class="n"><?= (int)$tot['units'] ?></div><div class="l">Itens</div></div>
  </div>
</div>

<?php foreach ($rows as $r): ?>
  <div class="m-card" style="padding:14px 16px">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><b><?= e($r['label'] ?? $r['key']) ?></b><div class="when" style="color:var(--muted);font-size:12px"><?= (int)$r['reports'] ?> PDF · <?= (int)$r['orders'] ?> pedidos · <?= (int)$r['units'] ?> itens</div></div>
      <span class="chip saida"><?= (int)$r['sent'] ?> saídas</span>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="empty-m">Sem dados ainda. Suba um PDF e bipe etiquetas.</div><?php endif; ?>

<div class="section-title">Top Itens</div>
<div class="m-card" style="padding:6px 16px">
  <?php $top = store_products_control(10); foreach ($top as $p): ?>
    <div class="kv"><span class="k"><?= e(strlen($p['product_name'])>34?substr($p['product_name'],0,34).'…':$p['product_name']) ?></span><span class="v"><?= (int)$p['total'] ?></span></div>
  <?php endforeach; ?>
  <?php if (!$top): ?><div class="empty-m" style="padding:12px">Sem itens ainda.</div><?php endif; ?>
</div>

<a class="m-btn light" href="conferencia_export.php" style="margin-top:6px">&#11015; Exportar relatório (Excel)</a>
<a class="m-btn ghost" href="produtos.php" style="margin-top:10px">Ver controle de produtos</a>
<?php m_foot('relatorios'); ?>
