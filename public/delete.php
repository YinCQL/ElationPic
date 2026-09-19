<?php
declare(strict_types=1);

/**
 * 删除接口（§23）。必须：管理员 Session + POST + CSRF。
 * 禁止 GET 触发删除。
 */

require __DIR__ . '/../src/bootstrap.php';

// API 端点：未登录一律 403 JSON，绝不重定向
require_admin_json();
csrf_require_post();

$idRaw = $_POST['id'] ?? null;
if (!is_string($idRaw) && !is_int($idRaw)) {
    log_event('warning', 'delete_failed', ['step' => 'bad_id_type']);
    json_out(['ok' => false, 'error' => fail_message()], 400);
}
$idStr = (string)$idRaw;
if ($idStr === '' || !ctype_digit($idStr)) {
    log_event('warning', 'delete_failed', ['step' => 'bad_id']);
    json_out(['ok' => false, 'error' => '参数无效。'], 400);
}
$id = (int)$idStr;
if ($id < 1) {
    json_out(['ok' => false, 'error' => '参数无效。'], 400);
}

$img = image_find($id);
if ($img === null) {
    // 不存在的图片：正常处理，不报错（§23）
    log_event('info', 'delete_missing', ['id' => $id]);
    json_out(['ok' => true], 200);
}

try {
    image_delete($id);
} catch (Throwable $e) {
    log_event('error', 'delete_failed', ['step' => 'db', 'id' => $id]);
    json_out(['ok' => false, 'error' => fail_message()], 500);
}

// 文件不存在不算错误
upload_remove_files((string)$img['filename']);

log_event('info', 'delete_success', ['id' => $id]);
json_out(['ok' => true], 200);
