<?php
declare(strict_types=1);

/**
 * 安全与高级设置（需登录）。
 *
 * 从「站点设置」拆出来。那一页原本把标题、描述、浏览、安全、上传限制、
 * 时区全堆在一起，日常只想改个站点标题也要在一屏里找半天。
 *
 * 拆分依据是**是否需要经常改**：这里全是设一次就不再动的项，
 * 而且有几项改错会让站点无法登录（强制 HTTPS、基础路径），
 * 单独成页也便于在这里给出更充分的警告。
 *
 * 写入逻辑与「站点设置」完全共用 src/settings.php，不存在两套实现。
 */

require __DIR__ . '/../src/bootstrap.php';

if (!auth_is_logged_in()) {
    header('Location: ' . url('/login.php'), true, 302);
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$cfgData = settings_load();
$saved   = isset($_GET['saved']);
$errors  = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_from_request())) {
        log_event('warning', 'csrf_failed', ['uri' => '/settings-advanced.php']);
        $errors[] = fail_message();
    } else {
        // 这一页只提交部分字段，其余字段必须原样保留。
        // settings_validate() 以现有配置为基准合并，因此未提交的键不会丢失。
        $input = $_POST;
        unset($input['csrf_token']);

        // 先校验（需要读 _form_keys），再清掉标记。
        $res = settings_validate($input, $cfgData);
        unset($input[SETTINGS_FORM_KEYS_FIELD]);
        if (!$res['ok']) {
            $errors = $res['errors'];
        } elseif (!settings_save($res['cfg'])) {
            $errors[] = '无法写入 data/config.php，请检查目录权限。';
            log_event('error', 'settings_save_failed');
        } else {
            log_event('info', 'settings_saved', ['keys' => implode(',', array_keys($input))]);
            header('Location: ' . url('/settings-advanced.php?saved=1'), true, 302);
            exit;
        }
    }
}

$val = static function (string $key, $default = '') use ($cfgData) {
    $v = $cfgData[$key] ?? $default;
    return is_scalar($v) ? $v : $default;
};

$pageTitle   = '安全与高级';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel">
    <h1>安全与高级</h1>
    <p class="muted">
        这些设置一般设一次就不再改动。改错可能让站点无法访问，
        因此单独成页，并在每一项下方说明后果。
    </p>

    <?php if ($saved): ?>
        <p class="alert alert-ok" role="status">设置已保存。</p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <p class="alert" role="alert"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/settings-advanced.php')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <?php // 同 settings.php：声明本页负责的键，避免清掉其他页面的复选框。 ?>
        <input type="hidden" name="<?= e(SETTINGS_FORM_KEYS_FIELD) ?>"
               value="force_https,login_max_attempts,login_lockout_secs,max_file_bytes,thumb_max_edge,strip_metadata,base_path,timezone">

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

        <label class="field">
            <span>登录失败上限（次）</span>
            <input type="number" name="login_max_attempts" min="1" max="100"
                   value="<?= (int)$val('login_max_attempts', 5) ?>">
            <small class="hint">同一 IP 连续失败达到此数量后暂时锁定。</small>
        </label>

        <label class="field">
            <span>登录锁定时长（秒）</span>
            <input type="number" name="login_lockout_secs" min="60" max="86400"
                   value="<?= (int)$val('login_lockout_secs', 900) ?>">
        </label>

        <h2 class="settings-group">上传与图片</h2>

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
            <small class="hint">
                只影响之后上传的新图片；0 表示不生成缩略图。
                改动后可用<a href="<?= e(url('/maintenance.php')) ?>">维护工具</a>为已有图片重建。
            </small>
        </label>

        <label class="field field-check">
            <input type="checkbox" name="strip_metadata" value="1"
                   <?= !empty($val('strip_metadata', true)) ? 'checked' : '' ?>>
            <span>上传时移除照片中的隐私信息（推荐）</span>
            <small class="hint">
                手机拍摄的照片通常带有 <strong>GPS 坐标</strong>、拍摄时间、设备型号。
                这些信息会随原图一起公开。开启后会在上传时移除它们。
                <br>
                实现方式是<strong>只删除元数据段，不重新压缩图片</strong> ——
                画面像素保持逐字节不变。仅对 JPEG / PNG / WebP 生效，GIF 不受影响。
            </small>
        </label>

        <h2 class="settings-group">部署</h2>
        <p class="muted">改错会导致站点无法打开，除非确有需要否则请保持默认。</p>

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

        <button type="submit" class="btn btn-primary">保存设置</button>
    </form>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>