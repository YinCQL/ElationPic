<?php
declare(strict_types=1);

/**
 * 备份（CLI）。
 *
 * 用法：
 *     php data/backup.php [输出目录]
 *     默认输出到 <项目>/backup/
 *
 * 备份内容：**数据库 + 站点配置**，打包成一个 zip。
 *
 * 为什么配置也要备份：
 *   站点标题、描述、固定网址、每页数量、管理员密码哈希都在 data/config.php。
 *   只备份数据库的话，恢复后**设置全丢、而且登录不进去**。
 *
 * 为什么数据库必须用 VACUUM INTO：
 *   本站启用 WAL 模式。**直接复制 database.sqlite 会丢事务** ——
 *   最近提交的数据还在 -wal 文件里，副本看起来正常，恢复时才发现缺数据。
 *
 * 图片文件（public/uploads/）不在范围内：它们写入后不可变，
 * 用 robocopy/rsync 增量同步更合适。
 */

// 只允许命令行执行
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/src/bootstrap.php';

$root   = APP_ROOT;
$outDir = $argv[1] ?? ($root . '/backup');
$outDir = rtrim(str_replace('\\', '/', $outDir), '/');

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "无法创建输出目录：$outDir\n");
    exit(1);
}

$tmpDir = DATA_DIR . '/tmp';
if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true)) {
    fwrite(STDERR, "无法创建临时目录：$tmpDir\n");
    exit(1);
}

$snap = $tmpDir . '/cli-snap-' . bin2hex(random_bytes(6)) . '.sqlite';
$dest = $outDir . '/' . backup_filename('elation');

if (!backup_snapshot_db($snap)) {
    fwrite(STDERR, "生成数据库快照失败。\n");
    exit(1);
}
if (!backup_write_bundle($dest, $snap)) {
    @unlink($snap);
    fwrite(STDERR, "打包失败（可能是服务器未启用 zip 扩展）。\n");
    exit(1);
}
@unlink($snap);

// 校验：备份包必须能重新打开并通过格式校验
$check = backup_read_bundle($dest);
if (empty($check['ok'])) {
    fwrite(STDERR, "备份包校验失败：" . (string)($check['error'] ?? '') . "\n");
    @unlink($dest);
    exit(1);
}
@unlink($check['db']);
@unlink($check['config']);

@chmod($dest, 0660);

$size = (int)@filesize($dest);
$rows = (int)($check['manifest']['images'] ?? 0);

echo "备份完成\n";
echo "  文件   : $dest\n";
echo "  大小   : " . number_format($size / 1024, 1) . " KB\n";
$files = (int)($check['manifest']['files'] ?? 0);
echo "  内容   : database.sqlite（索引 $rows 条） + config.php（站点设置与密码）\n";
echo "           uploads/（原图 $files 个）\n";
echo "  校验   : 备份包可重新打开，数据库、配置与图片条目均通过检查\n";
echo "\n";
echo "提示：备份包内含管理员密码哈希，请妥善保管，不要放在公开位置。\n";
echo "缩略图（uploads/thumbs/）不在备份内 —— 可由原图重新生成。\n";
exit(0);
