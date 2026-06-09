<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$id = (int)($_GET['id'] ?? 0);
$bundle = store_get_report_bundle($id);
if (!$bundle) { http_response_code(404); die('Relatório não encontrado.'); }
$report = $bundle['report'];
$items = store_separation_from_orders($bundle['orders'] ?? []);
$orders = array_slice($bundle['orders'], 0, 500);
$warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
$day = store_report_day($report);
$daySummary = store_day_summary($day);
$generalSummary = store_general_summary();
render_header('Relatório #' . $id, $user);
?>
<div class="report-hero">
    <div>
        <span class="badge big"><?= e(platform_label($report['platform'])) ?></span><?php if (store_report_is_duplicate($report)): ?><span class="badge big danger-badge">Duplicado ignorado</span><?php endif; ?>
        <h2><?= e($report['original_filename']) ?></h2>
        <p>Processado em <?= e(app_date_from_iso($report['created_at'])) ?> • relatório do dia <?= e(br_date_from_key($day)) ?></p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-light" href="reports.php">Voltar</a>
        <a class="btn btn-light" href="daily_report.php?date=<?= e($day) ?>">Relatório do dia</a>
        <a class="btn btn-light" href="separation.php?date=<?= e($day) ?>">Separação</a>
        <a class="btn btn-primary" href="export_txt.php?id=<?= (int)$report['id'] ?>">TXT limpo</a>
        <a class="btn btn-light" href="export_csv.php?id=<?= (int)$report['id'] ?>">CSV/Excel</a>
        <form method="post" action="delete_report.php" class="inline-form" onsubmit="return confirm('Apagar este relatório? Esta ação não volta.');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$report['id'] ?>">
            <input type="hidden" name="return" value="reports.php">
            <button class="btn btn-danger" type="submit">Apagar</button>
        </form>
    </div>
</div>

<?php if (!empty($_GET['duplicate'])): ?><div class="alert alert-warning"><strong>Arquivo já importado.</strong> Para não somar duas vezes, o sistema abriu o relatório original e não criou novo lançamento.</div><?php endif; ?>
<?php if (store_report_is_duplicate($report)): ?><div class="alert alert-warning"><strong>Relatório duplicado.</strong> Este lançamento antigo foi mantido para consulta, mas não entra nos totais do dia, geral, separação nem bipagem.</div><?php endif; ?>
<?php foreach ($warnings as $w): ?><div class="alert alert-warning"><?= e($w) ?></div><?php endforeach; ?>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>📄</span><small>Páginas deste PDF</small><strong><?= (int)$report['total_pages'] ?></strong></div>
    <div class="metric-card soft-green"><span>🏷️</span><small>Etiquetas deste PDF</small><strong><?= (int)$report['total_labels'] ?></strong></div>
    <div class="metric-card soft-orange"><span>🧾</span><small>Pedidos deste PDF</small><strong><?= (int)$report['total_orders'] ?></strong></div>
    <div class="metric-card soft-purple"><span>📦</span><small>Unidades deste PDF</small><strong><?= (int)$report['total_units'] ?></strong></div>
</div>

<div class="grid two-cols totals-split">
    <div class="panel-card summary-card">
        <div class="card-head"><div><h2>Total do dia</h2><p>Soma de todos os PDFs adicionados em <?= e(br_date_from_key($day)) ?>.</p></div></div>
        <div class="mini-metrics">
            <div><small>PDFs</small><strong><?= (int)$daySummary['reports_count'] ?></strong></div>
            <div><small>Etiquetas</small><strong><?= (int)$daySummary['labels'] ?></strong></div>
            <div><small>Pedidos</small><strong><?= (int)$daySummary['orders'] ?></strong></div>
            <div><small>Unidades</small><strong><?= (int)$daySummary['units'] ?></strong></div>
        </div>
    </div>
    <div class="panel-card summary-card">
        <div class="card-head"><div><h2>Total geral acumulado</h2><p>Soma de tudo que já foi adicionado no painel.</p></div></div>
        <div class="mini-metrics">
            <div><small>PDFs</small><strong><?= (int)$generalSummary['reports_count'] ?></strong></div>
            <div><small>Etiquetas</small><strong><?= (int)$generalSummary['labels'] ?></strong></div>
            <div><small>Pedidos</small><strong><?= (int)$generalSummary['orders'] ?></strong></div>
            <div><small>Unidades</small><strong><?= (int)$generalSummary['units'] ?></strong></div>
        </div>
    </div>
</div>

<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head"><div><h2>Resumo deste PDF</h2><p>Lista de separação somente deste arquivo.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Produto</th><th>SKU</th><th>Total de pedidos</th><th>Total de unidades</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e(($item['sku'] ?? '') ?: '-') ?></td>
                        <td><?= (int)($item['orders_count'] ?? 0) ?></td>
                        <td><strong><?= (int)($item['quantity'] ?? 0) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr><td colspan="4" class="empty">Nenhum produto identificado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="card-head"><div><h2>Resumo acumulado do dia</h2><p>Todos os PDFs do mesmo dia somados por produto.</p></div></div>
        <div class="table-wrap compact-table">
            <table>
                <thead><tr><th>Produto</th><th>SKU</th><th>Pedidos</th><th>Unidades</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($daySummary['items'], 0, 50) as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e(($item['sku'] ?? '') ?: '-') ?></td>
                        <td><?= (int)($item['orders_count'] ?? 0) ?></td>
                        <td><strong><?= (int)($item['quantity'] ?? 0) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($daySummary['items'])): ?><tr><td colspan="4" class="empty">Nenhum produto no dia.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head"><div><h2>Detalhamento deste PDF</h2><p>Primeiros 500 itens lidos. Para conferência rápida por rastreio, venda e destinatário.</p></div></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Rastreio</th><th>Venda</th><th>Destinatário</th><th>Remetente</th><th>Produto</th><th>Qtd</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?= e(($o['tracking_code'] ?? '') ?: '-') ?></td>
                    <td><?= e(($o['sale_id'] ?? '') ?: '-') ?></td>
                    <td><?= e(($o['recipient'] ?? '') ?: '-') ?><?php if (!empty($o['recipient_city']) || !empty($o['recipient_cep'])): ?><br><small><?= e(trim(($o['recipient_city'] ?? '') . ' ' . ($o['recipient_cep'] ?? ''))) ?></small><?php endif; ?><?php if (!empty($o['recipient_address'])): ?><br><small><?= e($o['recipient_address']) ?></small><?php endif; ?></td>
                    <td><?= e(($o['sender_name'] ?? '') ?: '-') ?><?php if (!empty($o['sender_city'])): ?><br><small><?= e($o['sender_city']) ?></small><?php endif; ?></td>
                    <td><?= e($o['product_name'] ?? 'Produto não identificado') ?><?php if (!empty($o['sku'])): ?><br><small>SKU: <?= e($o['sku']) ?></small><?php endif; ?><?php if (!empty($o['nf']) || !empty($o['service']) || !empty($o['weight']) || !empty($o['dace_number']) || !empty($o['item_value'])): ?><br><small><?= e(trim('NF: ' . ($o['nf'] ?? '-') . ' • ' . ($o['service'] ?? '-') . ' • ' . ($o['weight'] ?? '-') . ' • DACE: ' . ($o['dace_number'] ?? '-') . ' • Valor: ' . ($o['item_value'] ?? '-'))) ?></small><?php endif; ?></td>
                    <td><strong><?= (int)($o['quantity'] ?? 1) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="6" class="empty">Nenhum item detalhado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
