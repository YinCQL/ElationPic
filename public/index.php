<?php
declare(strict_types=1);

/**
 * 首页（公开 Gallery，§5 §6 §34 §35）。
 * 未登录用户可浏览与查看直链，但看不到任何管理功能。
 */

require __DIR__ . '/../src/bootstrap.php';

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
