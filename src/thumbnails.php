<?php
declare(strict_types=1);

/**
 * GD 缩略图（§7 首页性能）。
 * 仅缩放，不压缩原图（§20）。GD 不可用时调用方自行降级。
 */

/** 巨像素防护：超过此像素数则跳过缩略图，避免解压炸弹耗尽内存。 */
const THUMB_MAX_PIXELS = 50000000;

/**
 * 生成缩略图。
 * 返回 true 表示已写出目标文件。
 */
function thumb_create(string $srcPath, string $destPath, int $maxEdge, int $srcW = 0, int $srcH = 0): bool
{
    if ($maxEdge < 16 || $maxEdge > 4096) {
        return false;
    }
    if (!is_file($srcPath) || !is_readable($srcPath)) {
        return false;
    }

    if ($srcW < 1 || $srcH < 1) {
        $info = @getimagesize($srcPath);
        if ($info === false) {
            return false;
        }
        $srcW = (int)$info[0];
        $srcH = (int)$info[1];
    }

    // 尺寸必须为正，否则下面的除法会触发 DivisionByZeroError
    if ($srcW < 1 || $srcH < 1) {
        log_event('warning', 'thumb_skipped', ['reason' => 'zero_dimension', 'w' => $srcW, 'h' => $srcH]);
        return false;
    }

    // 解压炸弹防护
    if ($srcW * $srcH > THUMB_MAX_PIXELS) {
        log_event('warning', 'thumb_skipped', ['reason' => 'too_many_pixels', 'w' => $srcW, 'h' => $srcH]);
        return false;
    }

    $ratio = min($maxEdge / $srcW, $maxEdge / $srcH, 1.0);
    $dstW = max(1, (int)round($srcW * $ratio));
    $dstH = max(1, (int)round($srcH * $ratio));

    // 原图已小于阈值：不生成缩略图，调用方回退原图
    if ($ratio >= 1.0) {
        return false;
    }

    $data = @file_get_contents($srcPath);
    if ($data === false || $data === '') {
        return false;
    }
    $src = @imagecreatefromstring($data);
    unset($data);
    if ($src === false) {
        return false;
    }

    $dst = @imagecreatetruecolor($dstW, $dstH);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }

    // 输出格式按目标扩展名决定（也决定是否需要保留透明通道）
    $ext = strtolower((string)pathinfo($destPath, PATHINFO_EXTENSION));

    // 只有 PNG / GIF / WebP 支持透明；JPEG 不支持。
    // 对 JPEG 关闭 alpha 处理是必须的：JPEG 没有 alpha 通道，
    // 若预填充了透明像素而未全部覆盖，会被烘焙成黑边。
    $keepAlpha = in_array($ext, ['png', 'gif', 'webp'], true);
    if ($keepAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        }
    }

    $ok = @imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    if ($ok) {
        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        switch ($ext) {
            case 'png':
                $ok = @imagepng($dst, $destPath, 8);
                break;
            case 'gif':
                $ok = @imagegif($dst, $destPath);
                break;
            case 'webp':
                $ok = function_exists('imagewebp') ? @imagewebp($dst, $destPath, 80) : false;
                break;
            default:
                $ok = @imagejpeg($dst, $destPath, 82);
                break;
        }
        if ($ok && is_file($destPath)) {
            @chmod($destPath, 0664);
        }
    }

    imagedestroy($dst);
    imagedestroy($src);

    return (bool)$ok && is_file($destPath);
}

/**
 * 重建缩略图（供后台按钮与命令行共用）。
 *
 * 为什么需要：缩略图只在**上传时**生成一次。若之后修改了 `thumb_max_edge`，
 * 已有图片仍是旧尺寸；若 `uploads/thumbs/` 被误删或未随备份恢复，
 * 首页会回退加载原图（流量变大）。这个函数让这两种情况都能修复。
 *
 * 实现要点：
 *   - **分批处理**：每批上限由调用方给出，避免大图库把请求跑超时。
 *   - 只处理原图仍然存在的记录；原图缺失的记为 `missing`，不报错。
 *   - 原图小于阈值时删除已有缩略图（因为本就不该有），并把 `thumb` 置 0。
 *   - 不需要重建的记录（跳过条件）单独计数，便于前端展示进度。
 *
 * @param int $offset 从第几条开始（按 id 升序，稳定分页）
 * @param int $limit  本批最多处理多少条
 * @return array{processed:int,built:int,skipped:int,missing:int,failed:int,next:int,total:int,done:bool}
 */
function thumb_rebuild_batch(int $offset, int $limit): array
{
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    $total = images_count();

    $stmt = db()->prepare(
        'SELECT id, filename, width, height, thumb FROM images ORDER BY id ASC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $maxEdge = (int)cfg()['thumb_max_edge'];

    $built = 0; $skipped = 0; $missing = 0; $failed = 0;

    foreach ($rows as $row) {
        $safe = safe_filename((string)$row['filename']);
        if ($safe === null) {
            $failed++;
            continue;
        }
        $src   = UPLOAD_DIR . '/' . $safe;
        $thumb = THUMB_DIR . '/' . $safe;

        if (!is_file($src)) {
            // 原图不在了：删掉可能残留的缩略图，并把标记归零
            @unlink($thumb);
            if ((int)$row['thumb'] !== 0) {
                $up = db()->prepare('UPDATE images SET thumb = 0 WHERE id = :id');
                $up->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
                $up->execute();
            }
            $missing++;
            continue;
        }

        $w = (int)$row['width'];
        $h = (int)$row['height'];

        // 原图本来就小于阈值：不应有缩略图
        if ($maxEdge >= 16 && ($w < 1 || $h < 1 || ($w <= $maxEdge && $h <= $maxEdge))) {
            $had = is_file($thumb);
            if ($had) { @unlink($thumb); }
            if ((int)$row['thumb'] !== 0 || $had) {
                $up = db()->prepare('UPDATE images SET thumb = 0 WHERE id = :id');
                $up->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
                $up->execute();
            }
            $skipped++;
            continue;
        }

        // 重建：先删旧的，避免"生成失败但旧文件还在"造成误判
        @unlink($thumb);
        $ok = thumb_create($src, $thumb, $maxEdge, $w, $h);

        $flag = $ok ? 1 : 0;
        if ($ok) { $built++; } else { $failed++; }

        $up = db()->prepare('UPDATE images SET thumb = :t WHERE id = :id');
        $up->bindValue(':t', $flag, PDO::PARAM_INT);
        $up->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
        $up->execute();
    }

    $processed = count($rows);
    $next = $offset + $processed;
    $done = ($processed === 0) || ($next >= $total);

    return [
        'processed' => $processed,
        'built'     => $built,
        'skipped'   => $skipped,
        'missing'   => $missing,
        'failed'    => $failed,
        'next'      => $done ? $total : $next,
        'total'     => $total,
        'done'      => $done,
    ];
}
