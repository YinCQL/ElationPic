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
            <span>底栏右侧文字（可选）</span>
            <input type="text" name="footer_note" maxlength="120"
                   value="<?= e((string)$val('footer_note')) ?>"
                   placeholder="Powered by ElationPic">
            <small class="hint">
                显示在页面底部右下角。可以放<strong>备案号</strong>、版权声明或联系方式。
                <br>
                按纯文本显示（不会解析 HTML）。留空则显示默认文案。
            </small>
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

        <label class="field field-check">
            <input type="checkbox" name="public_gallery" value="1"
                   <?= !empty($val('public_gallery', true)) ? 'checked' : '' ?>>
            <span>公开首页（任何人都能浏览图片列表）</span>
            <small class="hint">
                关闭后访客看不到图片列表，只显示一个「本站未公开」的提示页。
                <br>
                <strong>已发出的直链不受影响</strong> —— 图片由 Web 服务器直接返回，
                不经过本站程序，因此把图片贴到论坛或聊天里仍然能正常打开。
                你登录后照常可以看到列表。
            </small>
        </label>

        <label class="field">
            <span>每页显示图片数</span>
            <input type="number" name="per_page" min="1" max="120"
                   value="<?= (int)$val('per_page', 20) ?>">
            <small class="hint">1 ~ 120。</small>
        </label>

        <button type="submit" class="btn btn-primary">保存设置</button>
    </form>

    <p class="muted settings-note">
        上传上限、缩略图、登录限制等在<a href="<?= e(url('/settings-advanced.php')) ?>">安全与高级</a>页。
    </p>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
