<?php
declare(strict_types=1);

/**
 * 站点设置（需登录）。
 *
 * 让管理员在不手工编辑 data/config.php 的前提下修改站点标题、描述、
 * 固定网址与每页图片数等。写入逻辑统一在 src/settings.php，
 * 与安装向导共用同一份实现。
 */

require __DIR__ . '/../src/bootstrap.php';

// HTML 页面：未登录时跳转到登录页（区别于 API 的 JSON 403）
if (!auth_is_logged_in()) {
    header('Location: ' . url('/login.php'), true, 302);
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$cfgData  = settings_load();
$saved    = isset($_GET['saved']);
$errors   = [];
$notice   = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_from_request())) {
        log_event('warning', 'csrf_failed', ['uri' => '/settings.php']);
        $errors[] = fail_message();
    } else {
        $input = $_POST;
        unset($input['csrf_token']);

        $res = settings_validate($input, $cfgData);
        if (!$res['ok']) {
            $errors = $res['errors'];
        } elseif (!settings_save($res['cfg'])) {
            $errors[] = '无法写入 data/config.php，请检查目录权限。';
            log_event('error', 'settings_save_failed');
        } else {
            log_event('info', 'settings_saved', ['keys' => implode(',', array_keys($input))]);
            header('Location: ' . url('/settings.php?saved=1'), true, 302);
            exit;
        }
    }
}

$val = static function (string $key, $default = '') use ($cfgData) {
    $v = $cfgData[$key] ?? $default;
    return is_scalar($v) ? $v : $default;
};

$pageTitle   = '站点设置';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel">
    <h1>站点设置</h1>

    <?php if ($saved): ?>
        <p class="alert alert-ok" role="status">设置已保存。</p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <p class="alert" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/settings.php')) ?>" autocomplete="off">
        <?= csrf_field() ?>

        <h2 class="settings-group">基本信息</h2>

        <label class="field">
            <span>站点标题</span>
            <input type="text" name="site_name" required maxlength="60"
                   value="<?= e((string)$val('site_name', 'ElationPic')) ?>">
            <small class="hint">显示在浏览器标签页与页面顶部。</small>
        </label>

        <label class="field">
            <span>站点描述</span>
            <input type="text" name="site_description" maxlength="160"
                   value="<?= e((string)$val('site_description')) ?>">
            <small class="hint">显示在首页标题下方。</small>
        </label>

        <label class="field">
            <span>站点网址（可选）</span>
            <input type="text" name="site_url" maxlength="200"
                   placeholder="https://img.example.com"
                   value="<?= e((string)$val('site_url')) ?>">
            <small class="hint">
                用于生成「复制直链」的域名前缀。留空则自动按当前访问的主机推导。
                仅在绑定了固定域名时才需要填写。
            </small>
        </label>

        <h2 class="settings-group">浏览</h2>

        <label class="field">
            <span>每页显示图片数</span>
            <input type="number" name="per_page" min="1" max="120"
                   value="<?= (int)$val('per_page', 20) ?>">
            <small class="hint">1 ~ 120。</small>
        </label>

        <h2 class="settings-group">安全</h2>

        <label class="field">
            <span>强制 HTTPS</span>
            <select name="force_https">
                <option value="0"<?= ((int)$val('force_https', 0) === 0) ? ' selected' : '' ?>>关闭（本地 HTTP 调试用）</option>
                <option value="1"<?= ((int)$val('force_https', 0) === 1) ? ' selected' : '' ?>>开启（已配置 HTTPS 时）</option>
            </select>
            <small class="hint">
                开启后 Session Cookie 会带 Secure 标志。若站点还是纯 HTTP，
                开启它会导致<strong>无法登录</strong>。
            </small>
        </label>

        <div class="settings-advanced">
            <h2 class="settings-group">高级</h2>
            <p class="muted">一般不需要修改。改错可能导致站点异常。</p>

            <label class="field">
                <span>上传大小上限（字节）</span>
                <input type="number" name="max_file_bytes" min="1024" max="104857600"
                       value="<?= (int)$val('max_file_bytes', 10485760) ?>">
                <small class="hint">同时需保证 PHP 的 upload_max_filesize 与 post_max_size 不低于此值。</small>
            </label>

            <label class="field">
                <span>缩略图最长边（像素）</span>
                <input type="number" name="thumb_max_edge" min="0" max="4000"
                       value="<?= (int)$val('thumb_max_edge', 480) ?>">
                <small class="hint">只影响之后上传的新图片；0 表示不生成缩略图。</small>
            </label>

            <label class="field field-check">
                <input type="checkbox" name="strip_metadata" value="1"
                       <?= !empty($val('strip_metadata', true)) ? 'checked' : '' ?>>
                <span>上传时移除照片中的隐私信息（推荐）</span>
                <small class="hint">
                    手机拍摄的照片通常带有 <strong>GPS 坐标</strong>、拍摄时间、设备型号。
                    这些信息会随原图一起公开。开启后会上传时移除它们。
                    <br>
                    实现方式是<strong>只删除元数据段，不重新压缩图片</strong> ——
                    画面像素保持逐字节不变。仅对 JPEG / PNG / WebP 生效，GIF 不受影响。
                </small>
            </label>

            <label class="field">
                <span>登录失败上限（次）</span>
                <input type="number" name="login_max_attempts" min="1" max="100"
                       value="<?= (int)$val('login_max_attempts', 5) ?>">
            </label>

            <label class="field">
                <span>登录锁定时长（秒）</span>
                <input type="number" name="login_lockout_secs" min="60" max="86400"
                       value="<?= (int)$val('login_lockout_secs', 900) ?>">
            </label>

            <label class="field">
                <span>基础路径</span>
                <input type="text" name="base_path" maxlength="200"
                       value="<?= e((string)$val('base_path')) ?>">
                <small class="hint">
                    站点根指向 public/ 时留空即可（程序会自动校正）。
                    仅当以子目录方式部署时才需要填写，如 <code>/img/public</code>。
                </small>
            </label>

            <label class="field">
                <span>时区</span>
                <input type="text" name="timezone" maxlength="64"
                       value="<?= e((string)$val('timezone', 'Asia/Shanghai')) ?>">
                <small class="hint">PHP 时区标识符，如 Asia/Shanghai。</small>
            </label>
        </div>

        <button type="submit" class="btn btn-primary">保存设置</button>
    </form>
</section>

<section class="panel">
    <h2>修改管理员密码</h2>
    <p class="muted">修改后当前会话保持登录，其他设备上的会话不受影响。</p>
    <p><a class="btn" href="<?= e(url('/password.php')) ?>">前往修改密码</a></p>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
