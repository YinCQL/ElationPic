<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var bool $isAdminPage */
$siteName = (string)cfg()['site_name'];
$loggedIn = auth_is_logged_in();
// $pageTitle / $isAdminPage 由调用页面提供（见 docs/DESIGN.md §5.4）
$isAdminPage = (bool)($isAdminPage ?? false);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// 公开首页允许被索引（§5「首页公开访问」）；后台与登录页禁止索引。
if ($isAdminPage): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>
<?php
// 主题脚本必须【同步】放在样式表之前：
//   - 同步：要在首次绘制前把 data-theme 写到 <html> 上，否则每次打开都会
//     先闪一下浅色再跳深色（defer/async 都做不到）。
//   - 放在 CSS 前：先确定主题，再解析样式，避免一次无用的样式计算。
//   - 独立文件而非内联：CSP 为 script-src 'self'，不允许内联脚本。
?>
<script src="<?= e(asset_url('/assets/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset_url('/assets/style.css')) ?>">
</head>
<?php
// 仅在已登录时下发 CSRF token。
// 原因：csrf_token() 会惰性写入 $_SESSION，若在公开首页也无条件下发，
// 则每个匿名访客（含爬虫）都会被迫创建一个 Session 文件，
// 既浪费磁盘也扩大 data/sessions/ 的填充面（§39 低资源占用）。
$csrfValue = $loggedIn ? csrf_token() : '';
?>
<body data-base="<?= e(rtrim((string)(cfg()['base_path'] ?? ''), '/')) ?>"<?= $csrfValue !== '' ? ' data-csrf="' . e($csrfValue) . '"' : '' ?>>
<?php
// 当前页面，用于给导航加上"选中"状态。取 SCRIPT_NAME 的文件名即可，
// 不需要额外参数（服务端可信值）。
$__cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$__nav = static function (string $file, string $active) use ($__cur): string {
    return $file === $__cur ? $active : '';
};
?>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text"><?= e($siteName) ?></span>
        </a>
        <nav class="nav">
            <?php
            // 主题切换：三态循环（跟随系统 -> 浅色 -> 深色）。
            // 图标与提示文字由 JS 根据当前状态更新；无 JS 时按钮不显示，
            // 页面仍按系统偏好渲染（CSS 的默认值），不会出现"点了没反应"。
            ?>
            <button type="button" class="theme-btn" id="theme-toggle"
                    title="切换主题" aria-label="切换主题" hidden>◐</button>
            <?php
            // 窄屏文案：<span class="lbl-long"> 与 <span class="lbl-short"> 同时输出，
            // 由 CSS 按屏宽决定显示哪一个（见 style.css 的 <=420px 媒体查询）。
            // 这比"用 ::after + font-size:0 覆盖文字"直白得多，也不依赖字号技巧。
            //
            // "首页"整体带 .nav-home：品牌本身就是首页入口，窄屏下省掉这一项，
            // 它恰好是导航里最宽的一项。
            //
            // aria-label 始终给出完整名称，避免屏幕阅读器只念到简称。
            ?>
            <a class="nav-home <?= $__nav('index.php', 'is-active') ?>" href="<?= e(url('/')) ?>">首页</a>
            <?php if ($loggedIn): ?>
                <?php
                // 顶栏只留一个「后台」入口。设置、备份、维护等具体页面
                // 一律从侧边栏进入 —— 两处都放会让顶栏拥挤，也会出现
                // 「同一个目标有两个入口、高亮状态还不一致」的问题。
                ?>
                <a class="<?= $__nav('admin.php', 'is-active') ?>" href="<?= e(url('/admin.php')) ?>"
                   aria-label="管理后台"><span class="lbl-long">管理后台</span><span class="lbl-short">后台</span></a>
                <form class="inline" method="post" action="<?= e(url('/logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="link-btn">退出</button>
                </form>
            <?php else: ?>
                <a class="<?= $__nav('login.php', 'is-active') ?>" href="<?= e(url('/login.php')) ?>">登录</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php
// 后台页面使用「侧边栏 + 内容」的两栏布局。
//
// 公开首页不加侧边栏 —— 那里没有可切换的管理入口，加了反而是噪音。
// 布局本身由 CSS 的 .admin-shell 承担；窄屏下侧边栏会变成顶部横向滚动条，
// 与现有导航的处理方式一致（见 style.css 的 <=720px 媒体查询）。
$__hasSidebar = !empty($isAdminPage) && !empty($loggedIn);
?>
<?php if ($__hasSidebar): ?>
<div class="wrap admin-shell">
    <?php require APP_ROOT . '/src/views/sidebar.php'; ?>
    <main class="admin-main">
<?php else: ?>
<main class="wrap">
<?php endif; ?>
