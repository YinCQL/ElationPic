<?php
declare(strict_types=1);

/**
 * 统一错误页（§37）。
 *
 * 刻意不加载 bootstrap.php：
 *   - 本页由 Nginx 的 error_page 指令调用，必须无条件可渲染
 *   - bootstrap 在配置缺失时会 app_fatal() 并返回 500，
 *     那会把所有错误页都变成 500，掩盖真实状态码
 *   - 本页不读取数据库、不读取配置
 */

/**
 * 确定要返回的 HTTP 状态码。
 *
 * 为什么需要这个：Nginx 的 error_page 可以把 403 / 404 / 5xx 都导向本页。
 * 若这里**写死 404**，那么一次"被拒绝的访问"（403）会显示成"页面不存在"——
 * 既误导用户，也让安全拒绝在监控里看起来像普通 404。
 *
 * 因此本页从查询参数读取原始状态码，并**严格白名单校验**后才使用，
 * 绝不直接把用户输入当成状态码。
 */
$allowed = [400, 403, 404, 405, 500, 502, 503, 504];
$code = 404;
if (isset($_GET['code']) && is_string($_GET['code']) && ctype_digit($_GET['code'])) {
    $candidate = (int)$_GET['code'];
    if (in_array($candidate, $allowed, true)) {
        $code = $candidate;
    }
}

$titles = [
    400 => '请求无效',
    403 => '没有权限',
    404 => '页面不存在',
    405 => '方法不被允许',
    500 => '服务器错误',
    502 => '网关错误',
    503 => '服务暂不可用',
    504 => '网关超时',
];
$title = $titles[$code] ?? '出错了';

$messages = [
    400 => '请求的内容无效。',
    403 => '你没有权限访问该资源。',
    404 => '你访问的页面不存在或已被移除。',
    405 => '该地址不接受这种请求方式。',
    500 => '操作失败，请稍后重试。',
    502 => '操作失败，请稍后重试。',
    503 => '服务暂时不可用，请稍后重试。',
    504 => '操作失败，请稍后重试。',
];
$message = $messages[$code] ?? '操作失败，请稍后重试。';

/**
 * 计算站点基础路径 —— 不使用 cfg()，也不依赖任何配置。
 *
 * 为什么不用相对路径（assets/style.css）：
 *   本页由 Nginx 的 error_page 内部改写而来，浏览器地址栏仍是被请求的原始 URL。
 *   若用户请求 /img/public/a/b/c，相对路径会解析成
 *   /img/public/a/b/assets/style.css → 404 → 错误页无样式。
 *
 * 为什么用 SCRIPT_NAME 而不是 REQUEST_URI：
 *   error_page 内部改写后 SCRIPT_NAME 指向本文件，是服务端可信信息；
 *   而 REQUEST_URI 是用户可控的原始请求，拿它做路径推导会引入注入面。
 */
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '/error.php');
$base = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.' || $base === '/') {
    $base = '';
}

$esc = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$cssHref  = $esc($base . '/assets/style.css');
$homeHref = $esc($base . '/');
$safeTitle = $esc($title);
$safeMessage = $esc($message);

http_response_code($code);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $safeTitle ?></title>
<link rel="stylesheet" href="<?= $cssHref ?>">
</head>
<body>
<main class="wrap">
    <section class="panel panel-narrow center">
        <h1><?= $safeTitle ?></h1>
        <p class="muted"><?= $safeMessage ?></p>
        <p><a class="btn btn-primary" href="<?= $homeHref ?>">返回首页</a></p>
    </section>
</main>
</body>
</html>
