<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
store_scan_sync_all_reports();
$q = trim((string)($_GET['q'] ?? ''));
$all = store_products_control(2000);
if ($q !== '') {
    $qn = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $all = array_values(array_filter($all, function ($p) use ($qn) {
        $hay = mb_strtolower(($p['product_name'] ?? '') . ' ' . ($p['sku'] ?? ''), 'UTF-8');
        return strpos($hay, $qn) !== false;
    }));
}
$tot = store_products_totals();
m_head('Produtos', ['back' => 'home.php']);
?>
<div class="m-card">
  <h2>Controle de produtos</h2>
  <div class="m-metrics">
    <div class="m-metric"><div class="n"><?= (int)$tot['produtos'] ?></div><div class="l">Produtos</div></div>
    <div class="m-metric"><div class="n"><?= (int)$tot['total'] ?></div><div class="l">Itens</div></div>
    <div class="m-metric g"><div class="n"><?= (int)$tot['sent'] ?></div><div class="l">Saíram</div></div>
    <div class="m-metric o"><div class="n"><?= (int)$tot['pending'] ?></div><div class="l">Faltam</div></div>
  </div>
</div>

<form class="m-search" method="get">
  <span>&#128269;</span>
  <input name="q" value="<?= e($q) ?>" placeholder="Buscar produto ou SKU...">
</form>

<?php foreach ($all as $p): $total = max(1, (int)$p['total']); $pct = (int)round($p['sent'] / $total * 100); ?>
  <div class="m-card" style="padding:13px 15px">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
      <div style="min-width:0;flex:1">
        <div style="font-weight:700;font-size:14px"><?= e($p['product_name']) ?></div>
        <?php if (!empty($p['sku'])): ?><div style="color:var(--muted);font-size:11px">SKU <?= e($p['sku']) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-weight:800;color:var(--navy);font-size:18px"><?= (int)$p['total'] ?></div>
        <div style="color:var(--muted);font-size:10px">itens</div>
      </div>
    </div>
    <div style="background:#eef1f6;border-radius:8px;height:8px;overflow:hidden;margin:9px 0 6px">
      <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#16a34a':'#1d4ed8' ?>"></div>
    </div>
    <div style="display:flex;gap:10px;font-size:11px;color:var(--muted)">
      <span>✅ <?= (int)$p['sent'] ?> saíram</span>
      <span>⏳ <?= (int)$p['pending'] ?> faltam</span>
      <?php if ((int)$p['returned'] > 0): ?><span>↩️ <?= (int)$p['returned'] ?> devolv.</span><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$all): ?><div class="empty-m">Nenhum produto. Suba um PDF de etiquetas.</div><?php endif; ?>
<?php m_foot('home'); ?>
