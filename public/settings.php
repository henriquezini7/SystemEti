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
            <div><strong>Limite upload</strong><span><?= (int)$config['upload_max_mb'] ?>MB</span></div>
            <div><strong>Porta sugerida</strong><span>3037</span></div>
        </div>
        <div class="notice-box">Para PDF escaneado/imagem, será preciso OCR. Este pacote lê PDFs com texto selecionável, como os PDFs comuns de etiqueta/lista de separação.</div>
    </div>
</div>
<?php render_footer(); ?>
