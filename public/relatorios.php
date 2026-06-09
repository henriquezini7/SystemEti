<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_scan_sync_all_reports();

$g = $_GET['g'] ?? 'day';
if (!in_array($g, ['day', 'week', 'month'], true)) { $g = 'day'; }
$titulos = ['day' => 'Diário', 'week' => 'Semanal', 'month' => 'Mensal'];
$rows = store_period_report($g, 120);

$tot = ['reports' => 0, 'labels' => 0, 'orders' => 0, 'units' => 0, 'sent' => 0, 'units_sent' => 0];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) { $tot[$k] += (int)($r[$k] ?? 0); }
}
render_header('Relatórios', $user);
?>
<div class="report-hero no-print">
    <div>
        <span class="badge big">Relatórios por período</span>
        <h2>Resumo <?= e($titulos[$g]) ?></h2>
        <p>Entrada (PDFs/etiquetas/pedidos/unidades) e saída (bipadas) agrupadas por <?= $g==='day'?'dia':($g==='week'?'semana':'mês') ?>.</p>
    </div>
    <div class="hero-actions">
        <a class="btn <?= $g==='day'?'btn-primary':'btn-light' ?>" href="relatorios.php?g=day">Diário</a>
        <a class="btn <?= $g==='week'?'btn-primary':'btn-light' ?>" href="relatorios.php?g=week">Semanal</a>
        <a class="btn <?= $g==='month'?'btn-primary':'btn-light' ?>" href="relatorios.php?g=month">Mensal</a>
        <a class="btn btn-light" href="javascript:window.print()">Imprimir</a>
    </div>
</div>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>📄</span><small>PDFs</small><strong><?= (int)$tot['reports'] ?></strong></div>
    <div class="metric-card soft-green"><span>🏷️</span><small>Etiquetas (entrada)</small><strong><?= (int)$tot['labels'] ?></strong></div>
    <div class="metric-card soft-orange"><span>🧾</span><small>Pedidos</small><strong><?= (int)$tot['orders'] ?></strong></div>
    <div class="metric-card soft-purple"><span>📦</span><small>Unidades</small><strong><?= (int)$tot['units'] ?></strong></div>
    <div class="metric-card soft-green"><span>✅</span><small>Bipadas (saíram)</small><strong><?= (int)$tot['sent'] ?></strong></div>
</div>

<div class="panel-card">
    <div class="card-head"><div><h2>Detalhe por <?= $g==='day'?'dia':($g==='week'?'semana':'mês') ?></h2><p>Mais recentes primeiro.</p></div></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Período</th><th>PDFs</th><th>Etiquetas</th><th>Pedidos</th><th>Unidades</th><th>Bipadas</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['label'] ?? $r['key']) ?></strong></td>
                    <td><?= (int)$r['reports'] ?></td>
                    <td><?= (int)$r['labels'] ?></td>
                    <td><?= (int)$r['orders'] ?></td>
                    <td><strong><?= (int)$r['units'] ?></strong></td>
                    <td><span class="status-chip sent"><?= (int)$r['sent'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhum dado ainda. Suba um PDF e bipe etiquetas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
