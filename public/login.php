<?php
declare(strict_types=1);

/**
 * 管理员登录（§9 §10 §28）。无用户名，仅密码。
 */

require __DIR__ . '/../src/bootstrap.php';

// 已登录直接进后台
if (auth_is_logged_in()) {
    header('Location: ' . url('/admin.php'), true, 302);
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_from_request())) {
        log_event('warning', 'csrf_failed', ['uri' => '/login.php']);
        $error = fail_message();
    } else {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $result = auth_login($password);
        if ($result['ok']) {
            header('Location: ' . url('/admin.php'), true, 302);
            exit;
        }
        $error = $result['message'];
    }
}

$siteName = (string)cfg()['site_name'];
$pageTitle   = '登录';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="auth">
    <div class="auth-brand">
        <span class="auth-mark" aria-hidden="true"></span>
        <span class="auth-name"><?= e($siteName) ?></span>
    </div>

    <div class="panel auth-panel">
        <h1 class="auth-title">管理员登录</h1>
        <p class="auth-hint">本站仅有一个管理员账户。</p>

        <?php if ($error !== ''): ?>
            <p class="alert" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/login.php')) ?>" autocomplete="off">
            <?= csrf_field() ?>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" required autofocus
                       maxlength="200" autocomplete="current-password"
                       placeholder="请输入管理员密码">
            </label>
            <button type="submit" class="btn btn-primary btn-block">登录</button>
        </form>
    </div>

    <p class="auth-back"><a href="<?= e(url('/')) ?>">返回首页</a></p>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
