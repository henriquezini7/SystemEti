<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/stock.php';
$user = require_login();
$g = $_GET['g'] ?? 'day';
if (!in_array($g, ['day', 'week', 'month'], true)) { $g = 'day'; }
$rows = stock_entries_period($g, 120);
$totQty = 0; $totCount = 0;
foreach ($rows as $r) { $totQty += (int)$r['qty']; $totCount += (int)$r['count']; }
render_header('Relatório de estoque', $user);
?>
<div class="report-hero">
    <div><span class="badge big">Entradas de produtos</span><h2>Relatório de entradas — <?= $g==='day'?'Diário':($g==='week'?'Semanal':'Mensal') ?></h2><p>Quanto entrou no estoque por período (com base na data/hora das entradas).</p></div>
    <div class="hero-actions">
        <a class="btn <?= $g==='day'?'btn-primary':'btn-light' ?>" href="relatorio_estoque.php?g=day">Diário</a>
        <a class="btn <?= $g==='week'?'btn-primary':'btn-light' ?>" href="relatorio_estoque.php?g=week">Semanal</a>
        <a class="btn <?= $g==='month'?'btn-primary':'btn-light' ?>" href="relatorio_estoque.php?g=month">Mensal</a>
        <a class="btn btn-light" href="estoque_export.php">Exportar Excel</a>
    </div>
</div>
<div class="grid cards-grid">
    <div class="metric-card soft-green"><span>📥</span><small>Total entrado</small><strong><?= moneyless_number($totQty) ?></strong></div>
    <div class="metric-card soft-blue"><span>🧾</span><small>Lançamentos</small><strong><?= moneyless_number($totCount) ?></strong></div>
</div>
<div class="panel-card">
    <div class="card-head"><div><h2>Por <?= $g==='day'?'dia':($g==='week'?'semana':'mês') ?></h2><p>Mais recentes primeiro.</p></div></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Período</th><th>Lançamentos</th><th>Quantidade entrada</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><strong><?= e($r['label']) ?></strong></td><td><?= (int)$r['count'] ?></td><td><strong><?= (int)$r['qty'] ?></strong></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="3" class="empty">Nenhuma entrada registrada ainda.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
