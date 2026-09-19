<?php
declare(strict_types=1);

/**
 * 批量删除接口（需登录 + POST + CSRF）。
 *
 * 与单张删除的区别：
 *   - 一次接收多个 id
 *   - 逐条处理，返回成功/失败明细（不因为一个 id 无效就整体失败）
 *   - 上限 500 条，避免超长请求
 *
 * 安全上不放松任何一项：仍要求管理员会话、POST、CSRF。
 * 单张删除仍然保留 delete.php —— 前端"删除"按钮走的是那条路径。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin_json();
csrf_require_post();

$raw = $_POST['ids'] ?? '';
if (!is_string($raw) || $raw === '') {
    json_out(['ok' => false, 'error' => '缺少要删除的图片。'], 400);
}

// 允许 "1,2,3" 或 JSON 数组两种形式，前端用前者（更短）
$ids = [];
if (str_starts_with(trim($raw), '[')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $ids = $decoded;
    }
} else {
    $ids = explode(',', $raw);
}

$clean = [];
foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) { $clean[$id] = $id; }
}
$clean = array_slice($clean, 0, 500, true);

if ($clean === []) {
    json_out(['ok' => false, 'error' => '没有有效的图片编号。'], 400);
}

$found = images_find_many(array_values($clean));

$deleted = [];
$missing = [];
$failed  = [];

foreach ($clean as $id) {
    if (!isset($found[$id])) {
        $missing[] = $id;
        continue;
    }
    $filename = (string)$found[$id]['filename'];
    // 先删文件再删记录：若文件删除失败则保留记录，避免留下"有记录无文件"的坏数据
    try {
        upload_remove_files($filename);
    } catch (Throwable $e) {
        $failed[] = $id;
        continue;
    }
    if (image_delete($id)) {
        $deleted[] = $id;
    } else {
        $failed[] = $id;
    }
}

log_event('info', 'bulk_delete', [
    'deleted' => count($deleted),
    'missing' => count($missing),
    'failed'  => count($failed),
]);

if ($deleted === [] && $failed !== []) {
    json_out(['ok' => false, 'error' => '删除失败，请稍后重试。'], 500);
}

json_out([
    'ok'      => true,
    'deleted' => $deleted,
    'missing' => $missing,
    'failed'  => $failed,
    'count'   => count($deleted),
]);
