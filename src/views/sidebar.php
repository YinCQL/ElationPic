<?php
declare(strict_types=1);
/**
 * 后台侧边栏。
 *
 * 只在登录后的管理页面输出（由调用方通过 $isAdminPage 决定）。
 *
 * 为什么要有侧边栏：后台原本是三张很长的单页，找一项功能要在页面里来回滚。
 * 侧边栏把入口固定在一处，页面本身也因此可以拆小。
 *
 * @var bool $isAdminPage  由页面在引入 header 前设置
 * @var bool $loggedIn     由 header 计算
 */

// 未登录、或不是后台页面，就不输出侧边栏。
// 公开首页不需要侧边栏 —— 那里没有可切换的管理入口。
if (empty($isAdminPage) || empty($loggedIn)) {
    return;
}

// 当前脚本名，用于高亮。服务端可信值，不需要额外参数。
$__cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// 侧边栏条目。分三组：内容 / 备份 / 系统。
// 分组区分了「每天都会点」与「偶尔才用」，降低误入维护功能的机会。
$__groups = [
    '内容' => [
        ['admin.php', '图片', '上传、浏览与管理'],
    ],
    '系统' => [
        ['settings.php', '站点设置', '标题、浏览'],
        ['settings-advanced.php', '安全与高级', '密码、上传、登录'],
        ['password.php', '修改密码', '管理员密码'],
    ],
    '备份' => [
        ['backup.php', '备份与恢复', '导出与导入'],
        ['maintenance.php', '维护工具', '存储、缩略图、自检'],
    ],
];
?>
<nav class="sidebar" aria-label="后台导航">
    <?php foreach ($__groups as $__groupName => $__items): ?>
        <div class="sidebar-group">
            <span class="sidebar-title"><?= e($__groupName) ?></span>
            <?php foreach ($__items as $__it): ?>
                <?php
                $__file = $__it[0];
                $__label = $__it[1];
                $__desc = $__it[2];
                $__active = ($__file === $__cur);
                ?>
                <a class="sidebar-link<?= $__active ? ' is-active' : '' ?>"
                   href="<?= e(url('/' . $__file)) ?>"
                   <?= $__active ? 'aria-current="page"' : '' ?>>
                    <span class="sidebar-label"><?= e($__label) ?></span>
                    <span class="sidebar-desc"><?= e($__desc) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</nav>