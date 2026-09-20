<?php
declare(strict_types=1);

/**
 * 维护工具（需登录）。
 *
 * 从原「备份与恢复」页拆出来。那一页当时同时承担导出、导入、存储概况、
 * 缩略图重建、一致性自检五件事，342 行，找一项功能要来回滚。
 *
 * 拆分依据是**使用频率**：导出与导入是日常操作，
 * 而这里的三项是「偶尔才用、用完就走」的诊断与修复工具。
 * 放在一起会让常用入口被不常用内容淹没。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin();

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$stats   = images_stats();
$storage = storage_summary();

$pageTitle   = '维护工具';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel">
    <h1>维护工具</h1>
    <p class="muted">
        这些是诊断与修复工具，平时不需要用到。
        日常的备份与恢复在<a href="<?= e(url('/backup.php')) ?>">备份与恢复</a>页。
    </p>
</section>

<?php
// 存储概况：图库占用 + 磁盘剩余。
// 磁盘快满时要提前知道 —— 写满不只是上传失败，
// PHP 的 session 文件也写不进去，可能表现为全站异常。
?>
<section class="panel">
    <h2>存储</h2>
    <div class="storage">
        <div class="storage-row">
            <span class="storage-label">原图占用</span>
            <span class="storage-value">
                <?= e(format_bytes((int)$storage['library_bytes'])) ?>
                <span class="muted">（<?= (int)$storage['files'] ?> 个文件）</span>
            </span>
        </div>
        <?php if ($storage['disk_free'] !== null): ?>
            <div class="storage-row">
                <span class="storage-label">磁盘剩余</span>
                <span class="storage-value">
                    <?= e(format_bytes((int)$storage['disk_free'])) ?>
                    <span class="muted">/ 共 <?= e(format_bytes((int)$storage['disk_total'])) ?></span>
                </span>
            </div>
            <?php
            $__used = max(0, (int)$storage['disk_total'] - (int)$storage['disk_free']);
            $__pct  = (int)$storage['disk_total'] > 0
                ? (int)round($__used / (int)$storage['disk_total'] * 100)
                : 0;
            ?>
            <div class="progress storage-bar">
                <div class="progress-bar <?= $storage['level'] === 'critical' ? 'is-critical' : ($storage['level'] === 'low' ? 'is-low' : '') ?>"
                     style="width:<?= (int)$__pct ?>%"></div>
            </div>
            <p class="muted storage-note">已用 <?= (int)$__pct ?>%</p>

            <?php if (!empty($storage['nearly_full'])): ?>
                <p class="alert" role="alert">
                    磁盘已用 <?= (int)$__pct ?>%。
                    目前剩余空间对本站仍然够用，但说明<strong>这台机器上有别的东西在占用磁盘</strong> ——
                    照这个趋势剩下这些迟早也会被吃掉，那时图床就会受影响。建议顺手看看是什么在占空间。
                </p>
            <?php endif; ?>

            <?php if ($storage['level'] === 'critical'): ?>
                <p class="alert" role="alert">
                    磁盘剩余不足 500 MB。写入随时可能失败 ——
                    不只是上传，PHP 的会话文件也写不进去，可能导致全站异常。
                    请尽快清理空间。
                </p>
            <?php elseif ($storage['level'] === 'low'): ?>
                <p class="alert" role="alert">
                    磁盘剩余不足 2 GB，建议清理。
                    空间耗尽时上传与本机会话都会出问题。
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">无法读取磁盘信息（服务器可能禁用了相关函数）。</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <h2>重建缩略图</h2>
    <p class="muted">
        缩略图只在上传时生成一次。若你修改过「缩略图最长边」，或者
        <code>uploads/thumbs/</code> 丢失了，已上传的图片仍会用旧尺寸或直接加载原图
        （流量变大）。这里可以按当前设置重新生成。
    </p>
    <p class="muted">
        共 <?= (int)$stats['count'] ?> 条记录，其中
        <strong><?= (int)$stats['thumbs'] ?></strong> 条已有缩略图。
        原图本身就小于阈值的图片会被跳过（它们本就不需要缩略图）。
    </p>
    <p>
        <button type="button" class="btn" id="btn-rebuild-thumbs"
                data-offset="0"
                data-total="<?= (int)$stats['count'] ?>">重建缩略图</button>
        <span class="muted" id="rebuild-status" role="status"></span>
    </p>
    <div class="progress" id="rebuild-progress" hidden>
        <div class="progress-bar" id="rebuild-bar"></div>
    </div>
</section>

<section class="panel">
    <h2>文件一致性自检</h2>
    <p class="muted">
        检查 <code>uploads/</code> 中的文件与数据库记录是否对得上。两类问题都不会报错，
        只会默默存在：
    </p>
    <ul class="muted maint-list">
        <li><strong>孤儿文件</strong> —— 有文件、没记录（例如删除时文件没删掉），
            占用磁盘且在界面里看不到</li>
        <li><strong>缺失文件</strong> —— 有记录、没文件（文件被误删或磁盘故障），
            首页会出现打不开的卡片</li>
    </ul>
    <p class="muted">
        这两类问题在图片列表里也会<strong>直接标在对应卡片上</strong>，
        不必回到这里逐个比对文件名。
    </p>
    <p>
        <button type="button" class="btn" id="btn-consistency">开始自检</button>
        <span class="muted" id="consistency-status" role="status"></span>
    </p>
    <div id="consistency-result" hidden>
        <div id="consistency-detail"></div>
        <p id="consistency-purge-wrap" hidden>
            <button type="button" class="btn btn-danger" id="btn-purge-orphans">
                删除这些孤儿文件
            </button>
            <span class="muted">删除前请先确认它们确实不需要保留。</span>
        </p>
        <p id="consistency-drop-wrap" hidden>
            <button type="button" class="btn btn-danger" id="btn-drop-broken">
                删除这些失效记录
            </button>
            <span class="muted">
                文件已不在磁盘上，这些卡片本来也打不开 ——
                删除记录不会影响任何还能看的图片。
            </span>
        </p>
    </div>
</section>

<?php require APP_ROOT . '/src/views/footer.php'; ?>