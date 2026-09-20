<?php
declare(strict_types=1);

/**
 * 修改管理员密码（需登录）。
 *
 * 为什么提供：此前修改密码的唯一方式是**删除 data/config.php 重新安装**，
 * 这会连带清空数据库引用，属于明显不合理的操作路径。
 * 修改自己的密码是基本运维需求，不属于 §9 所禁止的"多用户/注册"。
 *
 * 安全设计：
 *   - 必须提供**当前密码**。仅有会话不足以改密码 ——
 *     否则一次会话劫持即可永久接管站点。
 *   - 新密码需输入两次，且有最小长度要求。
 *   - 复用登录限速器，避免该页面成为爆破当前密码的入口。
 *   - 成功后轮换会话 ID，并清理限速状态。
 */

require __DIR__ . '/../src/bootstrap.php';

if (!auth_is_logged_in()) {
    header('Location: ' . url('/login.php'), true, 302);
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$errors = [];
$done   = isset($_GET['done']);

const PW_MIN_LEN = 8;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_from_request())) {
        log_event('warning', 'csrf_failed', ['uri' => '/password.php']);
        $errors[] = fail_message();
    } else {
        $cur   = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        $new1  = isset($_POST['new_password'])     && is_string($_POST['new_password'])     ? $_POST['new_password']     : '';
        $new2  = isset($_POST['new_password2'])    && is_string($_POST['new_password2'])    ? $_POST['new_password2']    : '';

        // 复用登录限速：连续猜错当前密码同样会被锁定
        $locked = auth_is_locked();
        if ($locked > 0) {
            $errors[] = '尝试次数过多，请 ' . (int)ceil($locked / 60) . ' 分钟后再试。';
        } else {
            $cfgData = settings_load();
            $hash    = $cfgData['admin_password_hash'] ?? '';

            if (!is_string($hash) || $hash === '' || !password_verify($cur, $hash)) {
                auth_record_failure();
                usleep(300000);   // 与登录保持一致的固定延迟
                log_event('warning', 'password_change_failed', ['ip' => client_ip()]);
                $errors[] = '当前密码不正确。';
            } elseif (strlen($new1) < PW_MIN_LEN) {
                $errors[] = '新密码至少需要 ' . PW_MIN_LEN . ' 个字符。';
            } elseif ($new1 !== $new2) {
                $errors[] = '两次输入的新密码不一致。';
            } elseif (hash_equals($new1, $cur)) {
                $errors[] = '新密码不能与当前密码相同。';
            } else {
                $newHash = password_hash($new1, PASSWORD_DEFAULT);
                if (!is_string($newHash) || $newHash === '') {
                    $errors[] = '密码哈希生成失败。';
                } else {
                    $cfgData['admin_password_hash'] = $newHash;
                    if (!settings_save($cfgData)) {
                        $errors[] = '无法写入 data/config.php，请检查目录权限。';
                        log_event('error', 'password_save_failed');
                    } else {
                        // 凭证已更换：轮换会话 ID 并清除限速状态
                        session_regenerate_id(true);
                        auth_clear_failures();
                        log_event('info', 'password_changed', ['ip' => client_ip()]);
                        header('Location: ' . url('/password.php?done=1'), true, 302);
                        exit;
                    }
                }
            }
        }
    }
}

$pageTitle   = '修改密码';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel panel-form">
    <h1>修改管理员密码</h1>

    <?php if ($done): ?>
        <p class="alert alert-ok" role="status">密码已更新。请妥善保存新密码。</p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <p class="alert" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/password.php')) ?>" autocomplete="off">
        <?= csrf_field() ?>

        <label class="field">
            <span>当前密码</span>
            <input type="password" name="current_password" required
                   maxlength="200" autocomplete="current-password">
        </label>

        <label class="field">
            <span>新密码</span>
            <input type="password" name="new_password" required
                   minlength="<?= PW_MIN_LEN ?>" maxlength="200" autocomplete="new-password">
            <small class="hint">至少 <?= PW_MIN_LEN ?> 个字符。</small>
        </label>

        <label class="field">
            <span>确认新密码</span>
            <input type="password" name="new_password2" required
                   minlength="<?= PW_MIN_LEN ?>" maxlength="200" autocomplete="new-password">
        </label>

        <button type="submit" class="btn btn-primary">更新密码</button>
    </form>

    <p class="muted"><a href="<?= e(url('/settings.php')) ?>">返回站点设置</a></p>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
