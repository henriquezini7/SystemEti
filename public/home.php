<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mobile.php';
require_once __DIR__ . '/../app/store.php';
$user = auth_user();
if (!$user) { redirect('entrar.php'); }
store_scan_sync_all_reports();
$s = store_scan_summary();
$today = app_date('Y-m-d');

// saídas por hora (hoje)
$hours = array_fill(0, 24, 0);
foreach (store_scan_events(5000) as $ev) {
    if (($ev['status'] ?? '') === 'sent' && report_day_key($ev['created_at'] ?? '') === $today) {
        try { $h = (int)(new DateTime($ev['created_at']))->setTimezone(new DateTimeZone(app_timezone()))->format('G'); $hours[$h]++; } catch (Throwable $e) {}
    }
}
$maxH = max(1, max($hours));

$right = '<a class="ic" href="ajustes.php">&#128276;</a>';
m_head('Dashboard', ['right' => $right]);
?>
<p class="m-hello">Olá, <b><?= e($user['name']) ?></b><br>Bem-vindo de volta!</p>

<div class="m-card">
  <h2>Resumo de hoje (<?= e(app_date('d/m/Y')) ?>)</h2>
  <div class="m-metrics">
    <div class="m-metric"><div class="n"><?= (int)$s['sent_today'] ?></div><div class="l">Saídas</div></div>
    <div class="m-metric g"><div class="n"><?= (int)$s['sent'] ?></div><div class="l">Enviadas</div></div>
    <div class="m-metric o"><div class="n"><?= (int)$s['pending'] ?></div><div class="l">Pendências</div></div>
    <div class="m-metric"><div class="n"><?= (int)$s['units_pending'] ?></div><div class="l">Itens</div></div>
  </div>
</div>

<div class="m-card">
  <h2>Saídas por hora</h2>
  <div class="bars">
    <?php foreach ($hours as $h => $v): ?><div class="bar" style="height:<?= (int)round($v / $maxH * 100) ?>%" title="<?= $h ?>h: <?= $v ?>"></div><?php endforeach; ?>
  </div>
  <div class="bars-x"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
</div>

<div class="section-title">Atalhos rápidos</div>
<div class="m-shortcuts">
  <a class="m-shortcut" href="bipar.php"><span class="ic">&#128229;</span>Nova Saída</a>
  <a class="m-shortcut" href="etiquetas.php"><span class="ic">&#127991;&#65039;</span>Etiquetas</a>
  <a class="m-shortcut" href="relatorios_app.php"><span class="ic">&#128202;</span>Relatórios</a>
  <a class="m-shortcut" href="conferencia.php"><span class="ic">&#9989;</span>Conferência</a>
</div>
<?php m_foot('home'); ?>
