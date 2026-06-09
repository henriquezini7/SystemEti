<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_deduplicate_existing_reports();
$totals = store_totals();
$today = app_date('Y-m-d');
$todaySummary = store_day_summary($today);
$latest = store_reports(8);
$topProducts = store_top_products(8);
store_scan_sync_all_reports();
$scanSummary = store_scan_summary();
render_header('Dashboard', $user);
?>
<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>📄</span><small>PDFs lidos</small><strong><?= moneyless_number($totals['reports']) ?></strong></div>
    <div class="metric-card soft-green"><span>🏷️</span><small>Etiquetas totais</small><strong><?= moneyless_number($totals['labels']) ?></strong></div>
    <div class="metric-card soft-orange"><span>🧾</span><small>Pedidos totais</small><strong><?= moneyless_number($totals['orders']) ?></strong></div>
    <div class="metric-card soft-purple"><span>📦</span><small>Unidades totais</small><strong><?= moneyless_number($totals['units']) ?></strong></div>
</div>

<div class="panel-card today-strip">
    <div>
        <h2>Resumo de hoje</h2>
        <p><?= e(br_date_from_key($today)) ?> • soma automática de todos os PDFs adicionados hoje.</p>
    </div>
    <div class="mini-metrics inline">
        <div><small>PDFs</small><strong><?= (int)$todaySummary['reports_count'] ?></strong></div>
        <div><small>Etiquetas</small><strong><?= (int)$todaySummary['labels'] ?></strong></div>
        <div><small>Pedidos</small><strong><?= (int)$todaySummary['orders'] ?></strong></div>
        <div><small>Unidades</small><strong><?= (int)$todaySummary['units'] ?></strong></div>
    </div>
    <div class="hero-actions">
        <a class="btn btn-primary" href="daily_report.php?date=<?= e($today) ?>">Abrir relatório do dia</a>
        <a class="btn btn-light" href="separation.php?date=<?= e($today) ?>">Separação</a>
        <a class="btn btn-light" href="export_txt.php?separation=1&date=<?= e($today) ?>">TXT separação</a>
    </div>
</div>

<div class="panel-card today-strip">
    <div>
        <h2>Controle por bipagem</h2>
        <p>Todas as etiquetas importadas dos PDFs entram como pendentes até serem bipadas no despacho.</p>
    </div>
    <div class="mini-metrics inline">
        <div><small>Registradas</small><strong><?= (int)$scanSummary['total'] ?></strong></div>
        <div><small>Pendentes</small><strong><?= (int)$scanSummary['pending'] ?></strong></div>
        <div><small>Enviadas</small><strong><?= (int)$scanSummary['sent'] ?></strong></div>
        <div><small>Devolvidas</small><strong><?= (int)$scanSummary['returned'] ?></strong></div>
    </div>
    <div class="hero-actions">
        <a class="btn btn-primary" href="scan.php">Bipar envio</a>
        <a class="btn btn-light" href="scan.php?mode=return">Bipar devolução</a>
        <a class="btn btn-light" href="labels.php?status=pending">Ver pendentes</a>
    </div>
</div>

<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head">
            <div><h2>Últimos relatórios</h2><p>Arquivos processados recentemente.</p></div>
            <a class="btn btn-light" href="upload.php">Novo PDF</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Arquivo</th><th>Plataforma</th><th>Etiquetas</th><th>Unidades</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($latest as $report): ?>
                    <tr>
                        <td><?= e($report['original_filename']) ?><br><small><?= e(app_date_from_iso($report['created_at'])) ?></small></td>
                        <td><span class="badge"><?= e(platform_label($report['platform'])) ?></span></td>
                        <td><?= (int)$report['total_labels'] ?></td>
                        <td><strong><?= (int)$report['total_units'] ?></strong></td>
                        <td><a class="link" href="report.php?id=<?= (int)$report['id'] ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$latest): ?><tr><td colspan="5" class="empty">Nenhum PDF processado ainda.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="card-head">
            <div><h2>Produtos mais enviados</h2><p>Total geral por produto: pedidos e unidades.</p></div>
        </div>
        <div class="product-list">
            <?php foreach ($topProducts as $p): ?>
                <div class="product-row">
                    <div><strong><?= e($p['product_name']) ?></strong><?php if ($p['sku']): ?><small>SKU: <?= e($p['sku']) ?></small><?php endif; ?></div>
                    <span><?= (int)($p['orders_count'] ?? 0) ?> ped.<br><small><?= (int)$p['quantity'] ?> un.</small></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$topProducts): ?><div class="empty">Os produtos aparecerão aqui após o primeiro PDF.</div><?php endif; ?>
        </div>
    </div>
</div>
<?php render_footer(); ?>
