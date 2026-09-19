<?php
declare(strict_types=1);

/**
 * 删除本机备份文件（需登录 + POST + CSRF）。
 *
 * 安全要点：
 *   - **绝不接受客户端传来的路径**。只接受一个文件名，然后在**服务端枚举**
 *     出来的允许列表里查找。这样即使有人构造 "../config.php" 也无处可用。
 *   - 只允许两个位置：data/ 下的导入回滚点、backup/ 下的命令行备份。
 *   - 只允许匹配我们自己的命名模式。
 */

require __DIR__ . '/../src/bootstrap.php';
// 这是 JSON 端点，必须用 require_admin_json()：绝不返回 302。
// 用 require_admin() 会把匿名攻击者引导到登录页，语义不正确，
// 也与 upload.php / delete.php 等端点行为不一致。
require_admin_json();
csrf_require_post();

$name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
if ($name === '') {
    json_out(['ok' => false, 'error' => '缺少文件名。'], 400);
}

// 只取 basename，杜绝任何路径成分
$name = basename($name);

// 允许的目录与各自的命名模式。
// 每种都精确匹配：数据库回滚点、配置回滚点、命令行生成的备份包。
$candidates = [
    ['dir' => DATA_DIR,             'pattern' => '/^database\.before-import-[0-9]{8}-[0-9]{6}\.sqlite$/'],
    ['dir' => DATA_DIR,             'pattern' => '/^config\.before-import-[0-9]{8}-[0-9]{6}\.php$/'],
    ['dir' => APP_ROOT . '/backup', 'pattern' => '/^elation-[0-9]{8}-[0-9]{6}\.(zip|sqlite)$/'],
];

$target = null;
foreach ($candidates as $c) {
    if (preg_match($c['pattern'], $name) !== 1) {
        continue;
    }
    $path = $c['dir'] . '/' . $name;
    // realpath 校验：确认最终位置确实在预期目录内（防符号链接等）
    $real = realpath($path);
    $dirReal = realpath($c['dir']);
    if ($real === false || $dirReal === false) {
        continue;
    }
    if (strncmp($real, $dirReal . DIRECTORY_SEPARATOR, strlen($dirReal) + 1) !== 0) {
        continue;
    }
    if (!is_file($real)) {
        continue;
    }
    $target = $real;
    break;
}

if ($target === null) {
    log_event('warning', 'backup_delete_rejected', ['name' => $name]);
    json_out(['ok' => false, 'error' => '该文件不在可删除的备份列表中。'], 400);
}

// 不允许删掉当前正在使用的数据库
if (realpath(DATA_DIR . '/database.sqlite') === $target) {
    json_out(['ok' => false, 'error' => '不能删除正在使用的数据库。'], 400);
}

$size = (int)@filesize($target);
if (!@unlink($target)) {
    log_event('error', 'backup_delete_failed', ['name' => $name]);
    json_out(['ok' => false, 'error' => '删除失败，请检查文件权限。'], 500);
}

log_event('info', 'backup_deleted', ['name' => $name, 'bytes' => $size]);
json_out(['ok' => true, 'name' => $name, 'freed' => $size]);
