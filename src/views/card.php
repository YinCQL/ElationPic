<?php
declare(strict_types=1);
/**
 * 图片卡片（frontend/backend 共用）。
 * @var array $img
 * @var bool  $editable   是否显示删除按钮（仅后台且已登录）
 * @var bool  $selectable 是否输出批量选择框（仅后台）
 * @var bool  $isFirst    是否为列表第一张 —— 它通常是首屏可见的图，
 *                        因此去掉懒加载并提高优先级，让首图更快出现。
 *                        其余图片保持 loading="lazy"，避免一次性拉取整页图片。
 * @var array $flags      由 images_page_flags() 得到的每页标记：
 *                        ['missing'=>[filename=>true], 'dupes'=>[origName=>count]]。
 *                        用于在卡片上标出"文件丢了"与"原始文件名重复"——
 *                        让问题在列表里**直接可见**，而不是只在自检报告里列文件名。
 *                        不传则不显示任何标记。
 */

// filename 必须通过白名单校验后才用于拼 URL（§7 输出契约）
$safe = safe_filename((string)$img['filename']);
if ($safe === null) {
    return;
}
$url      = image_url($safe);
// "复制直链"要粘到站外使用，因此必须是绝对地址（含协议与主机）。
// 页面内的 href 仍用相对路径——那样更可移植，也避免暴露主机名。
$absUrl   = absolute_url('/uploads/' . $safe);
$hasThumb = ((int)$img['thumb'] === 1);
$src      = $hasThumb ? thumb_url($safe) : $url;

// 需要提醒的问题（由调用方传入，见文件头注释）
$fileMissing = !empty($flags['missing'][$safe]);
$dupeCount   = (int)($flags['dupes'][(string)$img['original_name']] ?? 0);
$hasIssue    = $fileMissing || $dupeCount > 1;
?>
<article class="card<?= $hasIssue ? ' has-issue' : '' ?>" data-id="<?= (int)$img['id'] ?>">
    <?php // 勾选框只在后台列表输出（由调用方传入 $selectable）。
          // 刻意不依赖 CSS 隐藏 —— 前台是公开浏览页，压根不该出现选择控件，
          // 而不渲染比"渲染后用样式藏起来"更可靠（不受缓存/优先级/打印样式影响）。 ?>
    <?php if (!empty($selectable)): ?>
        <label class="card-pick" title="选择这张">
            <input type="checkbox" class="js-pick" value="<?= (int)$img['id'] ?>"
                   aria-label="选择 <?= e(display_name((string)$img['original_name'])) ?>">
        </label>
    <?php endif; ?>
    <a class="card-media" href="<?= e($url) ?>" target="_blank" rel="noopener">
        <?php // data-full 用于缩略图缺失时由 app.js 回退到原图（不增加服务端 stat 开销） ?>
        <img class="thumb"
             src="<?= e($src) ?>"
             <?php if ($hasThumb && $src !== $url): ?>data-full="<?= e($url) ?>"<?php endif; ?>
             alt="<?= e((string)$img['original_name']) ?>"
             width="<?= (int)$img['width'] ?>"
             height="<?= (int)$img['height'] ?>"
             <?php if (!empty($isFirst)): ?>
             loading="eager" fetchpriority="high" decoding="async"
             <?php else: ?>
             loading="lazy" decoding="async"
             <?php endif; ?>>
    </a>
    <div class="card-body">
        <?php if ($fileMissing): ?>
            <p class="card-flag card-flag-bad" role="note">
                文件已丢失，卡片打不开。请删除这条记录。
            </p>
        <?php endif; ?>
        <?php if ($dupeCount > 1): ?>
            <p class="card-flag card-flag-dupe" role="note">
                原始文件名有 <?= (int)$dupeCount ?> 张重复
            </p>
        <?php endif; ?>

        <?php // 展示时去掉扩展名；悬停 title 仍保留完整原始文件名 ?>
        <p class="card-name" title="<?= e((string)$img['original_name']) ?>"><?= e(display_name((string)$img['original_name'])) ?></p>
        <p class="card-meta">
            <span><?= (int)$img['width'] ?> × <?= (int)$img['height'] ?></span>
            <span class="sep">·</span>
            <span><?= e(format_bytes((int)$img['size'])) ?></span>
            <span class="sep">·</span>
            <?php
            // 重名时把时间显示到**秒**：否则两张同名图的时间看起来完全一样，
            // 仍然无法分辨哪张是哪张（这正是重名标记要解决的问题）。
            $__t = format_local_time((string)$img['created_at']);
            if ($dupeCount > 1 && strlen($__t) === 16) { $__t .= ':00'; }
            ?>
            <span<?= $dupeCount > 1 ? ' title="该原始文件名有重复，已显示到秒以便区分"' : '' ?>><?= e($__t) ?></span>
        </p>
        <div class="card-actions">
            <button type="button" class="btn btn-sm js-copy" data-url="<?= e($absUrl) ?>">复制直链</button>
            <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">查看</a>
            <?php if (!empty($editable)): ?>
                <button type="button" class="btn btn-sm btn-danger js-delete" data-id="<?= (int)$img['id'] ?>">删除</button>
            <?php endif; ?>
        </div>
    </div>
</article>
