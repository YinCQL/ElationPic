<?php
declare(strict_types=1);

/**
 * 备份包的构建与解析。
 *
 * 为什么需要这个模块：
 *   早期实现只导出 database.sqlite —— 也就是**只有图片索引**。
 *   站点标题、描述、固定网址、每页数量、以及**管理员密码哈希**都在
 *   data/config.php 里，全部没有备份。用户换机器恢复后会发现：
 *   设置全丢，而且登录不进去。
 *
 * 因此备份改为一个 **ZIP 包**，同时包含：
 *   - database.sqlite   图片索引
 *   - config.php        全部站点设置 + 管理员密码哈希
 *   - manifest.json     格式版本、生成时间、站点概况（供人查看与校验）
 *
 * 为什么用 zip 而不是自定义格式：
 *   用户可以直接解开查看、单独取用数据库；出问题时也容易人工排查。
 *   服务器已具备 zip 扩展（PHP 8.0.2 标准配置通常都带）。
 *
 * 安全提示：备份包内含**管理员密码哈希**，等同于站点钥匙，
 * 必须妥善保管，不要放在公开位置。
 */

/** 备份包格式标识与版本。 */
function backup_format(): string { return 'elation-backup'; }
function backup_version(): int    { return 1; }

/** 备份包的默认文件名。 */
function backup_filename(string $prefix = 'elation-backup'): string
{
    return $prefix . '-' . date('Ymd-His') . '.zip';
}

/**
 * 生成数据库的一致快照到指定路径。
 * 用 VACUUM INTO —— 本站启用 WAL，直接复制 database.sqlite 会丢事务。
 */
function backup_snapshot_db(string $dest): bool
{
    $db = DATA_DIR . '/database.sqlite';
    if (!is_file($db)) {
        return false;
    }
    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        $pdo = null;
    } catch (Throwable $e) {
        @unlink($dest);
        log_event('error', 'backup_snapshot_failed', ['msg' => $e->getMessage()]);
        return false;
    }
    return is_file($dest);
}

/**
 * 把数据库快照与配置打包成 zip。
 *
 * @param string $dest     目标 zip 路径
 * @param string $dbSnap   已生成的数据库快照路径
 * @return bool
 */
function backup_write_bundle(string $dest, string $dbSnap): bool
{
    if (!class_exists('ZipArchive')) {
        log_event('error', 'backup_zip_unavailable');
        return false;
    }
    $cfgFile = DATA_DIR . '/config.php';
    if (!is_file($cfgFile)) {
        log_event('error', 'backup_config_missing');
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        log_event('error', 'backup_zip_open_failed');
        return false;
    }

    $zip->addFile($dbSnap, 'database.sqlite');
    $zip->addFile($cfgFile, 'config.php');

    // 图片文件：放入 uploads/ 前缀下，与站点目录结构一致，便于直接取用。
    // ZipArchive::addFile 从磁盘流式读取，不会把文件读进内存，因此体积不是问题。
    $uploadDir = APP_ROOT . '/public/uploads';
    $imgCount  = 0;
    $imgBytes  = 0;
    if (is_dir($uploadDir)) {
        $items = @scandir($uploadDir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === '.gitkeep') {
                    continue;
                }
                $full = $uploadDir . '/' . $item;
                if (is_file($full)) {
                    // 只收图片本体（文件名是服务端生成的 32 位十六进制 + 图片扩展名）
                    if (preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/i', $item) === 1) {
                        $zip->addFile($full, 'uploads/' . $item);
                        $imgCount++;
                        $imgBytes += (int)@filesize($full);
                    }
                } elseif (is_dir($full) && $item === 'thumbs') {
                    // 缩略图可以重新生成，不必备份
                    continue;
                }
            }
        }
    }

    // manifest：给人看，也给导入时做格式校验
    $stats = images_stats();
    $manifest = [
        'format'      => backup_format(),
        'version'     => backup_version(),
        'created_at'  => gmdate('c'),
        'site_name'   => (string)cfg()['site_name'],
        'images'      => (int)$stats['count'],
        'bytes'       => (int)$stats['bytes'],
        'files'       => $imgCount,
        'files_bytes' => $imgBytes,
        'note'        => 'config.php 含管理员密码哈希，请妥善保管本备份包。',
    ];
    $zip->addFromString(
        'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $ok = $zip->close();
    if (!$ok) {
        @unlink($dest);
        log_event('error', 'backup_zip_close_failed');
        return false;
    }
    return true;
}

/**
 * 读取并校验一个备份包。
 *
 * @return array{ok:bool,error:string,db?:string,config?:string,manifest?:array}
 *         db/config 为解出的临时文件路径（调用方负责清理）
 */
function backup_read_bundle(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => '服务器未启用 zip 扩展。'];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => '无法打开该文件，它不是有效的 zip 备份包。'];
    }

    // manifest 必须是本程序的格式
    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) {
        $zip->close();
        return ['ok' => false, 'error' => '备份包缺少 manifest.json，不是本站的备份。'];
    }
    $manifest = json_decode((string)$manifestRaw, true);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== backup_format()) {
        $zip->close();
        return ['ok' => false, 'error' => '备份包格式标识不匹配。'];
    }
    if ((int)($manifest['version'] ?? 0) > backup_version()) {
        $zip->close();
        return ['ok' => false, 'error' => '备份包版本高于当前程序，请先升级程序。'];
    }

    // 先收集包内的图片条目，并做**严格的路径校验**。
    //
    // 为什么必须校验：zip 条目名完全由打包方决定，可以包含 "../" 或绝对路径
    // （即"zip slip"漏洞）。若不校验，一个恶意备份包就能往站点目录外写文件。
    // 因此这里只接受**恰好**形如 "uploads/<32位十六进制>.<图片扩展名>" 的条目，
    // 其余一律忽略（而不是报错——旧备份包本就没有图片）。
    $imageEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || !isset($stat['name'])) {
            continue;
        }
        $name = (string)$stat['name'];
        if (preg_match('#^uploads/[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$#i', $name) === 1) {
            $imageEntries[] = $name;
        }
    }

    $dbRaw = $zip->getFromName('database.sqlite');
    $cfgRaw = $zip->getFromName('config.php');
    $zip->close();

    if ($dbRaw === false) {
        return ['ok' => false, 'error' => '备份包缺少 database.sqlite。'];
    }
    if ($cfgRaw === false) {
        return ['ok' => false, 'error' => '备份包缺少 config.php。'];
    }

    // 落盘到临时文件，供后续校验与替换使用
    $tmpDir = DATA_DIR . '/tmp';
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true)) {
        return ['ok' => false, 'error' => '无法创建临时目录。'];
    }
    $dbOut  = $tmpDir . '/restore-' . bin2hex(random_bytes(6)) . '.sqlite';
    $cfgOut = $tmpDir . '/restore-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($dbOut, $dbRaw) === false || @file_put_contents($cfgOut, $cfgRaw) === false) {
        @unlink($dbOut); @unlink($cfgOut);
        return ['ok' => false, 'error' => '无法写入临时文件，请检查 data/tmp 权限。'];
    }
    @chmod($dbOut, 0660);
    @chmod($cfgOut, 0660);

    // 数据库必须是可读的 SQLite 且含 images 表
    try {
        $probe = new PDO('sqlite:' . $dbOut, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $probe->query('SELECT COUNT(*) FROM images');
        $probe->query('SELECT id, filename, original_name, created_at FROM images LIMIT 1');
        $probe = null;
    } catch (Throwable $e) {
        @unlink($dbOut); @unlink($cfgOut);
        return ['ok' => false, 'error' => '备份包内的数据库无效（读不出 images 表）。'];
    }

    // 配置必须是可包含的 PHP 且返回数组、含密码哈希
    $cfgArr = @include $cfgOut;
    if (!is_array($cfgArr)
        || !isset($cfgArr['admin_password_hash'])
        || !is_string($cfgArr['admin_password_hash'])
        || $cfgArr['admin_password_hash'] === ''
        || $cfgArr['admin_password_hash'][0] !== '$') {
        @unlink($dbOut); @unlink($cfgOut);
        return ['ok' => false, 'error' => '备份包内的 config.php 无效或缺少管理员密码。'];
    }

    return [
        'ok'       => true,
        'error'    => '',
        'db'       => $dbOut,
        'config'   => $cfgOut,
        'manifest' => $manifest,
        'zip'      => $zipPath,       // 需要时用于解出图片
        'images'   => $imageEntries,  // 已通过路径校验的条目名
    ];
}

/**
 * 把备份包内的图片解到 uploads 目录。
 *
 * 只处理调用方从 backup_read_bundle() 拿到的**已校验条目名**，
 * 因此不会出现路径穿越。同名文件直接覆盖 —— 文件名是内容随机生成的，
 * 内容一致，覆盖是安全的。
 *
 * @return array{ok:bool,written:int,failed:int}
 */
function backup_extract_images(string $zipPath, array $entries): array
{
    if ($entries === [] || !class_exists('ZipArchive')) {
        return ['ok' => true, 'written' => 0, 'failed' => 0];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'written' => 0, 'failed' => 0];
    }

    $dest = APP_ROOT . '/public/uploads';
    if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
        $zip->close();
        return ['ok' => false, 'written' => 0, 'failed' => 0];
    }

    $written = 0;
    $failed  = 0;
    foreach ($entries as $name) {
        // 二次校验：即便上游被改动，这里也再确认一次。
        // basename() 保证最终只取文件名，不含任何目录成分。
        if (preg_match('#^uploads/([a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp))$#i', $name, $m) !== 1) {
            $failed++;
            continue;
        }
        $target = $dest . '/' . basename($m[1]);
        $src = $zip->getStream($name);
        if ($src === false) {
            $failed++;
            continue;
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($src);
            $failed++;
            continue;
        }
        $copied = @stream_copy_to_stream($src, $out);
        fclose($src);
        fclose($out);
        if ($copied === false) {
            @unlink($target);
            $failed++;
        } else {
            @chmod($target, 0660);
            $written++;
        }
    }
    $zip->close();

    return ['ok' => $failed === 0, 'written' => $written, 'failed' => $failed];
}
