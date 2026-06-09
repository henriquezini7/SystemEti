<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$date = $_GET['date'] ?? app_date('Y-m-d');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : app_date('Y-m-d');
$reports = store_reports_by_date($date);
$summary = store_day_summary($date);
$orders = array_slice($summary['orders_list'], 0, 800);
render_header('Relatório do dia', $user);
?>
<div class="report-hero">
    <div>
        <span class="badge big">Relatório diário</span>
        <h2><?= e(br_date_from_key($date)) ?></h2>
        <p>Total de todos os PDFs adicionados neste dia, agrupado por produto.</p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-light" href="reports.php">Voltar</a>
        <a class="btn btn-primary" href="separation.php?date=<?= e($date) ?>">Separação</a>
        <a class="btn btn-light" href="export_txt.php?separation=1&date=<?= e($date) ?>">TXT separação</a>
        <a class="btn btn-primary" href="export_txt.php?date=<?= e($date) ?>">TXT completo</a>
        <a class="btn btn-light" href="export_csv.php?date=<?= e($date) ?>">CSV/Excel do dia</a>
        <form method="post" action="clear_reports.php" class="inline-form" onsubmit="return confirm('Apagar todos os relatórios deste dia? Esta ação não volta.');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="scope" value="day">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <button class="btn btn-danger" type="submit">Apagar dia</button>
        </form>
    </div>
</div>

<form method="get" class="panel-card day-filter">
    <label>Escolher data</label>
    <input class="input" type="date" name="date" value="<?= e($date) ?>">
    <button class="btn btn-primary" type="submit">Ver dia</button>
</form>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>📄</span><small>PDFs no dia</small><strong><?= (int)$summary['reports_count'] ?></strong></div>
    <div class="metric-card soft-green"><span>🏷️</span><small>Etiquetas no dia</small><strong><?= (int)$summary['labels'] ?></strong></div>
    <div class="metric-card soft-orange"><span>🧾</span><small>Pedidos no dia</small><strong><?= (int)$summary['orders'] ?></strong></div>
    <div class="metric-card soft-purple"><span>📦</span><small>Unidades no dia</small><strong><?= (int)$summary['units'] ?></strong></div>
</div>

<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head"><div><h2>Produtos do dia</h2><p>Total de pedidos e unidades por produto para separar na bancada.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Produto</th><th>SKU</th><th>Total de pedidos</th><th>Total de unidades</th></tr></thead>
                <tbody>
                <?php foreach ($summary['items'] as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e(($item['sku'] ?? '') ?: '-') ?></td>
                        <td><strong><?= (int)($item['orders_count'] ?? 0) ?> pedidos</strong></td>
                        <td><strong><?= (int)($item['quantity'] ?? 0) ?> unidades</strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($summary['items'])): ?><tr><td colspan="4" class="empty">Nenhum produto encontrado nesta data.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="card-head"><div><h2>PDFs adicionados no dia</h2><p>Arquivos que formam este relatório diário.</p></div></div>
        <div class="table-wrap compact-table">
            <table>
                <thead><tr><th>#</th><th>Arquivo</th><th>Unidades</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= e($r['original_filename']) ?><br><small><?= e(platform_label($r['platform'])) ?> • <?= e(app_date_from_iso($r['created_at'])) ?></small></td>
                        <td><strong><?= (int)$r['total_units'] ?></strong></td>
                        <td class="actions action-stack">
                            <a class="link" href="report.php?id=<?= (int)$r['id'] ?>">Abrir</a>
                            <form method="post" action="delete_report.php" onsubmit="return confirm('Apagar este relatório? Esta ação não volta.');">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="return" value="daily_report.php?date=<?= e($date) ?>">
                                <button class="link danger-link" type="submit">Apagar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?><tr><td colspan="4" class="empty">Nenhum PDF neste dia.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head"><div><h2>Detalhamento do dia</h2><p>Primeiros 800 itens somados no relatório diário.</p></div></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Rel.</th><th>Rastreio</th><th>Venda</th><th>Destinatário</th><th>Produto</th><th>Qtd</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= (int)($o['_report_id'] ?? 0) ?></td>
                    <td><?= e(($o['tracking_code'] ?? '') ?: '-') ?></td>
                    <td><?= e(($o['sale_id'] ?? '') ?: '-') ?></td>
                    <td><?= e(($o['recipient'] ?? '') ?: '-') ?></td>
                    <td><?= e($o['product_name'] ?? 'Produto não identificado') ?><?php if (!empty($o['sku'])): ?><br><small>SKU: <?= e($o['sku']) ?></small><?php endif; ?></td>
                    <td><strong><?= (int)($o['quantity'] ?? 1) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="6" class="empty">Nenhum detalhe nesta data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
