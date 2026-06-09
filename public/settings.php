<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';
$user = require_login();
$config = include __DIR__ . '/../app/config.php';
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';
    if ($action === 'reset' && ($_POST['confirm'] ?? '') === 'ZERAR') {
        try {
            store_reset_all();
            $message = 'Dados zerados. O sistema está limpo para produção.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'reset') {
        $error = 'Para zerar, digite ZERAR no campo de confirmação.';
    } else {
        $name = trim($_POST['name'] ?? $user['name']);
        $email = trim($_POST['email'] ?? $user['email']);
        $password = trim($_POST['password'] ?? '');
        try {
            if ($name === '' || $email === '') { throw new RuntimeException('Nome e e-mail são obrigatórios.'); }
            store_update_user($user['id'], $name, $email, $password);
            $message = 'Configurações salvas.';
            $user = auth_user();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
render_header('Configurações', $user);
?>
<div class="grid two-cols">
    <div class="panel-card">
        <div class="card-head"><div><h2>Usuário administrador</h2><p>Troque os dados do primeiro acesso.</p></div></div>
        <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Nome</label>
            <input class="input" name="name" value="<?= e($user['name']) ?>" required>
            <label>E-mail</label>
            <input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required>
            <label>Nova senha</label>
            <input class="input" type="password" name="password" placeholder="Deixe vazio para manter">
            <button class="btn btn-primary" type="submit">Salvar</button>
        </form>
    </div>
    <div class="panel-card">
        <div class="card-head"><div><h2>Status técnico</h2><p>Dependências necessárias para ler PDFs.</p></div></div>
        <div class="status-list">
            <div><strong>Banco de dados</strong><span>✅ não usa MySQL</span></div>
            <div><strong>Armazenamento</strong><span>JSON local</span></div>
            <div><strong>pdftotext</strong><span><?= is_executable($config['pdftotext_bin']) ? '✅ instalado' : '⚠️ não encontrado' ?></span></div>
            <div><strong>pdfinfo</strong><span><?= is_executable($config['pdfinfo_bin']) ? '✅ instalado' : '⚠️ não encontrado' ?></span></div>
            <div><strong>OCR (Tesseract)</strong><span><?= is_executable($config['tesseract_bin'] ?? '') ? '✅ instalado' : '⚠️ não encontrado' ?></span></div>
            <div><strong>Limite upload</strong><span><?= (int)$config['upload_max_mb'] ?>MB</span></div>
        </div>
        <div class="notice-box">Lê PDF de texto E de imagem (OCR automático para etiquetas Mercado Livre/Shopee). Bipagem por leitor USB ou câmera (código de barras + QR).</div>
    </div>
</div>

<div class="panel-card mt-18">
    <div class="card-head"><div><h2>Zerar dados para produção</h2><p>Apaga todos os relatórios, etiquetas e histórico de bipagem. Mantém seu login. Use depois dos testes.</p></div></div>
    <?php $sum = store_scan_summary(); ?>
    <p>Hoje há <b><?= (int)$sum['total'] ?></b> etiquetas registradas e <b><?= (int)$sum['sent'] ?></b> bipadas. Para apagar tudo, digite <b>ZERAR</b> e confirme.</p>
    <form method="post" class="form-stack" onsubmit="return confirm('Apagar TODOS os dados (relatórios, etiquetas, bipagens)? Não tem volta.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <label>Digite ZERAR para confirmar</label>
        <input class="input" name="confirm" placeholder="ZERAR" autocomplete="off">
        <button class="btn btn-danger" type="submit">Zerar todos os dados</button>
    </form>
</div>
<?php render_footer(); ?>
