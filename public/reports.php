<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$reports = store_reports(200);
$days = store_daily_index(30);
render_header('Relatórios', $user);
?>
<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head">
            <div><h2>Relatórios do dia</h2><p>Use essa área para não confundir PDFs separados. Aqui soma tudo por data.</p></div>
            <a class="btn btn-primary" href="daily_report.php">Hoje</a>
        </div>
        <div class="table-wrap compact-table">
            <table>
                <thead><tr><th>Data</th><th>PDFs</th><th>Etiquetas</th><th>Pedidos</th><th>Unidades</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($days as $d): ?>
                    <tr>
                        <td><strong><?= e(br_date_from_key($d['date'])) ?></strong></td>
                        <td><?= (int)$d['reports'] ?></td>
                        <td><?= (int)$d['labels'] ?></td>
                        <td><?= (int)$d['orders'] ?></td>
                        <td><strong><?= (int)$d['units'] ?></strong></td>
                        <td class="actions action-stack">
                            <a class="link" href="daily_report.php?date=<?= e($d['date']) ?>">Abrir</a>
                            <form method="post" action="clear_reports.php" onsubmit="return confirm('Apagar todos os relatórios deste dia? Esta ação não volta.');">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="scope" value="day">
                                <input type="hidden" name="date" value="<?= e($d['date']) ?>">
                                <button class="link danger-link" type="submit">Apagar dia</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$days): ?><tr><td colspan="6" class="empty">Nenhum dia com relatório ainda.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card">
        <div class="card-head">
            <div><h2>Ações rápidas</h2><p>Baixe relatório limpo, limpe dias antigos ou apague tudo quando quiser recomeçar.</p></div>
        </div>
        <div class="feature-list">
            <div><span>🧾</span><strong>TXT limpo</strong><small>Relatório organizado, sem ponto e vírgula, bom para copiar e enviar.</small></div>
            <div><span>📊</span><strong>CSV/Excel</strong><small>Arquivo separado por seções: totais, produtos e detalhes.</small></div>
            <div><span>📅</span><strong>Total do dia</strong><small>Soma todos os PDFs enviados na mesma data.</small></div>
            <div><span>🗑️</span><strong>Limpeza</strong><small>Agora dá para apagar um PDF, apagar um dia inteiro ou zerar todos os relatórios.</small></div>
        </div>
        <form method="post" action="clear_reports.php" class="danger-zone" onsubmit="return confirm('Apagar TODOS os relatórios do painel? Esta ação não volta.');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="scope" value="all">
            <button class="btn btn-danger" type="submit">Apagar todos os relatórios</button>
            <small>Remove histórico, PDFs enviados e textos extraídos. Não mexe no login nem nas configurações.</small>
        </form>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head">
        <div><h2>Histórico de PDFs</h2><p>Últimos 200 PDFs processados individualmente.</p></div>
        <a class="btn btn-primary" href="upload.php">Enviar novo PDF</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Arquivo</th><th>Plataforma</th><th>Etiquetas</th><th>Pedidos</th><th>Unidades</th><th>Data</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= e($r['original_filename']) ?></td>
                    <td><span class="badge"><?= e(platform_label($r['platform'])) ?></span></td>
                    <td><?= (int)$r['total_labels'] ?></td>
                    <td><?= (int)$r['total_orders'] ?></td>
                    <td><strong><?= (int)$r['total_units'] ?></strong></td>
                    <td><?= e(app_date_from_iso($r['created_at'])) ?></td>
                    <td class="actions action-stack">
                        <a class="link" href="report.php?id=<?= (int)$r['id'] ?>">Abrir</a>
                        <form method="post" action="delete_report.php" onsubmit="return confirm('Apagar este relatório? Esta ação não volta.');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="return" value="reports.php">
                            <button class="link danger-link" type="submit">Apagar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?><tr><td colspan="8" class="empty">Nenhum relatório encontrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
