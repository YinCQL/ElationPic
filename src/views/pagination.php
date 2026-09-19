<?php
declare(strict_types=1);
/** @var int $page @var int $pages */
/** @var string $pagerQuery 可选：额外查询串（如搜索词），需已 urlencode 且带 & */
if (($pages ?? 1) <= 1) {
    return;
}
// 保留搜索条件，否则在搜索结果里翻页会丢掉关键词
$extra = $pagerQuery ?? '';
?>
<nav class="pager">
    <?php if ($page > 1): ?>
        <a class="btn btn-sm" href="?<?= e($extra) ?>page=<?= (int)($page - 1) ?>">上一页</a>
    <?php endif; ?>
    <span class="pager-info">第 <?= (int)$page ?> / <?= (int)$pages ?> 页</span>
    <?php if ($page < $pages): ?>
        <a class="btn btn-sm" href="?<?= e($extra) ?>page=<?= (int)($page + 1) ?>">下一页</a>
    <?php endif; ?>
</nav>
