<?php
declare(strict_types=1);

/**
 * 一致性自检（需登录 + POST + CSRF）。
 *
 * 三个动作：
 *   action=check        只报告，不改动任何东西
 *   action=purge        删除"有文件无记录"的孤儿文件
 *   action=drop_broken  删除"有记录无文件"的失效记录（卡片本来也打不开）
 *
 * 刻意**都不自动执行**：必须先由人看清再决定。
 * 孤儿文件可能来自"文件比数据库新"，失效记录也可能是磁盘临时不可用造成的
 * —— 自动清理会把还能救的东西删掉。
 */

require __DIR__ . '/../src/bootstrap.php';
require_admin_json();
csrf_require_post();

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'check';

if ($action === 'purge') {
    // 重新算一次，只删除**本次仍判定为孤儿**的文件，
    // 不接受客户端传来的文件列表（避免被诱导删除任意文件）。
    $res = images_consistency_check(500);
    $p = images_purge_orphans($res['orphan_files']);
    log_event('info', 'orphans_purged', [
        'deleted' => $p['deleted'],
        'failed'  => $p['failed'],
        'freed'   => $p['freed'],
    ]);
    json_out([
        'ok'      => true,
        'action'  => 'purge',
        'deleted' => $p['deleted'],
        'freed'   => $p['freed'],
        'failed'  => $p['failed'],
    ]);
}

if ($action === 'drop_broken') {
    // 同样重新计算：只删除**本次仍判定为缺失文件**的记录。
    $res   = images_consistency_check(2000);
    $names = $res['missing_files'];

    $deleted = 0;
    $failed  = 0;
    if ($names !== []) {
        $del = db()->prepare('DELETE FROM images WHERE filename = ?');
        foreach ($names as $fn) {
            // 二次校验命名规则：不信任任何外部来源的文件名
            if (preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/i', (string)$fn) !== 1) {
                $failed++;
                continue;
            }
            $del->execute([(string)$fn]);
            if ($del->rowCount() > 0) {
                $deleted++;
                // 顺带清掉可能残留的缩略图
                @unlink(THUMB_DIR . '/' . (string)$fn);
            } else {
                $failed++;
            }
        }
    }

    log_event('info', 'broken_records_dropped', ['deleted' => $deleted, 'failed' => $failed]);
    json_out(['ok' => true, 'action' => 'drop_broken', 'deleted' => $deleted, 'failed' => $failed]);
}

$res = images_consistency_check(200);
log_event('info', 'consistency_checked', [
    'files'   => $res['files'],
    'records' => $res['records'],
    'orphan'  => $res['orphan_count'],
    'missing' => $res['missing_count'],
]);

json_out(['ok' => true, 'action' => 'check'] + $res);
