<?php
declare(strict_types=1);

/**
 * 上传校验链（§13 §14 §15 §18 §19）。
 *
 * 五层防护（§14）：
 *   1. MIME 白名单（finfo，不信 $_FILES['type']）
 *   2. 扩展名白名单（由 MIME 反查，忽略用户文件名）
 *   3. 文件内容验证（getimagesize 且与 MIME 一致）
 *   4. 服务端生成随机文件名
 *   5. 上传目录由 Nginx 禁止执行 PHP（见 deploy/nginx.conf）
 *
 * 绝不使用用户提供的文件名或扩展名落盘（§15 §27）。
 */

/** 允许的 MIME -> 扩展名映射。这是唯一的类型真相来源。 */
function upload_allowed_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
}

/** getimagesize 的 IMAGETYPE_* 常量 -> MIME，用于交叉验证。 */
function upload_imagetype_to_mime(int $type): ?string
{
    switch ($type) {
        case IMAGETYPE_JPEG: return 'image/jpeg';
        case IMAGETYPE_PNG:  return 'image/png';
        case IMAGETYPE_GIF:  return 'image/gif';
        case IMAGETYPE_WEBP: return 'image/webp';
        default: return null;
    }
}

/**
 * 处理一次上传。
 * 返回 ['ok'=>bool, 'error'=>string, 'data'=>array|null]
 * error 为面向用户的固定文案，不含路径与堆栈（§37）。
 */
function handle_upload(array $file, array $cfg): array
{
    $generic = fail_message();
    $maxBytes = (int)$cfg['max_file_bytes'];

    // ---- 步 1：上传状态 ----
    // 注意：$_FILES 的结构完全由客户端决定。攻击者可用 name="image[]" 让
    // $file['error'] 变成数组。此时若写 (string)$err 会触发 PHP 的
    // "Array to string conversion" 警告，被我们的错误处理器写入日志——
    // 攻击者因此可以刷请求来灌满 data/logs/app.log（日志放大/磁盘填充）。
    // 因此这里先做类型判定，绝不对可能为数组的值做字符串强转。
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_string($err) && ctype_digit($err)) {
        $err = (int)$err;
    }
    if (!is_int($err)) {
        log_event('warning', 'upload_failed', ['step' => 'malformed_files']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器允许的大小。',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单允许的大小。',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分，请重试。',
            UPLOAD_ERR_NO_FILE    => '没有选择文件。',
            UPLOAD_ERR_NO_TMP_DIR => $generic,
            UPLOAD_ERR_CANT_WRITE => $generic,
            UPLOAD_ERR_EXTENSION  => $generic,
        ];
        log_event('warning', 'upload_failed', ['step' => 'status', 'code' => $err]);
        return ['ok' => false, 'error' => $map[$err] ?? $generic, 'data' => null];
    }

    // 同理：$file['tmp_name'] 也可能是数组（name="image[]"），
    // 因此先做类型判定再使用，避免 "Array to string conversion" 警告。
    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp)) {
        log_event('warning', 'upload_failed', ['step' => 'malformed_tmp_name']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }

    // ---- 步 2：确认是真正的上传文件（防伪造路径） ----
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        log_event('warning', 'upload_failed', ['step' => 'is_uploaded_file']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }

    // ---- 步 3：大小（在 getimagesize 之前，防大文件耗内存） ----
    $size = @filesize($tmp);
    if ($size === false || $size <= 0) {
        log_event('warning', 'upload_failed', ['step' => 'empty']);
        return ['ok' => false, 'error' => '文件为空。', 'data' => null];
    }
    if ($size > $maxBytes) {
        log_event('warning', 'upload_failed', ['step' => 'too_large', 'size' => $size]);
        return ['ok' => false, 'error' => '文件超过 ' . format_bytes($maxBytes) . ' 限制。', 'data' => null];
    }
    // 同时对照 PHP 自身限制，避免配置不一致造成误判
    if ($size > upload_ini_bytes('upload_max_filesize') || $size > upload_ini_bytes('post_max_size')) {
        log_event('warning', 'upload_failed', ['step' => 'ini_limit', 'size' => $size]);
        return ['ok' => false, 'error' => '文件超过服务器限制。', 'data' => null];
    }

    // ---- 步 4：MIME 白名单（finfo，绝不信 $_FILES['type']） ----
    $allowed = upload_allowed_types();
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        log_event('error', 'upload_failed', ['step' => 'finfo_open']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }
    $mime = @finfo_file($finfo, $tmp);
    @finfo_close($finfo);
    if (!is_string($mime) || !isset($allowed[$mime])) {
        log_event('warning', 'upload_failed', ['step' => 'mime', 'mime' => is_string($mime) ? $mime : '']);
        return ['ok' => false, 'error' => '只允许 JPG、PNG、GIF、WebP 图片。', 'data' => null];
    }

    // ---- 步 5：扩展名由 MIME 反查，用户提供的文件名完全不参与 ----
    $ext = $allowed[$mime];

    // ---- 步 6：内容验证（拦伪装成图片的脚本） ----
    $info = @getimagesize($tmp);
    if ($info === false || !isset($info[0], $info[1], $info[2])) {
        log_event('warning', 'upload_failed', ['step' => 'getimagesize', 'mime' => $mime]);
        return ['ok' => false, 'error' => '文件不是有效的图片。', 'data' => null];
    }
    $width  = (int)$info[0];
    $height = (int)$info[1];
    $itypeMime = upload_imagetype_to_mime((int)$info[2]);
    if ($itypeMime === null || $itypeMime !== $mime) {
        log_event('warning', 'upload_failed', ['step' => 'type_mismatch', 'mime' => $mime, 'itype' => (int)$info[2]]);
        return ['ok' => false, 'error' => '文件类型与内容不一致。', 'data' => null];
    }
    if ($width < 1 || $height < 1) {
        return ['ok' => false, 'error' => '图片尺寸无效。', 'data' => null];
    }

    // ---- 步 7：哈希 + 随机文件名 ----
    $sha = @hash_file('sha256', $tmp);
    $sha = is_string($sha) ? $sha : '';
    $filename = random_filename($ext);

    // ---- 步 8：落盘（路径完全由程序决定） ----
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true)) {
        log_event('error', 'upload_failed', ['step' => 'mkdir_upload']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }
    // 落盘路径由程序自身决定，用户输入完全不参与（§27）。
    // 这里再加一道断言：$filename 必须符合 safe_filename() 的白名单形态。
    // 目的是把「$filename 来自 random_filename()」这个隐式前提变成显式校验——
    // 若将来有人修改了命名逻辑，这一行会立刻失败而不是静默产生不可控路径。
    if (safe_filename($filename) === null) {
        log_event('error', 'upload_failed', ['step' => 'filename_invariant']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }

    $dest = UPLOAD_DIR . '/' . $filename;
    if (!@move_uploaded_file($tmp, $dest)) {
        log_event('error', 'upload_failed', ['step' => 'move']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }
    @chmod($dest, 0664);

    // ---- 剥离隐私元数据 ----
    //
    // 手机拍摄的图片通常带 GPS 坐标。原图会被公开直链直接访问，
    // 于是**拍摄地点也一并公开**。这里在上传时去掉这类元数据。
    //
    // 关键：不是重编码，而是**只删元数据段**（见 src/metadata.php）。
    // 压缩像素数据一个字节都不会变，因此仍然满足"原图不做处理"的承诺。
    if (!empty($cfg['strip_metadata'])) {
        $meta = meta_strip($dest, $mime);
        if (!$meta['ok']) {
            // 剥不掉就留着，绝不因为清理元数据而损坏图片
            log_event('warning', 'metadata_strip_failed', ['reason' => $meta['reason']]);
        } elseif ($meta['changed']) {
            log_event('info', 'metadata_stripped', ['bytes' => $meta['removed']]);
        }
    }

    // 落盘后复核：文件确实存在且仍是有效图片。
    // 元数据剥离之后才做这一步 —— 若剥离过程损坏了文件，这里会拦下来。
    $recheck = @getimagesize($dest);
    if ($recheck === false || (int)$recheck[0] !== $width || (int)$recheck[1] !== $height) {
        @unlink($dest);
        log_event('error', 'upload_failed', ['step' => 'post_move_recheck']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }

    // ---- 缩略图（失败不影响原图保存） ----
    $thumbOk = 0;
    $thumbEdge = (int)($cfg['thumb_max_edge'] ?? 0);
    if ($thumbEdge > 0 && function_exists('imagecreatetruecolor')) {
        if (!is_dir(THUMB_DIR)) {
            @mkdir(THUMB_DIR, 0775, true);
        }
        if (thumb_create($dest, THUMB_DIR . '/' . $filename, $thumbEdge, $width, $height)) {
            $thumbOk = 1;
        }
    }

    // ---- 步 9：写库（失败则回滚已落盘文件） ----
    // 同样避免对可能为数组的 $file['name'] 做字符串强转。
    // 取不到字符串时用中性名，不影响落盘文件名（那个完全由服务端生成）。
    $rawName = $file['name'] ?? '';
    $originalName = upload_sanitize_original_name(is_string($rawName) ? $rawName : '');
    try {
        $id = image_insert([
            'filename'      => $filename,
            'original_name' => $originalName,
            'mime'          => $mime,
            'size'          => $size,
            'width'         => $width,
            'height'        => $height,
            'sha256'        => $sha,
            'thumb'         => $thumbOk,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        @unlink($dest);
        if ($thumbOk === 1) { @unlink(THUMB_DIR . '/' . $filename); }
        log_event('error', 'upload_failed', ['step' => 'db']);
        return ['ok' => false, 'error' => $generic, 'data' => null];
    }

    log_event('info', 'upload_success', ['id' => $id, 'mime' => $mime, 'size' => $size, 'thumb' => $thumbOk]);

    return [
        'ok'    => true,
        'error' => '',
        'data'  => [
            'id'       => $id,
            'filename' => $filename,
            'url'      => image_url($filename),
            // 绝对地址供"复制直链"使用（相对路径粘到站外没有意义）
            'abs_url'  => absolute_url('/uploads/' . $filename),
            'thumb'    => $thumbOk === 1 ? thumb_url($filename) : image_url($filename),
            'width'    => $width,
            'height'   => $height,
            'size'     => $size,
        ],
    ];
}

/**
 * 净化原始文件名，仅用于展示（§26 输出时仍需 e()）。
 * 去掉路径成分与控制字符，限制长度。
 */
function upload_sanitize_original_name(string $name): string
{
    // 仅保留"看起来像文件名"的部分。original_name 只用于展示，不参与任何路径拼接。

    // 1) 去掉路径成分：反斜杠统一成正斜杠，再取最后一段
    $name = str_replace('\\', '/', $name);
    $pos = strrpos($name, '/');
    if ($pos !== false) {
        $name = substr($name, $pos + 1);
    }

    // 2) 去掉 ASCII 控制字符（含 NUL / CR / LF / TAB）。
    //    这里刻意不使用 /u 修饰符：文件名可能包含非法 UTF-8 字节（可被构造），
    //    而带 /u 的 preg_replace 遇到非法 UTF-8 会返回 null，
    //    若再配合默认值会把整个文件名清空——用一个静默失败换掉另一个。
    //    ASCII 控制字符都是单字节，按字节范围过滤即可。
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    if ($name === null) {
        $name = '';
    }

    // 3) 去掉 Unicode 双向控制字符。
    //    U+202E（RIGHT-TO-LEFT OVERRIDE）等可让 "evil\u202Egpj.php" 在界面上
    //    显示为 "evilphp.jpg"，属于文件名欺骗。虽然输出经过 e() 转义、
    //    不存在 XSS，但管理员是依据这个名字决定删哪张图的，因此必须过滤。
    $stripped = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $name);
    if ($stripped !== null) {
        $name = $stripped;
    }

    // 4) 限长。mb_substr 按字符计，避免把多字节字符截成半个。
    if (function_exists('mb_substr')) {
        $sub = mb_substr($name, 0, 120, 'UTF-8');
        $name = ($sub === false) ? '' : $sub;
    } else {
        $name = substr($name, 0, 120);
    }

    return $name === '' ? 'unnamed' : $name;
}

/** 解析 php.ini 的简写字节值（如 10M）。 */
function upload_ini_bytes(string $key): int
{
    $v = (string)ini_get($key);
    if ($v === '') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($v, -1));
    $num = (int)$v;
    switch ($unit) {
        case 'g': return $num * 1073741824;
        case 'm': return $num * 1048576;
        case 'k': return $num * 1024;
        default:  return $num > 0 ? $num : PHP_INT_MAX;
    }
}

/** 删除图片文件与缩略图。文件不存在不算错误（§23）。 */
function upload_remove_files(string $filename): void
{
    $safe = safe_filename($filename);
    if ($safe === null) {
        return;
    }
    $orig = UPLOAD_DIR . '/' . $safe;
    $thumb = THUMB_DIR . '/' . $safe;
    if (is_file($orig))  { @unlink($orig); }
    if (is_file($thumb)) { @unlink($thumb); }
}
