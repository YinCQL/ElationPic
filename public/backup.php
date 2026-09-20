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
// $storage 随维护工具一起搬到了 maintenance.php —— 这一页不再需要它。
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

<?php
// 维护工具在 maintenance.php，从侧边栏进入。
// 这里不再放跳转按钮 —— 侧边栏已经是一个常驻入口，
// 页面里再放一个只会让内容区多出一块没有实际信息的区域。
?>

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
