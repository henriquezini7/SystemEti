<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/parser.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$config = include __DIR__ . '/../app/config.php';
@set_time_limit(0);
@ini_set('memory_limit', '2048M');
@ini_set('upload_max_filesize', ((int)($config['upload_max_mb'] ?? 300)) . 'M');
@ini_set('post_max_size', (((int)($config['upload_max_mb'] ?? 300)) + 20) . 'M');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Envie um PDF válido.');
        }
        $file = $_FILES['pdf'];
        $maxBytes = ((int)$config['upload_max_mb']) * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException('PDF acima do limite de ' . (int)$config['upload_max_mb'] . 'MB.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new RuntimeException('O arquivo precisa estar em PDF.');
        }

        store_init();
        store_deduplicate_existing_reports();
        $fileHash = strtolower((string)@hash_file('sha256', $file['tmp_name']));
        if ($fileHash !== '') {
            $duplicate = store_find_report_by_file_hash($fileHash);
            if ($duplicate) {
                redirect('report.php?id=' . (int)$duplicate['id'] . '&duplicate=1');
            }
        }
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $uploadDir = storage_path('uploads');
        $textDir = storage_path('text');
        ensure_dir($uploadDir);
        ensure_dir($textDir);
        $pdfPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $pdfPath)) {
            throw new RuntimeException('Falha ao salvar PDF no servidor. Confira permissão da pasta storage.');
        }
        $textPath = $textDir . DIRECTORY_SEPARATOR . preg_replace('/\.pdf$/', '.txt', $safeName);
        $parser = new PdfLabelParser($config);
        $result = $parser->process($pdfPath, $textPath);
        $result['_file_hash'] = $fileHash ?: strtolower((string)@hash_file('sha256', $pdfPath));
        $result['_file_size'] = (int)($file['size'] ?? @filesize($pdfPath));

        $reportId = store_save_report(
            $user['id'],
            $file['name'],
            $safeName,
            $result,
            'storage/text/' . basename($textPath)
        );
        redirect('report.php?id=' . $reportId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('Enviar PDF', $user);
?>
<div class="grid two-cols upload-layout">
    <div class="panel-card upload-card">
        <div class="card-head">
            <div><h2>Enviar PDF de etiquetas</h2><p>Mercado Livre, Shopee, Jadlog/DANFE e modelos mistos. O modo inteligente testa vários leitores e escolhe o resultado mais completo.</p></div>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="upload-form" id="uploadForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label class="dropzone" id="dropzone">
                <input type="file" name="pdf" accept="application/pdf,.pdf" required>
                <div class="drop-icon">📄</div>
                <strong>Arraste o PDF aqui ou clique para selecionar</strong>
                <span>Limite: <?= (int)$config['upload_max_mb'] ?>MB. Suporta PDFs grandes; recomendado PDF com texto selecionável.</span>
            </label>
            <div class="progress-box" id="progressBox" hidden>
                <div class="progress-top"><span id="progressText">Processando PDF...</span><b id="progressPercent">0%</b></div>
                <div class="progress-line"><i id="progressBar"></i></div>
                <small>Extraindo texto, lendo etiquetas, agrupando produtos e somando unidades.</small>
            </div>
            <button class="btn btn-primary btn-full" type="submit">Ler PDF agora</button>
        </form>
    </div>
    <div class="panel-card info-card">
        <h2>O que o painel lê?</h2>
        <div class="feature-list">
            <div><span>🏷️</span><strong>Etiquetas</strong><small>Conta rastreios, SHP/pedido e páginas de etiqueta.</small></div>
            <div><span>📦</span><strong>Produtos</strong><small>Lê produto, SKU, venda, pack e quantidade quando o PDF contém lista de itens.</small></div>
            <div><span>📊</span><strong>Relatório</strong><small>Soma unidades por produto, cria relatório do dia e baixa TXT limpo ou CSV/Excel.</small></div>
            <div><span>🛒</span><strong>Modo inteligente</strong><small>Testa Mercado Livre, Shopee, Jadlog/DANFE, DACE e leitura genérica antes de salvar o relatório. Depois registra todas as etiquetas para bipagem.</small></div>
        </div>
        <div class="notice-box">Esta versão não usa MySQL/MariaDB. Os relatórios e as etiquetas registradas ficam salvos em JSON. Depois do upload, cada etiqueta entra como pendente para conferência por bipagem.</div>
    </div>
</div>
<?php render_footer(); ?>
