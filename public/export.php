<?php
declare(strict_types=1);

/**
 * 导出备份包（需登录）。
 *
 * 导出内容：**数据库 + 站点配置**，打包成一个 zip。
 *
 * 为什么不是只导出数据库：
 *   站点标题、描述、固定网址、每页数量、管理员密码哈希都保存在
 *   data/config.php 中。只备份数据库的话，换一台机器恢复后会发现
 *   **设置全丢、而且登录不进去** —— 那不是一份可用的备份。
 *
 * 数据库部分用 VACUUM INTO 生成一致快照：本站启用 WAL 模式，
 * 直接复制 database.sqlite 会丢掉最新事务。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin();

if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
}

$tmpDir = DATA_DIR . '/tmp';
if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true)) {
    log_event('error', 'export_tmpdir_failed');
    header('Location: ' . url('/backup.php?err=tmp'), true, 302);
    exit;
}

$snap = $tmpDir . '/snap-' . bin2hex(random_bytes(6)) . '.sqlite';
$zip  = $tmpDir . '/export-' . bin2hex(random_bytes(6)) . '.zip';

if (!backup_snapshot_db($snap)) {
    @unlink($snap);
    header('Location: ' . url('/backup.php?err=vacuum'), true, 302);
    exit;
}
if (!backup_write_bundle($zip, $snap)) {
    @unlink($snap); @unlink($zip);
    header('Location: ' . url('/backup.php?err=bundle'), true, 302);
    exit;
}
@unlink($snap);   // 快照已进 zip，删掉落盘副本

if (!is_file($zip)) {
    header('Location: ' . url('/backup.php?err=nofile'), true, 302);
    exit;
}

$name = backup_filename();
$size = (int)filesize($zip);

log_event('info', 'backup_exported', ['bytes' => $size]);

// 清空输出缓冲，避免把 HTML 混进二进制流
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($zip);
@unlink($zip);
exit;
