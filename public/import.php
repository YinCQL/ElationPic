<?php
declare(strict_types=1);

/**
 * 导入（恢复）备份包（需登录 + POST + CSRF）。
 *
 * 恢复内容：**数据库 + 站点配置**（含管理员密码哈希）。
 *
 * 这是全项目风险最高的操作 —— 它会覆盖现有数据。因此刻意做得很啰嗦：
 *
 *   1. 必须是管理员会话、POST、有效 CSRF
 *   2. 必须显式输入确认短语，避免误点
 *   3. 用 ZipArchive 打开并校验 manifest 格式标识与版本
 *   4. 包内数据库必须能读出 images 表（用 SQLite 自己验证，不看扩展名）
 *   5. 包内 config.php 必须能包含且含有效密码哈希
 *   6. 覆盖前**同时**为数据库与配置生成回滚点
 *   7. 先移除 WAL/SHM 再原子替换数据库（旧 WAL 与新库不匹配会损坏数据）
 *
 * 图片文件（uploads/）不在导入范围内：它们与数据库记录一一对应，
 * 用文件同步处理更合适。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin();
csrf_require_post();

$confirmPhrase = 'REPLACE';

$fail = static function (string $code): void {
    header('Location: ' . url('/backup.php?err=' . urlencode($code)), true, 302);
    exit;
};

// ---- 基本校验 ----
if (!isset($_FILES['dbfile'])) {
    $fail('nofile');
}
$f = $_FILES['dbfile'];
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    log_event('warning', 'import_upload_error', ['err' => (int)($f['error'] ?? -1)]);
    $fail('upload');
}
$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    $fail('notuploaded');
}
if ((int)($f['size'] ?? 0) > 128 * 1048576) {
    $fail('toobig');
}

$confirm = isset($_POST['confirm']) && is_string($_POST['confirm']) ? trim($_POST['confirm']) : '';
if ($confirm !== $confirmPhrase) {
    $fail('confirm');
}

// ---- 解析并校验备份包 ----
$bundle = backup_read_bundle($tmp);
if (empty($bundle['ok'])) {
    log_event('warning', 'import_invalid_bundle', ['msg' => (string)($bundle['error'] ?? '')]);
    $fail('invalid');
}

$newDb  = (string)$bundle['db'];
$newCfg = (string)$bundle['config'];
$rows   = (int)($bundle['manifest']['images'] ?? 0);

// ---- 覆盖前生成回滚点（数据库 + 配置都要） ----
$db       = DATA_DIR . '/database.sqlite';
$cfgFile  = DATA_DIR . '/config.php';
$stamp    = date('Ymd-His');
$rbDb     = DATA_DIR . '/database.before-import-' . $stamp . '.sqlite';
$rbCfg    = DATA_DIR . '/config.before-import-' . $stamp . '.php';

if (is_file($db)) {
    if (!backup_snapshot_db($rbDb)) {
        @unlink($newDb); @unlink($newCfg);
        log_event('error', 'import_rollback_failed');
        $fail('rollback');
    }
    @chmod($rbDb, 0660);
}
if (is_file($cfgFile)) {
    if (!@copy($cfgFile, $rbCfg)) {
        @unlink($newDb); @unlink($newCfg);
        log_event('error', 'import_rollback_cfg_failed');
        $fail('rollback');
    }
    @chmod($rbCfg, 0660);
}

// ---- 原子替换数据库 ----
// 旧的 WAL/SHM 属于旧库，留着会与新文件不匹配，导致数据错乱。
foreach (['-wal', '-shm'] as $suffix) {
    @unlink($db . $suffix);
}
if (!@rename($newDb, $db)) {
    @unlink($newDb); @unlink($newCfg);
    log_event('error', 'import_rename_failed');
    $fail('rename');
}
@chmod($db, 0660);

// ---- 替换配置 ----
if (!@rename($newCfg, $cfgFile)) {
    // 数据库已换、配置没换 -> 状态不一致。回滚数据库。
    if (is_file($rbDb)) { @copy($rbDb, $db); }
    @unlink($newCfg);
    log_event('error', 'import_config_rename_failed');
    $fail('renamecfg');
}
@chmod($cfgFile, 0660);

// ---- 解出图片 ----
// 备份包可能不含图片（旧版备份），此时 $bundle['images'] 为空数组，跳过即可。
// 条目名已在 backup_read_bundle() 中通过严格校验，这里再做一次 basename 兜底。
$imgWritten = 0;
$imgFailed  = 0;
if (!empty($bundle['images'])) {
    $ex = backup_extract_images((string)$bundle['zip'], (array)$bundle['images']);
    $imgWritten = (int)$ex['written'];
    $imgFailed  = (int)$ex['failed'];
    if (!$ex['ok']) {
        log_event('warning', 'import_images_partial', ['written' => $imgWritten, 'failed' => $imgFailed]);
    }
}

log_event('info', 'backup_imported', [
    'rows'    => $rows,
    'images'  => $imgWritten,
    'failed'  => $imgFailed,
    'rollback'=> basename($rbDb),
]);

header('Location: ' . url('/backup.php?ok=1&rows=' . $rows . '&imgs=' . $imgWritten . ($imgFailed > 0 ? '&imgfail=' . $imgFailed : '')), true, 302);
exit;
