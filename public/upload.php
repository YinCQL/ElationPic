<?php
declare(strict_types=1);

/**
 * 上传接口（§12 §19 §36）。
 * 必须：管理员 Session + POST + CSRF。缺一不可，不依赖前端隐藏按钮。
 */

require __DIR__ . '/../src/bootstrap.php';

// 1) 管理员会话（API 端点：失败一律 403 JSON，绝不重定向）
require_admin_json();
// 2) 仅 POST + 3) CSRF
csrf_require_post();

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    log_event('warning', 'upload_failed', ['step' => 'no_file_field']);
    json_out(['ok' => false, 'error' => '没有选择文件。'], 400);
}

$result = handle_upload($_FILES['image'], cfg());

if (!$result['ok']) {
    json_out(['ok' => false, 'error' => $result['error']], 400);
}

json_out(['ok' => true, 'data' => $result['data']], 200);
