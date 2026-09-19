<?php
declare(strict_types=1);

/**
 * 管理后台（§12 §22 §33）。必须登录。
 *
 * 包含：站点概况、上传区、搜索、批量删除、图片列表。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin();

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$q     = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
// 搜索词只用于展示与拼链接，长度做个上限避免超长 URL
if (mb_strlen($q, 'UTF-8') > 100) {
    $q = mb_substr($q, 0, 100, 'UTF-8');
}

$perPage = (int)cfg()['per_page'];
$data    = images_search($q, $page, $perPage);

// 当前页的"需要注意"标记（文件丢失 / 原始名重复）
$flags = images_page_flags($data['rows']);
$stats   = images_stats();

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$pageTitle   = '管理后台';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel">
    <h1>站点概况</h1>
    <div class="stats">
        <div class="stat">
            <span class="stat-num"><?= (int)$stats['count'] ?></span>
            <span class="stat-label">图片总数</span>
        </div>
        <div class="stat">
            <span class="stat-num"><?= e(format_bytes((int)$stats['bytes'])) ?></span>
            <span class="stat-label">原图占用</span>
        </div>
        <div class="stat">
            <span class="stat-num"><?= (int)$stats['thumbs'] ?></span>
            <span class="stat-label">已有缩略图</span>
        </div>
        <div class="stat">
            <span class="stat-num"><?= (int)$stats['no_thumb'] ?></span>
            <span class="stat-label">未生成缩略图</span>
        </div>
    </div>
</section>

<section class="panel">
    <h2>上传图片</h2>
    <p class="muted">
        支持 JPG / PNG / GIF / WebP，单文件最大
        <?= e(format_bytes((int)cfg()['max_file_bytes'])) ?>。
    </p>

    <?php // 把服务端的权威限制下发给前端，避免 JS 里写死常量后与 config.php 失配 ?>
    <div class="dropzone" id="dropzone" tabindex="0" role="button"
         data-max-bytes="<?= (int)cfg()['max_file_bytes'] ?>"
         data-allowed-types="image/jpeg,image/png,image/gif,image/webp"
         aria-label="点击或拖拽图片到此处上传">
        <p class="dz-title">点击选择图片，或将图片拖到此处</p>
        <p class="dz-hint muted">一次可选多张 · 也可直接按 <kbd>Ctrl</kbd>+<kbd>V</kbd> 粘贴截图</p>
        <input type="file" id="file-input" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
    </div>

    <div class="progress" id="progress" hidden>
        <div class="progress-bar" id="progress-bar"></div>
    </div>
    <ul class="upload-log" id="upload-log"></ul>
</section>

<section class="panel">
    <?php
    // #admin-list 始终存在（即使还没有图片），并包含计数与分页。
    // 上传成功后 app.js 刷新的是这个容器的整体内容——
    // 若只刷新 #admin-grid，则在"零图片"时该元素不存在，首张图片上传后不会显示；
    // 且计数与分页会停留在旧值。
    ?>
    <div id="admin-list"
         data-base-url="<?= e(rtrim((string)cfg()['base_path'], '/')) ?>">
        <div class="panel-head">
            <h2>图片（<?= (int)$data['total'] ?><?= $q !== '' ? ' · 搜索"' . e($q) . '"' : '' ?>）</h2>

            <form class="search" method="get" action="<?= e(url('/admin.php')) ?>" role="search">
                <input type="search" name="q" value="<?= e($q) ?>"
                       placeholder="按文件名搜索" maxlength="100" aria-label="按文件名搜索">
                <button type="submit" class="btn btn-sm">搜索</button>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-sm" href="<?= e(url('/admin.php')) ?>">清除</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($data['total'] === 0): ?>
            <p class="empty">
                <?= $q !== '' ? '没有匹配的图片。' : '还没有图片，上传第一张吧。' ?>
            </p>
        <?php else: ?>
            <?php // 操作条常驻显示（不使用 hidden 属性：作者的 display 规则会覆盖
                  // UA 的 [hidden]{display:none}，造成显隐行为不确定）。 ?>
            <div class="bulkbar" id="bulkbar">
                <label class="bulk-select-all">
                    <input type="checkbox" id="select-all"> 全选本页
                </label>
                <span class="bulk-count" id="bulk-count">已选 0 张</span>
                <button type="button" class="btn btn-sm btn-danger" id="bulk-delete" disabled>
                    删除所选
                </button>
            </div>

            <div class="grid" id="admin-grid" data-bulk="1">
                <?php $__i = 0; foreach ($data['rows'] as $img): ?>
                    <?php
                    $editable   = true;
                    $selectable = true;
                    $isFirst    = ($__i === 0);
                    $__i++;
                    require APP_ROOT . '/src/views/card.php';
                    ?>
                <?php endforeach; ?>
            </div>

            <?php
            // 分页需要保留搜索词，否则翻页会丢掉查询条件
            $page = $data['page'];
            $pages = $data['pages'];
            $pagerQuery = $q !== '' ? ('q=' . rawurlencode($q) . '&') : '';
            require APP_ROOT . '/src/views/pagination.php';
            ?>
        <?php endif; ?>
    </div>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
