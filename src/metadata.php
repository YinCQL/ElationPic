<?php
declare(strict_types=1);

/**
 * 上传前剥离图片中的隐私元数据（EXIF / GPS / XMP / IPTC）。
 *
 * ── 为什么不用 GD 重编码 ──────────────────────────────────────────
 * 最直接的做法是 imagecreatefromstring() 读进来再 imagejpeg() 写出去，
 * 这样确实能去掉全部元数据。但它**重新压缩了像素** —— 实测质量 92 时
 * 体积变化约 -11%，且每次处理都会累积一代损失。
 * 本项目对原图的承诺是「不做任何处理」，重编码违背了这一点。
 *
 * ── 本模块的做法：只删元数据段，像素字节完全不动 ──────────────────
 * JPEG / PNG / WebP 都是**分段的容器格式**。元数据位于独立的段里，
 * 把这些段整段移除，剩下的压缩像素数据一个字节都不会变。
 * 这正是 `jpegtran -copy none` 的思路。
 *
 * ── 保留什么 ─────────────────────────────────────────────────────
 * 不是所有段都该删：
 *   APP0 (JFIF)   —— 2 字节标识，不含隐私，**保留**（删了有些解码器会挑食）
 *   APP2 (ICC)    —— 颜色配置文件。删掉会导致**颜色失真**，**保留**
 *   其余 APPn、COM 注释段 —— 全部移除（EXIF、GPS、XMP、IPTC、缩略图都在这里）
 *
 * ── 失败时的行为 ─────────────────────────────────────────────────
 * 解析不了就**原样保留**并记日志。宁可留下元数据，也不能损坏用户的图片。
 */

/** 每个格式的段结构上限，防止畸形文件导致无限循环。 */
const META_MAX_SEGMENTS = 4096;

/**
 * 剥离元数据。
 *
 * @param string $path 图片文件路径（就地改写）
 * @param string $mime 已由 finfo 检测出的真实 MIME
 * @return array{ok:bool,changed:bool,removed:int,saved:int,reason:string}
 *         ok=false 表示解析失败，文件未被改动
 */
function meta_strip(string $path, string $mime): array
{
    $fail = static function (string $why): array {
        return ['ok' => false, 'changed' => false, 'removed' => 0, 'saved' => 0, 'reason' => $why];
    };

    if (!is_file($path) || !is_readable($path)) {
        return $fail('unreadable');
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $fail('empty');
    }
    $before = strlen($raw);

    switch ($mime) {
        case 'image/jpeg':
            $out = meta_strip_jpeg($raw);
            break;
        case 'image/png':
            $out = meta_strip_png($raw);
            break;
        case 'image/webp':
            $out = meta_strip_webp($raw);
            break;
        default:
            // GIF 的注释/应用扩展块理论上也能带元数据，但极罕见，
            // 且 GIF 的块结构与上面三者差异较大，这里不处理。
            return ['ok' => true, 'changed' => false, 'removed' => 0, 'saved' => 0, 'reason' => 'unsupported_type'];
    }

    if ($out === null) {
        return $fail('parse_error');
    }

    $changed = ($out !== $raw);
    if ($changed) {
        // 原子写入：先写临时文件再改名，避免中途失败留下半截文件
        $tmp = $path . '.meta.tmp';
        if (@file_put_contents($tmp, $out) !== strlen($out)) {
            @unlink($tmp);
            return $fail('write_failed');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return $fail('rename_failed');
        }
    }

    return [
        'ok'      => true,
        'changed' => $changed,
        'removed' => max(0, $before - strlen($out)),
        'saved'   => max(0, $before - strlen($out)),
        'reason'  => $changed ? 'stripped' : 'nothing_to_strip',
    ];
}

/**
 * JPEG：删除除 APP0(JFIF) 与 APP2(ICC) 之外的所有 APPn 段，以及 COM 注释段。
 *
 * JPEG 结构：每个段以 0xFF + 标记字节开始，后跟 2 字节大端长度（含长度本身）。
 * 0xFFDA（SOS）之后是熵编码的像素数据，到文件末尾为止，**不再有段结构**。
 * 因此只需扫描到 SOS 之前的部分。
 *
 * @return string|null null 表示解析失败
 */
function meta_strip_jpeg(string $raw): ?string
{
    $len = strlen($raw);
    // SOI (0xFFD8)
    if ($len < 4 || $raw[0] !== "\xFF" || $raw[1] !== "\xD8") {
        return null;
    }

    $out = "\xFF\xD8";
    $i = 2;
    $segments = 0;

    while ($i + 1 < $len) {
        if (++$segments > META_MAX_SEGMENTS) {
            return null;   // 畸形文件
        }

        // 段必须以 0xFF 开头；填充字节 0xFF 可以连续出现
        if ($raw[$i] !== "\xFF") {
            return null;
        }
        while ($i < $len && $raw[$i] === "\xFF") {
            $i++;
        }
        if ($i >= $len) {
            return null;
        }

        // 注意：这里是**字符**而非整数，必须转成码值再比较，
        // 否则 "\xDA" >= 0xD0 这类数字比较会得到毫无意义的结果。
        $marker = ord($raw[$i]);
        $markerPos = $i - 1;
        $i++;

        // SOS：之后是像素数据，原样拷贝剩余全部内容并结束
        if ($marker === 0xDA) {
            return $out . substr($raw, $markerPos);
        }
        // EOI：文件提前结束
        if ($marker === 0xD9) {
            return $out . "\xFF\xD9";
        }
        // 无长度字段的独立标记（RSTn / TEM），直接拷贝
        if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
            $out .= "\xFF" . chr($marker);
            continue;
        }

        if ($i + 1 >= $len) {
            return null;
        }
        $segLen = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);
        if ($segLen < 2 || $i + $segLen > $len) {
            return null;
        }

        $segment = substr($raw, $markerPos, $segLen + 2);
        $i += $segLen;

        if (meta_jpeg_keep($marker)) {
            $out .= $segment;
        }
    }

    return null;   // 没找到 SOS/EOI，说明文件不完整
}

/**
 * 判断某个 JPEG 标记对应的段是否应当保留。
 *
 * 保留：
 *   0xE0 APP0 —— JFIF 标识
 *   0xE2 APP2 —— ICC 颜色配置（删了会颜色失真）
 *   0xDB DQT 量化表 / 0xC4 DHT 霍夫曼表 / 0xC0-0xCF 帧头 —— 解码必需
 *   0xDD DRI 重启间隔
 * 移除：
 *   0xE1 APP1 —— EXIF（含 GPS）与 XMP
 *   0xE3-0xEF APP3-APP15 —— IPTC、Photoshop 资源块等
 *   0xFE COM —— 注释
 */
function meta_jpeg_keep(int $marker): bool
{
    if ($marker === 0xE0) { return true; }   // APP0 JFIF
    if ($marker === 0xE2) { return true; }   // APP2 ICC
    if ($marker === 0xFE) { return false; }  // COM
    if ($marker >= 0xE1 && $marker <= 0xEF) { return false; }  // APP1, APP3-15
    // 其余（DQT/DHT/SOF/DRI 等）保留
    return true;
}

/**
 * PNG：删除携带元数据的辅助块 tEXt / zTXt / iTXt / eXIf。
 *
 * PNG 由「长度(4) + 类型(4) + 数据 + CRC(4)」的块序列组成。
 * 块是独立且自校验的，整块移除不会影响像素数据（IDAT）。
 *
 * 注意 gAMA / cHRM / sRGB / iCCP 等**颜色相关块必须保留**，
 * 删除会导致颜色显示不一致。
 *
 * @return string|null null 表示解析失败
 */
function meta_strip_png(string $raw): ?string
{
    $sig = "\x89PNG\r\n\x1A\n";
    if (strncmp($raw, $sig, 8) !== 0) {
        return null;
    }

    // 要删除的块类型（都是纯元数据）
    $drop = ['tEXt' => 1, 'zTXt' => 1, 'iTXt' => 1, 'eXIf' => 1];

    $out = $sig;
    $i = 8;
    $len = strlen($raw);
    $blocks = 0;

    while ($i + 8 <= $len) {
        if (++$blocks > META_MAX_SEGMENTS) {
            return null;
        }
        $dataLen = unpack('N', substr($raw, $i, 4))[1];
        $type = substr($raw, $i + 4, 4);
        $total = 4 + 4 + $dataLen + 4;
        if ($dataLen < 0 || $i + $total > $len) {
            return null;
        }

        if (!isset($drop[$type])) {
            $out .= substr($raw, $i, $total);
        }

        $i += $total;
        if ($type === 'IEND') {
            // IEND 之后若还有字节（少见），原样保留
            if ($i < $len) { $out .= substr($raw, $i); }
            return $out;
        }
    }

    return null;   // 没有 IEND
}

/**
 * WebP：从 RIFF 容器中删除 EXIF 与 XMP 块。
 *
 * WebP 是 RIFF 结构："RIFF" + 文件大小(4, 小端) + "WEBP" + 若干块。
 * 每块：FourCC(4) + 大小(4, 小端) + 数据 + 补齐到偶数长度。
 *
 * 注意：删除块后 RIFF 头里记录的总长度**必须同步更新**，否则文件损坏。
 *
 * @return string|null null 表示解析失败
 */
function meta_strip_webp(string $raw): ?string
{
    $len = strlen($raw);
    if ($len < 12 || substr($raw, 0, 4) !== 'RIFF' || substr($raw, 8, 4) !== 'WEBP') {
        return null;
    }

    $out = '';
    $i = 12;
    $chunks = 0;

    while ($i + 8 <= $len) {
        if (++$chunks > META_MAX_SEGMENTS) {
            return null;
        }
        $fourcc = substr($raw, $i, 4);
        $size = unpack('V', substr($raw, $i + 4, 4))[1];
        $padded = $size + ($size % 2);            // 块数据补齐到偶数
        if ($i + 8 + $padded > $len) {
            return null;
        }

        if ($fourcc !== 'EXIF' && $fourcc !== 'XMP ') {
            $out .= substr($raw, $i, 8 + $padded);
        }
        $i += 8 + $padded;
    }

    if ($out === '') {
        return null;
    }

    // 重建 RIFF 头：总长度 = 4("WEBP") + 所有块
    return 'RIFF' . pack('V', strlen($out) + 4) . 'WEBP' . $out;
}
