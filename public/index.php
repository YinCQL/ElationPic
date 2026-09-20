<?php
declare(strict_types=1);

/**
 * 首页（公开 Gallery，§5 §6 §34 §35）。
 * 未登录用户可浏览与查看直链，但看不到任何管理功能。
 */

require __DIR__ . '/../src/bootstrap.php';

// ---- 首页公开开关 ----
//
// 关闭后**访客**看不到图片列表，但：
//   - 直链（/uploads/xxx.jpg）**仍然有效** —— 图片由 Web 服务器直接返回，
//     根本不经过这个文件，因此不受开关影响。这正是"关闭展示但不影响外链"的关键。
//   - 已登录的管理员照常浏览，否则自己也看不到自己的图。
//   - 登录页不受影响，否则无法登录后台去重新打开。
//
// 这里刻意**不返回 404**：访客看到明确的"本站不公开"比一个错误页更好，
// 至少知道站点是活的、只是不对外展示。
if (empty(cfg()['public_gallery']) && !auth_is_logged_in()) {
    if (!headers_sent()) {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }
    $pageTitle   = '未公开';
    $isAdminPage = false;
    require APP_ROOT . '/src/views/header.php';
    ?>
    <section class="hero">
        <h1><?= e((string)cfg()['site_name']) ?></h1>
    </section>

    <div class="panel panel-narrow">
        <h2>本站未公开</h2>
        <p class="muted">
            站点管理员关闭了图片列表的公开访问。
        </p>
        <p class="muted">
            如果你持有某张图片的直链，它<strong>仍然可以正常打开</strong>。
        </p>
        <p>
            <a class="btn" href="<?= e(url('/login.php')) ?>">管理员登录</a>
        </p>
    </div>
    <?php
    require APP_ROOT . '/src/views/footer.php';
    exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// 排序：只接受白名单里的键，非法值一律退回默认。
// 真正的 SQL 片段由 images_sort_sql() 查表得到，用户输入不会进入 SQL。
$sortOptions = images_sort_options();
$sort = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : 'new';
if (!isset($sortOptions[$sort])) {
    $sort = 'new';
}

$data = images_page($page, (int)cfg()['per_page'], $sort);

// 当前页的"需要注意"标记（文件丢失 / 原始名重复）。
// 只对显示的这几十条做检查，不做整目录扫描。
$flags = images_page_flags($data['rows']);

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$pageTitle   = '图片';
$isAdminPage = false;
require APP_ROOT . '/src/views/header.php';
?>

<?php $__desc = trim((string)cfg()['site_description']); ?>
<section class="hero">
    <div class="hero-head">
        <div>
            <h1><?= e((string)cfg()['site_name']) ?></h1>
            <?php if ($__desc !== ''): ?>
                <p class="muted"><?= $__desc !== '' ? e($__desc) : '' ?></p>
            <?php endif; ?>
        </div>

        <?php if ($data['total'] > 1): ?>
            <?php // 排序用 GET 表单：无需 JS 也能工作，且结果可收藏/分享 ?>
            <form class="sortbar" method="get" action="<?= e(url('/')) ?>">
                <label class="sortbar-label" for="sort">排序</label>
                <select name="sort" id="sort" onchange="this.form.submit()">
                    <?php foreach ($sortOptions as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= $k === $sort ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-sm">应用</button></noscript>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($data['total'] === 0): ?>
    <p class="empty">还没有图片。</p>
<?php else: ?>
    <div class="grid">
        <?php $__i = 0; foreach ($data['rows'] as $img): ?>
            <?php
            $editable   = false;
            $selectable = false;
            // 第一张通常是首屏可见的图：不懒加载、提高抓取优先级
            $isFirst    = ($__i === 0);
            $__i++;
            require APP_ROOT . '/src/views/card.php';
            ?>
        <?php endforeach; ?>
    </div>
    <?php
    // 翻页时保留排序条件
    $page = $data['page'];
    $pages = $data['pages'];
    $pagerQuery = $sort !== 'new' ? ('sort=' . rawurlencode($sort) . '&') : '';
    require APP_ROOT . '/src/views/pagination.php';
    ?>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden>
    <button type="button" class="lightbox-close" aria-label="关闭">×</button>
    <img src="" alt="" id="lightbox-img">
</div>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
