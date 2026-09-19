<?php
declare(strict_types=1);

/**
 * 重建缩略图（需登录 + POST + CSRF）。
 *
 * 用途：修改 thumb_max_edge 之后让旧图跟上，或在 thumbs/ 丢失后补回来。
 *
 * 分批处理：每次只处理一批（默认 20 条），由前端循环调用。
 * 一次性处理整个图库在大站点上会超出 max_execution_time，
 * 而"处理到一半超时"既没有进度提示，也无法续做。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin_json();
csrf_require_post();

if (!extension_loaded('gd')) {
    json_out(['ok' => false, 'error' => '服务器未启用 GD 扩展，无法生成缩略图。'], 400);
}

$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
$limit  = isset($_POST['limit'])  ? (int)$_POST['limit']  : 20;

$res = thumb_rebuild_batch($offset, $limit);

log_event('info', 'thumbs_rebuilt', [
    'processed' => $res['processed'],
    'built'     => $res['built'],
    'skipped'   => $res['skipped'],
    'missing'   => $res['missing'],
    'failed'    => $res['failed'],
    'done'      => $res['done'],
]);

json_out(['ok' => true] + $res);
