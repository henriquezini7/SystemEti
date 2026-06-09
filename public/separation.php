<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$scope = $_GET['scope'] ?? 'day';
$date = $_GET['date'] ?? app_date('Y-m-d');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
if ($scope === 'all') {
    $summary = store_general_summary();
    $titleLabel = 'Total geral acumulado';
    $subtitle = 'Soma de todos os PDFs já adicionados no painel.';
    $exportQuery = 'scope=all';
} else {
    $scope = 'day';
    $summary = store_day_summary($date);
    $titleLabel = 'Separação do dia ' . br_date_from_key($date);
    $subtitle = 'Soma de todos os PDFs adicionados nesta data.';
    $exportQuery = 'date=' . urlencode($date);
}
$items = $summary['items'] ?? [];
render_header('Separação de produtos', $user);
?>
<div class="report-hero no-print">
    <div>
        <span class="badge big">Lista de separação</span>
        <h2><?= e($titleLabel) ?></h2>
        <p><?= e($subtitle) ?></p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-light" href="daily_report.php?date=<?= e($date) ?>">Relatório do dia</a>
        <a class="btn btn-primary" href="export_txt.php?separation=1&<?= e($exportQuery) ?>">TXT separação</a>
        <a class="btn btn-light" href="export_csv.php?separation=1&<?= e($exportQuery) ?>">CSV separação</a>
        <button class="btn btn-light" type="button" onclick="window.print()">Imprimir</button>
    </div>
</div>

<form method="get" class="panel-card day-filter no-print">
    <label>Modo</label>
    <select class="input" name="scope" onchange="this.form.submit()">
        <option value="day" <?= $scope === 'day' ? 'selected' : '' ?>>Somente um dia</option>
        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Total geral</option>
    </select>
    <label>Data</label>
    <input class="input" type="date" name="date" value="<?= e($date) ?>">
    <button class="btn btn-primary" type="submit">Gerar separação</button>
</form>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>🧴</span><small>Produtos diferentes</small><strong><?= (int)count($items) ?></strong></div>
    <div class="metric-card soft-green"><span>🧾</span><small>Total de pedidos</small><strong><?= (int)$summary['orders'] ?></strong></div>
    <div class="metric-card soft-purple"><span>📦</span><small>Total de unidades</small><strong><?= (int)$summary['units'] ?></strong></div>
    <div class="metric-card soft-orange"><span>📄</span><small>PDFs somados</small><strong><?= (int)$summary['reports_count'] ?></strong></div>
</div>

<div class="panel-card mt-18 print-card">
    <div class="card-head">
        <div>
            <h2>Produtos para separar</h2>
            <p>Use esta lista na bancada: produto, total de pedidos e total de unidades.</p>
        </div>
    </div>
    <div class="table-wrap separation-table">
        <table>
            <thead><tr><th>#</th><th>Produto</th><th>SKU</th><th>Total de pedidos</th><th>Total de unidades</th><th>Separado</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?= (int)($i + 1) ?></td>
                    <td class="product-name-cell"><strong><?= e($item['product_name']) ?></strong><?php if (!empty($item['recipients'])): ?><br><small>Ex.: <?= e(implode(', ', array_slice($item['recipients'], 0, 3))) ?></small><?php endif; ?></td>
                    <td><?= e(($item['sku'] ?? '') ?: '-') ?></td>
                    <td><strong><?= (int)($item['orders_count'] ?? 0) ?> pedidos</strong></td>
                    <td><strong><?= (int)($item['quantity'] ?? 0) ?> unidades</strong></td>
                    <td class="check-cell">□</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6" class="empty">Nenhum produto identificado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
