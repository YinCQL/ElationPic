<?php
declare(strict_types=1);

/**
 * 备份与恢复（需登录）。
 *
 * 导出：GET /export.php 下载一份一致快照（VACUUM INTO）。
 * 导入：POST /import.php 覆盖现有数据库，带确认短语与回滚点。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin();

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$stats = images_stats();
$storage = storage_summary();
$db    = DATA_DIR . '/database.sqlite';
$dbSize = is_file($db) ? (int)filesize($db) : 0;
$cfgSize = is_file(DATA_DIR . '/config.php') ? (int)filesize(DATA_DIR . '/config.php') : 0;

// 回滚点：导入时自动生成（数据库 + 配置各一份）
$rollbacks = [];
foreach (['database.before-import-*.sqlite' => '数据库', 'config.before-import-*.php' => '配置'] as $pat => $kind) {
    foreach ((array)@glob(DATA_DIR . '/' . $pat) as $f) {
        $rollbacks[] = [
            'name' => basename((string)$f),
            'size' => (int)@filesize((string)$f),
            'kind' => $kind,
        ];
    }
}
rsort($rollbacks);

// 命令行脚本产生的备份包
$autoBackups = [];
foreach ((array)@glob(APP_ROOT . '/backup/elation-*.zip') as $f) {
    $autoBackups[] = ['name' => basename((string)$f), 'size' => (int)@filesize((string)$f), 'kind' => '备份包'];
}
rsort($autoBackups);

$okFlag   = isset($_GET['ok']);
$okRows   = isset($_GET['rows']) ? (int)$_GET['rows'] : 0;
$okImgs   = isset($_GET['imgs']) ? (int)$_GET['imgs'] : 0;
$okImgFail= isset($_GET['imgfail']) ? (int)$_GET['imgfail'] : 0;
$errCode = isset($_GET['err']) ? (string)$_GET['err'] : '';

$errMessages = [
    'nofile'     => '没有收到文件。',
    'upload'     => '文件上传失败，请重试。',
    'notuploaded'=> '文件来源不合法。',
    'toobig'     => '文件超过 128 MB。',
    'confirm'    => '确认短语不正确，未执行导入。',
    'invalid'    => '该文件不是有效的本站备份包（格式标识、数据库或配置校验未通过）。',
    'rollback'   => '无法为当前数据创建回滚点，出于安全考虑已中止。',
    'rename'     => '替换数据库失败，原数据未受影响。',
    'renamecfg'  => '替换配置失败，数据库已自动回滚。',
    'tmp'        => '无法创建临时目录。',
    'vacuum'     => '生成数据库快照失败，请检查磁盘空间。',
    'bundle'     => '打包备份失败（可能是服务器未启用 zip 扩展）。',
    'nofile2'    => '备份文件未生成。',
];

$pageTitle   = '备份与恢复';
$isAdminPage = true;
require APP_ROOT . '/src/views/header.php';
?>

<section class="panel">
    <h1>备份与恢复</h1>

    <?php if ($okFlag): ?>
        <p class="alert alert-ok" role="status">
            导入完成，当前数据库含 <?= (int)$okRows ?> 条记录<?php
            if ($okImgs > 0) { echo '，并恢复了 ' . (int)$okImgs . ' 个图片文件'; }
            ?>。
        </p>
        <?php if ($okImgFail > 0): ?>
            <p class="alert" role="alert">
                有 <?= (int)$okImgFail ?> 个图片文件未能写入，请检查
                <code>public/uploads/</code> 的目录权限。
            </p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($errCode !== ''): ?>
        <p class="alert" role="alert"><?= e($errMessages[$errCode] ?? '操作失败。') ?></p>
    <?php endif; ?>

    <div class="stats">
        <div class="stat">
            <span class="stat-num"><?= (int)$stats['count'] ?></span>
            <span class="stat-label">当前记录数</span>
        </div>
        <div class="stat">
            <span class="stat-num"><?= e(format_bytes($dbSize)) ?></span>
            <span class="stat-label">数据库大小</span>
        </div>
        <div class="stat">
            <span class="stat-num"><?= e(format_bytes((int)$stats['bytes'])) ?></span>
            <span class="stat-label">原图占用</span>
        </div>
    </div>

    <?php // 用 class 而不是内联样式：内联样式无法被深色模式覆盖 ?>
    <div class="warnbox warnbox-info">
        <strong>备份包包含什么</strong>
        <ul>
            <li><code>database.sqlite</code> —— 图片索引（文件名、尺寸、上传时间等）</li>
            <li><code>config.php</code> —— <strong>站点设置</strong>（标题、描述、固定网址、每页数量）
                以及<strong>管理员密码</strong></li>
            <li><code>uploads/</code> —— <strong>原图文件本身</strong></li>
            <li><code>manifest.json</code> —— 生成时间与数量统计，供人工查看</li>
        </ul>
        <p style="margin:6px 0 0">
            数据库与配置<strong>必须一起备份</strong>：只恢复数据库的话，
            站点设置会全部丢失，而且<strong>登录不进去</strong>。
        </p>
    </div>

    <p class="muted">
        <strong>不包含</strong>缩略图（<code>uploads/thumbs/</code>）——
        它们可以随时由原图重新生成，没有必要占用备份体积。
    </p>
</section>

<section class="panel">
    <h2>导出</h2>
    <p class="muted">
        下载一个 zip 备份包，内含<strong>数据库 + 站点配置</strong>。
        数据库部分用 <code>VACUUM INTO</code> 生成一致快照 ——
        本站启用 WAL 模式，<strong>直接复制 database.sqlite 会丢最新数据</strong>。
    </p>
    <p><a class="btn btn-primary" href="<?= e(url('/export.php')) ?>">下载完整备份包</a></p>
</section>

<section class="panel">
    <h2>导入（恢复）</h2>
    <p class="muted">
        用一个备份包<strong>覆盖</strong>当前的数据库与站点设置。
    </p>

    <div class="warnbox">
        <strong>请注意</strong>
        <ul>
            <li>这会<strong>完全替换</strong>现有的图片索引<strong>和站点设置</strong>
                （包括管理员密码）。</li>
            <li><code>public/uploads/</code> 里的图片文件<strong>不会</strong>被改动。</li>
            <li>导入前系统会<strong>自动为数据库和配置各生成一个回滚点</strong>，若结果不对可手工用它恢复。</li>
            <li>仅接受本页导出的 <code>.zip</code> 备份包（会校验格式标识、
                数据库结构与配置有效性）。</li>
        </ul>
    </div>

    <form method="post" action="<?= e(url('/import.php')) ?>" enctype="multipart/form-data" autocomplete="off">
        <?= csrf_field() ?>

        <label class="field">
            <span>备份文件</span>
            <input type="file" name="dbfile" accept=".zip,application/zip" required>
        </label>

        <label class="field">
            <span>输入 <code><?= 'REPLACE' ?></code> 以确认</span>
            <input type="text" name="confirm" required autocomplete="off" placeholder="REPLACE">
            <small class="hint">这是不可撤销的操作，因此需要显式确认。</small>
        </label>

        <button type="submit" class="btn btn-danger">覆盖当前数据库与设置</button>
    </form>
</section>

<?php if (!$okFlag && $errCode === ''): ?>
<section class="panel">
    <h2>维护</h2>

    <?php
    // 存储概况：图库占用 + 磁盘剩余。
    // 磁盘快满时要提前知道 —— 写满不只是上传失败，
    // PHP 的 session 文件也写不进去，可能表现为全站异常。
    ?>
    <h3 class="maint-title">存储</h3>
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
            // 用量条
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

    <hr class="maint-sep">

    <h3 class="maint-title">重建缩略图</h3>
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

    <hr class="maint-sep">

    <h3 class="maint-title">文件一致性自检</h3>
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
<?php endif; ?>

<?php if ($rollbacks || $autoBackups): ?>
<section class="panel">
    <h2>本机已有的备份文件</h2>
    <table class="table">
        <thead><tr><th>文件</th><th>内容</th><th>大小</th><th>位置</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rollbacks as $r): ?>
            <tr>
                <td><code><?= e($r['name']) ?></code></td>
                <td><?= e($r['kind']) ?></td>
                <td><?= e(format_bytes($r['size'])) ?></td>
                <td class="muted">data/ · 导入前自动生成</td>
                <td class="row-action">
                    <button type="button" class="btn btn-sm btn-danger js-del-backup"
                            data-name="<?= e($r['name']) ?>">删除</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($autoBackups as $b): ?>
            <tr>
                <td><code><?= e($b['name']) ?></code></td>
                <td><?= e($b['kind']) ?></td>
                <td><?= e(format_bytes($b['size'])) ?></td>
                <td class="muted">backup/ · 命令行脚本生成</td>
                <td class="row-action">
                    <button type="button" class="btn btn-sm btn-danger js-del-backup"
                            data-name="<?= e($b['name']) ?>">删除</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:10px">
        这些文件在服务器磁盘上，<strong>不构成异地备份</strong>。
        建议定期把它们复制到其他位置。
    </p>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/src/views/footer.php'; ?>
