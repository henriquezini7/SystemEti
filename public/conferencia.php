<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
store_scan_sync_all_reports();

$summary  = store_scan_summary();
$total    = (int)($summary['total'] ?? 0);
$sent     = (int)($summary['sent'] ?? 0);
$pending  = (int)($summary['pending'] ?? 0);
$returned = (int)($summary['returned'] ?? 0);
$sentToday = (int)($summary['sent_today'] ?? 0);
$pct = $total > 0 ? (int)round($sent / $total * 100) : 0;

// Furos: códigos bipados que NÃO estavam cadastrados (saiu algo fora do controle).
$unknownEvents = array_values(array_filter(store_scan_events(3000), function ($e) {
    return ($e['status'] ?? '') === 'unknown';
}));
$unknownCount = count($unknownEvents);

// Faltam sair (pendentes).
$pendentes = store_labels_filtered('pending', '', 3000);

$semFuro = ($unknownCount === 0 && $pending === 0);
render_header('Conferência', $user);
?>
<div class="report-hero no-print">
    <div>
        <span class="badge big">Controle de saída — sem furo</span>
        <h2>Conferência de etiquetas</h2>
        <p>Tudo que <b>entrou</b> (PDF) tem que <b>sair</b> (bipagem). Aqui você vê o que falta e qualquer furo.</p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-primary" href="scan.php">Bipar envio</a>
        <a class="btn btn-light" href="labels.php?status=pending">Ver pendentes</a>
        <a class="btn btn-light" href="conferencia_export.php">Exportar Excel</a>
        <a class="btn btn-light" href="javascript:window.print()">Imprimir</a>
    </div>
</div>

<div class="grid cards-grid">
    <div class="metric-card soft-blue"><span>🏷️</span><small>Esperadas (entraram)</small><strong><?= $total ?></strong></div>
    <div class="metric-card soft-green"><span>✅</span><small>Enviadas (saíram)</small><strong><?= $sent ?></strong></div>
    <div class="metric-card soft-orange"><span>⏳</span><small>Faltam sair</small><strong><?= $pending ?></strong></div>
    <div class="metric-card soft-purple"><span>↩️</span><small>Devolvidas</small><strong><?= $returned ?></strong></div>
</div>

<div class="panel-card">
    <div class="card-head"><div><h2><?= $pct ?>% conferido</h2><p><?= $sent ?> de <?= $total ?> etiquetas já saíram · <?= $sentToday ?> bipadas hoje.</p></div></div>
    <div style="background:#eef1f6;border-radius:10px;height:22px;overflow:hidden;border:1px solid #e2e6ee">
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#16a34a':'#2563eb' ?>;transition:width .3s"></div>
    </div>
    <?php if ($semFuro): ?>
        <div class="alert alert-success mt-18"><strong>✅ Sem furo.</strong> Nada pendente e nada bipado fora do controle. Tudo que entrou, saiu.</div>
    <?php else: ?>
        <?php if ($pending > 0): ?><div class="alert alert-warning mt-18"><strong>⏳ Faltam <?= $pending ?> etiqueta(s) sair.</strong> Veja a lista abaixo.</div><?php endif; ?>
        <?php if ($unknownCount > 0): ?><div class="alert alert-danger"><strong>🚨 <?= $unknownCount ?> bipagem(ns) de código NÃO cadastrado.</strong> Algo foi bipado sem ter entrado por PDF — confira se não é furo.</div><?php endif; ?>
    <?php endif; ?>
</div>

<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head"><div><h2>Faltam sair (<?= count($pendentes) ?>)</h2><p>Etiquetas que entraram e ainda não foram bipadas.</p></div></div>
        <div class="table-wrap compact-table">
            <table>
                <thead><tr><th>Rastreio</th><th>Cliente</th><th>Produto</th><th>Origem</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($pendentes, 0, 500) as $l): ?>
                    <tr>
                        <td><strong><?= e(($l['tracking_code'] ?? '') ?: ($l['key'] ?? '-')) ?></strong></td>
                        <td><?= e(($l['recipient'] ?? '') ?: '-') ?></td>
                        <td><?= nl2br(e(store_label_products_text($l))) ?></td>
                        <td><?= e(platform_label($l['platform'] ?? '')) ?><br><small>#<?= (int)($l['report_id'] ?? 0) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pendentes): ?><tr><td colspan="4" class="empty">Nada pendente — tudo saiu! 🎉</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="card-head"><div><h2>Furos / Alertas (<?= $unknownCount ?>)</h2><p>Bipagens de código que não estava em nenhum PDF.</p></div></div>
        <div class="table-wrap compact-table">
            <table>
                <thead><tr><th>Código bipado</th><th>Quando</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($unknownEvents, 0, 200) as $ev): ?>
                    <tr>
                        <td><strong><?= e($ev['code'] ?? '-') ?></strong></td>
                        <td><small><?= e(app_date_from_iso($ev['created_at'] ?? date('c'))) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$unknownEvents): ?><tr><td colspan="2" class="empty">Nenhum furo: nada bipado fora do controle.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php render_footer(); ?>
