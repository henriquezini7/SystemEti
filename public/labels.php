<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_scan_sync_all_reports();
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$labels = store_labels_filtered($status, $q, 1000);
$summary = store_scan_summary();
render_header('Etiquetas Registradas', $user);
?>
<div class="report-hero no-print">
    <div><span class="badge big">Registro obrigatório</span><h2>Todas as etiquetas dos PDFs</h2><p>Use esta tela para conferir o que já entrou no sistema, o que foi enviado e o que voltou.</p></div>
    <div class="hero-actions"><a class="btn btn-primary" href="scan.php">Bipar envio</a><a class="btn btn-light" href="scan.php?mode=return">Bipar devolução</a></div>
</div>
<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>🏷️</span><small>Total registrado</small><strong><?= (int)$summary['total'] ?></strong></div>
    <div class="metric-card soft-orange"><span>⏳</span><small>Pendentes</small><strong><?= (int)$summary['pending'] ?></strong></div>
    <div class="metric-card soft-green"><span>✅</span><small>Enviadas</small><strong><?= (int)$summary['sent'] ?></strong></div>
    <div class="metric-card soft-purple"><span>↩️</span><small>Devolvidas</small><strong><?= (int)$summary['returned'] ?></strong></div>
</div>
<div class="panel-card">
    <form class="filters-bar" method="get">
        <div class="form-stack"><label>Status</label><select class="input" name="status"><option value="">Todos</option><option value="pending" <?= $status==='pending'?'selected':'' ?>>Pendentes</option><option value="sent" <?= $status==='sent'?'selected':'' ?>>Enviadas</option><option value="returned" <?= $status==='returned'?'selected':'' ?>>Devolvidas</option></select></div>
        <div class="form-stack grow"><label>Buscar</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Rastreio, venda, cliente, produto, SKU..."></div>
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn btn-light" href="labels.php">Limpar</a>
    </form>
    <div class="table-wrap compact-table mt-18">
        <table>
            <thead><tr><th>Status</th><th>Etiqueta/Rastreio</th><th>Cliente</th><th>Produtos</th><th>Origem</th><th>Bipagem</th></tr></thead>
            <tbody>
            <?php foreach ($labels as $l): $st=$l['status'] ?? 'pending'; ?>
                <tr>
                    <td><span class="status-chip <?= e($st) ?>"><?= e(store_label_status_label($st)) ?></span></td>
                    <td><strong><?= e(($l['tracking_code'] ?? '') ?: ($l['key'] ?? '-')) ?></strong><br><small><?= e(($l['shipment_id'] ?? '') ?: '') ?> <?= e(($l['sale_id'] ?? '') ?: '') ?> <?= e(($l['pack_id'] ?? '') ?: '') ?></small></td>
                    <td><?= e(($l['recipient'] ?? '') ?: '-') ?><br><small><?= e(trim(($l['recipient_address'] ?? '') . ' ' . ($l['recipient_city'] ?? '') . ' ' . ($l['recipient_cep'] ?? ''))) ?></small></td>
                    <td><?= nl2br(e(store_label_products_text($l))) ?><br><small>Total: <?= (int)($l['units_total'] ?? 1) ?> un.</small></td>
                    <td><?= e(platform_label($l['platform'] ?? '')) ?><br><small>Relatório #<?= (int)($l['report_id'] ?? 0) ?> · <?= e($l['report_file'] ?? '') ?></small></td>
                    <td><?php if (!empty($l['sent_at'])): ?><small>Enviado: <?= e(app_date_from_iso($l['sent_at'])) ?></small><br><?php endif; ?><?php if (!empty($l['returned_at'])): ?><small>Devolvido: <?= e(app_date_from_iso($l['returned_at'])) ?></small><br><?php endif; ?><small>Bipes: <?= (int)($l['scan_count'] ?? 0) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$labels): ?><tr><td colspan="6" class="empty">Nenhuma etiqueta encontrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
