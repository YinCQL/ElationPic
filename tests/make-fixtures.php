<?php
declare(strict_types=1);

/**
 * 生成 §45 上传测试所需的全部素材。
 *
 * 用法（在项目根目录）：
 *   php tests/make-fixtures.php
 *
 * 输出目录：tests/fixtures/
 *
 * 这些素材用于验证上传链路能否拒绝恶意文件。
 * 其中部分文件包含 PHP 代码片段，但它们只是测试用样本，
 * 且永远不应被保存到 Web 可访问目录 —— 上传后必须被程序拒绝。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit(1);
}

$dir = __DIR__ . '/fixtures';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    fwrite(STDERR, "无法创建目录: $dir\n");
    exit(1);
}

$made = [];
function put(string $dir, string $name, string $bytes, array &$made): void
{
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $bytes) === false) {
        fwrite(STDERR, "写入失败: $name\n");
        return;
    }
    $made[] = [$name, strlen($bytes)];
}

// ---------------------------------------------------------------------------
// 1) 合法图片（用 GD 生成，确保是真图）
// ---------------------------------------------------------------------------
$gd = function_exists('imagecreatetruecolor');

if ($gd) {
    // JPEG
    $im = imagecreatetruecolor(240, 160);
    $bg = imagecolorallocate($im, 47, 111, 235);
    imagefilledrectangle($im, 0, 0, 240, 160, $bg);
    $fg = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 70, 70, 'TEST JPG', $fg);
    ob_start(); imagejpeg($im, null, 90); $jpg = (string)ob_get_clean(); imagedestroy($im);
    put($dir, 'ok.jpg', $jpg, $made);

    // PNG（带透明）
    $im = imagecreatetruecolor(200, 200);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, 200, 200, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, 100, 100, 160, 160, imagecolorallocatealpha($im, 26, 127, 75, 20));
    ob_start(); imagepng($im, null, 8); $png = (string)ob_get_clean(); imagedestroy($im);
    put($dir, 'ok.png', $png, $made);

    // GIF
    $im = imagecreatetruecolor(120, 90);
    imagefilledrectangle($im, 0, 0, 120, 90, imagecolorallocate($im, 200, 60, 60));
    ob_start(); imagegif($im, null); $gif = (string)ob_get_clean(); imagedestroy($im);
    put($dir, 'ok.gif', $gif, $made);

    // WebP（GD 需带 webp 支持）
    if (function_exists('imagewebp')) {
        $im = imagecreatetruecolor(200, 150);
        imagefilledrectangle($im, 0, 0, 200, 150, imagecolorallocate($im, 90, 90, 200));
        ob_start(); imagewebp($im, null, 85); $webp = (string)ob_get_clean(); imagedestroy($im);
        put($dir, 'ok.webp', $webp, $made);
    } else {
        fwrite(STDOUT, "[跳过] 本机 GD 不支持 WebP，未生成 ok.webp\n");
    }
} else {
    fwrite(STDOUT, "[警告] 未启用 GD，无法生成合法图片素材。请手工放入 ok.jpg / ok.png / ok.gif / ok.webp\n");
}

// ---------------------------------------------------------------------------
// 2) 超大文件（11 MB，超过 10 MB 上限）
//
// 刻意不使用 GD 生成大图：GD 生成 4200x4200 真彩图需约 70-140 MB 内存，
// 且 imagecolorallocate 为每个像素分配颜色会导致内存耗尽。
// 这里的做法是：用一张小图的有效 JPEG 数据作为头部，再填充到 11 MB。
// 填充字节位于 JPEG EOI 标记之后，因此文件仍是"可解码的 JPEG"，
// getimagesize() 依然能读出尺寸 —— 这正是我们想测的：
// 一个内容合法但超过大小上限的文件，必须在"大小检查"这一步被拒绝。
// ---------------------------------------------------------------------------
if ($gd) {
    $im = imagecreatetruecolor(600, 400);
    $bg = imagecolorallocate($im, 40, 90, 180);
    imagefilledrectangle($im, 0, 0, 600, 400, $bg);
    // 加入一些噪点块，让 JPEG 不至于太小
    for ($x = 0; $x < 600; $x += 4) {
        for ($y = 0; $y < 400; $y += 4) {
            $c = imagecolorallocate($im, ($x * 7) % 256, ($y * 11) % 256, ($x + $y) % 256);
            imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
        }
    }
    ob_start(); imagejpeg($im, null, 95); $bigBase = (string)ob_get_clean(); imagedestroy($im);

    $target = 11 * 1048576;
    if (strlen($bigBase) < $target) {
        $bigBase .= str_repeat("\0", $target - strlen($bigBase));
    }
    put($dir, 'big.jpg', $bigBase, $made);
    unset($bigBase);
}

// 另一种超大文件：纯填充（无有效图片结构，用于验证大小检查先于内容检查）
put($dir, 'big_filler.bin', str_repeat('A', 11 * 1048576), $made);

// ---------------------------------------------------------------------------
// 3) 非图片
// ---------------------------------------------------------------------------
put($dir, 'notimage.txt', "This is plain text, not an image.\n", $made);

// ---------------------------------------------------------------------------
// 4) 改扩展名的恶意文件：内容是 PHP，扩展名 .jpg
// ---------------------------------------------------------------------------
put($dir, 'evil.php.jpg', "<?php echo 'PWNED_' . (6*7); ?>\n", $made);

// ---------------------------------------------------------------------------
// 5) PHP 伪装图片：真的 GIF 头 + PHP 代码（经典 GIF89a 绕过）
// ---------------------------------------------------------------------------
$gifHeader = "GIF89a" . chr(1) . chr(0) . chr(1) . chr(0) . chr(0x80) . chr(0) . chr(0);
$shell = $gifHeader . "<?php echo 'PWNED_' . (6*7); ?>\n";
put($dir, 'shell.gif', $shell, $made);

// JPEG 头 + PHP
put($dir, 'shell.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 12) . "<?php echo 'PWNED_' . (6*7); ?>\n", $made);

// ---------------------------------------------------------------------------
// 6) 空文件
// ---------------------------------------------------------------------------
put($dir, 'empty.jpg', '', $made);

// ---------------------------------------------------------------------------
// 7) 损坏图片：有效 JPEG 头但内容被截断/破坏
// ---------------------------------------------------------------------------
if ($gd) {
    $im = imagecreatetruecolor(300, 300);
    imagefilledrectangle($im, 0, 0, 300, 300, imagecolorallocate($im, 20, 20, 20));
    ob_start(); imagejpeg($im, null, 85); $tmp = (string)ob_get_clean(); imagedestroy($im);
    // 保留前 40 字节后截断，并破坏后续
    put($dir, 'broken.jpg', substr($tmp, 0, 40) . "GARBAGE", $made);
}

// ---------------------------------------------------------------------------
// 8) 目录穿越文件名素材（内容合法，但文件名恶意）
// 上传时由程序改用随机名；此素材用于验证文件名不参与服务器路径
// ---------------------------------------------------------------------------
if ($gd) {
    $im = imagecreatetruecolor(80, 80);
    imagefilledrectangle($im, 0, 0, 80, 80, imagecolorallocate($im, 120, 200, 120));
    ob_start(); imagejpeg($im, null, 80); $j = (string)ob_get_clean(); imagedestroy($im);
    put($dir, 'good_for_traversal.jpg', $j, $made);
}

// ---------------------------------------------------------------------------
// 9) 双扩展名 / 特殊字符文件名素材
// ---------------------------------------------------------------------------
put($dir, 'double_ext.php.png', "<?php echo 'PWNED_42'; ?>", $made);

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n生成完成，输出目录: $dir\n\n");
fwrite(STDOUT, sprintf("%-30s %12s\n", '文件名', '字节'));
fwrite(STDOUT, str_repeat('-', 44) . "\n");
foreach ($made as $m) {
    fwrite(STDOUT, sprintf("%-30s %12s\n", $m[0], number_format($m[1])));
}
fwrite(STDOUT, "\n共 " . count($made) . " 个文件。\n");
fwrite(STDOUT, "\n下一步：\n");
fwrite(STDOUT, "  powershell -ExecutionPolicy Bypass -File tests\\run-tests.ps1 -Password <你的密码>\n");