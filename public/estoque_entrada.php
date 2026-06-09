<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/stock.php';
$user = require_login();
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'bulk') {
            $n = stock_initial_bulk($_POST['bulk'] ?? '', $user['id'] ?? 0);
            $message = $n > 0 ? "Estoque inicial: $n produto(s) registrado(s)." : 'Nenhuma linha válida. Use o formato "Produto ; quantidade".';
        } elseif ($action === 'single') {
            $name = trim($_POST['product'] ?? '');
            $qty = (int)($_POST['qty'] ?? 0);
            if ($name === '' || $qty === 0) { throw new RuntimeException('Informe o produto e uma quantidade diferente de zero.'); }
            stock_add_entry($name, trim($_POST['sku'] ?? ''), $qty, 'entrada', $_POST['note'] ?? '', $user['id'] ?? 0);
            $message = 'Entrada registrada com data e hora.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$recent = stock_recent_entries(40);
render_header('Entrada de estoque', $user);
?>
<div class="report-hero">
    <div><span class="badge big">Entrada de produtos</span><h2>Dar entrada no estoque</h2><p>Toda entrada registra <b>dia, data e hora</b>. Use o lote para o estoque inicial.</p></div>
    <div class="hero-actions"><a class="btn btn-light" href="estoque.php">Ver estoque</a></div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head"><div><h2>Estoque inicial (em lote)</h2><p>Uma linha por produto: <code>Produto ; quantidade</code></p></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="bulk">
            <textarea class="input" name="bulk" rows="10" placeholder="Perfume Lattafa Yara 100ml ; 50&#10;Perfume Asad Elixir ; 20&#10;Armaf Club de Nuit ; 35"></textarea>
            <button class="btn btn-primary" type="submit">Registrar estoque inicial</button>
        </form>
    </div>
    <div class="panel-card">
        <div class="card-head"><div><h2>Reposição / entrada avulsa</h2><p>Adicionar quantidade a um produto.</p></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="single">
            <label>Produto</label><input class="input" name="product" placeholder="Nome do produto" required>
            <label>SKU (opcional)</label><input class="input" name="sku" placeholder="SKU">
            <label>Quantidade</label><input class="input" type="number" name="qty" value="1" required>
            <label>Observação (opcional)</label><input class="input" name="note" placeholder="Ex.: nota fiscal, fornecedor...">
            <button class="btn btn-primary" type="submit">Dar entrada</button>
        </form>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head"><div><h2>Últimas entradas</h2><p>Histórico com data e hora.</p></div></div>
    <div class="table-wrap compact-table">
        <table>
            <thead><tr><th>Data / hora</th><th>Produto</th><th>Tipo</th><th>Qtd</th><th>Obs.</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $e): ?>
                <tr>
                    <td><?= e(app_date_from_iso($e['datetime'] ?? date('c'), 'd/m/Y H:i:s')) ?></td>
                    <td><strong><?= e($e['product_name']) ?></strong><?php if (!empty($e['sku'])): ?><br><small>SKU: <?= e($e['sku']) ?></small><?php endif; ?></td>
                    <td><span class="badge"><?= e($e['type']) ?></span></td>
                    <td><strong><?= (int)$e['qty'] ?></strong></td>
                    <td><small><?= e($e['note'] ?? '') ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="5" class="empty">Nenhuma entrada ainda.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
