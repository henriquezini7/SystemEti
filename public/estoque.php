<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/stock.php';
$user = require_login();
store_scan_sync_all_reports();
$q = trim((string)($_GET['q'] ?? ''));
$rows = stock_balance($q);
$tot = stock_totals();
render_header('Estoque', $user);
?>
<div class="report-hero">
    <div>
        <span class="badge big">Depósito Principal</span>
        <h2>Controle de estoque</h2>
        <p>Entrou (estoque) · Reservado (pedidos pendentes) · Enviado (baixa por bipagem) · Saldo · Disponível.</p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-primary" href="estoque_entrada.php">Entrada de estoque</a>
        <a class="btn btn-light" href="relatorio_estoque.php">Relatório de entradas</a>
        <a class="btn btn-light" href="estoque_export.php">Exportar Excel</a>
    </div>
</div>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>📦</span><small>Produtos</small><strong><?= (int)$tot['produtos'] ?></strong></div>
    <div class="metric-card soft-green"><span>📥</span><small>Entrou (total)</small><strong><?= moneyless_number($tot['in']) ?></strong></div>
    <div class="metric-card soft-orange"><span>🔒</span><small>Reservado</small><strong><?= moneyless_number($tot['reserved']) ?></strong></div>
    <div class="metric-card soft-purple"><span>📊</span><small>Saldo físico</small><strong><?= moneyless_number($tot['balance']) ?></strong></div>
</div>

<?php if ((int)$tot['negativos'] > 0): ?>
<div class="alert alert-danger"><strong>🚨 <?= (int)$tot['negativos'] ?> produto(s) com disponível negativo.</strong> Vendeu/reservou mais do que tem em estoque — confira a entrada desses produtos.</div>
<?php endif; ?>

<div class="panel-card">
    <form class="filters-bar" method="get">
        <div class="form-stack grow"><label>Buscar produto / SKU</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Nome do produto ou SKU..."></div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn btn-light" href="estoque.php">Limpar</a>
    </form>
    <div class="table-wrap compact-table mt-18">
        <table>
            <thead><tr><th>Produto</th><th>SKU</th><th>Entrou</th><th>Reservado</th><th>Enviado</th><th>Saldo</th><th>Disponível</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['product_name']) ?></strong></td>
                    <td><?= e($r['sku'] ?: '-') ?></td>
                    <td><?= (int)$r['in'] ?></td>
                    <td><?= (int)$r['reserved'] ?></td>
                    <td><?= (int)$r['sent'] ?></td>
                    <td><strong><?= (int)$r['balance'] ?></strong></td>
                    <td><span class="status-chip <?= $r['available'] < 0 ? 'unknown' : ($r['available'] == 0 ? 'pending' : 'sent') ?>"><?= (int)$r['available'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty">Sem produtos no estoque ainda. Cadastre o estoque inicial.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
