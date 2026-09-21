<?php
declare(strict_types=1);

/**
 * 通用辅助函数。无副作用、无依赖（除配置常量）。
 * PHP 8.0.2 兼容：不使用 8.1+ 任何语法。
 */

/** HTML 转义。所有用户可控内容的唯一出口。 */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 输出 JSON 并终止。 */
function json_out(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 面向用户的统一失败文案（§37）。绝不拼接异常信息。
 */
function fail_message(): string
{
    return '操作失败，请稍后重试。';
}

/**
 * 写日志。绝不记录密码 / Session / CSRF token 全值。
 * $ctx 中的敏感键会被自动剔除。
 */
function log_event(string $level, string $event, array $ctx = []): void
{
    static $rank = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    $cfgLevel = (string)(cfg()['log_level'] ?? 'info');
    if (($rank[$level] ?? 1) < ($rank[$cfgLevel] ?? 1)) {
        return;
    }

    $blocked = ['password', 'pass', 'pwd', 'token', 'csrf', 'csrf_token', 'session', 'cookie', 'hash', 'authorization'];
    $safe = [];
    foreach ($ctx as $k => $v) {
        $lk = strtolower((string)$k);
        $hit = false;
        foreach ($blocked as $b) {
            if (str_contains($lk, $b)) { $hit = true; break; }
        }
        if ($hit) { continue; }
        if (is_scalar($v) || $v === null) {
            $safe[$k] = is_string($v) ? substr($v, 0, 200) : $v;
        }
    }

    $line = sprintf(
        "[%s] %s %s %s\n",
        gmdate('Y-m-d H:i:s'),
        strtoupper($level),
        $event,
        json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // 日志体积上限与轮转。
    //
    // 为什么需要：`require_admin_json()` 会对**每一个未认证的 API 请求**记录
    // `forbidden_request`（§38 要求记录非法请求，这本身是对的）。
    // 但匿名攻击者因此可以持续猛打 `POST /upload.php`，每次请求写一行日志，
    // 把 data/logs/app.log 撑满磁盘——一个零成本的磁盘填充攻击。
    //
    // 处理方式：单文件超过上限后依次轮转 app.log.1 -> .2 -> .3，丢弃最旧的一份。
    // 保留 3 份历史（约占 8 MB 上限），比只留 1 份更能覆盖"几周前出过什么问题"。
    // 磁盘占用仍是常数级，攻击者无法靠刷日志撑满磁盘。
    $maxBytes = 2 * 1048576;   // 2 MB
    $keep     = 3;             // 保留的历史份数
    $size = @filesize(LOG_FILE);
    if ($size !== false && $size > $maxBytes) {
        // 只在轮转时做额外 IO，正常写入路径无额外开销。
        // 从最旧的一份开始往前推，避免覆盖掉还未搬走的内容。
        @unlink(LOG_FILE . '.' . $keep);
        for ($i = $keep - 1; $i >= 1; $i--) {
            $from = LOG_FILE . '.' . $i;
            if (is_file($from)) {
                @rename($from, LOG_FILE . '.' . ($i + 1));
            }
        }
        @rename(LOG_FILE, LOG_FILE . '.1');
    }

    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 客户端 IP。
 * 仅在 Nginx 通过 X-Real-IP 传递时使用；否则用 REMOTE_ADDR。
 * 不信任 X-Forwarded-For（可被客户端伪造），避免限速被绕过。
 */
function client_ip(): string
{
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

/** 是否为 HTTPS 请求。 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

/** 人类可读文件大小。 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 2) . ' MB';
}

/**
 * UTC 存储时间 -> 配置时区的展示时间。
 *
 * 时区对象与 UTC 时区都做静态缓存：本函数在每个图片卡片上调用一次，
 * 首页一屏 24 张图会调用 24 次；每页重复构造 DateTimeZone 是不必要的开销（§39）。
 */
function format_local_time(string $utc): string
{
    static $utcTz = null;
    static $localTz = null;
    static $tzName = null;

    try {
        if ($utcTz === null) {
            $utcTz = new DateTimeZone('UTC');
        }
        $want = (string)(cfg()['timezone'] ?? 'UTC');
        if ($localTz === null || $tzName !== $want) {
            try {
                $localTz = new DateTimeZone($want);
            } catch (Throwable $e) {
                // 配置了非法时区时退回 UTC，而不是让整页报错
                $localTz = $utcTz;
            }
            $tzName = $want;
        }
        $dt = new DateTimeImmutable($utc, $utcTz);
        return $dt->setTimezone($localTz)->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * 文件名白名单校验（§27 防目录穿越的第二道闸）。
 * 只接受 safe_filename 生成形态：32 位十六进制 + 白名单扩展名。
 * 任何含路径分隔符、点、空字节的输入都在此被拒。
 */
function safe_filename(string $name): ?string
{
    if ($name === '' || strlen($name) > 64) {
        return null;
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || str_contains($name, '..')) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $name) !== 1) {
        return null;
    }
    return $name;
}

/**
 * 生成站点内绝对 URL。
 *
 * 支持两种部署模式（见 data/config.sample.php 的 base_path 说明）：
 *   模式 A（推荐，§17）：站点根 = public/，base_path 为空 -> url('/login.php') = '/login.php'
 *   模式 B（子目录）：站点根 = 上级目录，base_path = '/img/public'
 *                     -> url('/login.php') = '/img/public/login.php'
 *
 * base_path 一律来自配置（管理员设定），绝不取自请求头，因此不存在
 * Host 头注入或开放重定向风险。
 */
function url(string $path = '/'): string
{
    static $base = null;
    if ($base === null) {
        // 配置里的 base_path（规范化）
        $b = trim((string)(cfg()['base_path'] ?? ''));
        $b = rtrim($b, '/');
        if ($b !== '' && $b[0] !== '/') {
            $b = '/' . $b;
        }

        // 自愈：把配置值与**实际被访问的位置**比对。
        //
        // 背景：部署形态可能和配置不一致。最常见的一种是站点根被设为项目根，
        // 于是浏览器实际访问 /public/login.php，而配置里 base_path=''。
        // 这时 url('/login.php') 会生成 '/login.php' —— 该文件并不存在，
        // PHP-FPM 直接返回 "No input file specified."，且 CSS 也会 404。
        //
        // 由 SCRIPT_NAME 反推当前位置（服务端可信信息）：
        //   '/public/login.php' -> '/public'
        //   '/login.php'        -> ''（站点根就是 public/）
        //
        // 只有当**二者不一致**时才采信推导值，避免在正常配置下改变行为。
        if (!defined('APP_NO_URL_SELFHEAL') && isset($_SERVER['SCRIPT_NAME'])) {
            // 注意：**必须在 dirname() 之后再规范化一次**。
            // Windows 版 PHP 的 dirname('/diag.php') 返回的是反斜杠 '\'，
            // 只规范化输入是不够的：dirname 的输出同样带反斜杠。
            // 若不处理，$derived 会变成 '\'，它既不等于 '.' 也不等于 '/'，
            // 于是逃过下面的检查；最终 url() 产出 '\/login.php'，
            // 浏览器把它当成 '//login.php'（协议相对 URL），
            // 主机名被解析成 'login.php' —— 即 "http://login.php/" 现象。
            $scriptName = str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']);
            $derived = str_replace('\\', '/', dirname($scriptName));
            $derived = rtrim($derived, '/');
            if ($derived === '.' || $derived === '/') {
                $derived = '';
            }
            if ($derived !== $b) {
                // 记录一次，便于排查；不影响输出。
                if (function_exists('log_event')) {
                    log_event('warning', 'base_path_selfhealed', [
                        'configured' => $b,
                        'derived'    => $derived,
                    ]);
                }
                $b = $derived;
            }
        }

        // 最后一道防线：base 必须是一个"干净的路径前缀"或空串。
        //
        // 为什么需要：
        //   - 若 base 变成单个 '/'，拼接结果会是 '//login.php'。浏览器把
        //     以 '//' 开头的地址当作**协议相对 URL**，于是主机名被解析成
        //     'login.php'，最终跳到 http://login.php/ —— 一个完全不存在的地址。
        //   - 若 base 是 Windows 路径（如 'C:/x/y'），拼接结果会被浏览器
        //     当成 'C:' 协议，同样是坏地址。
        // 这两种情况都只可能来自异常环境变量，但代价极低，值得挡掉。
        if ($b === '/' || $b === '.') {
            $b = '';
        }
        if (preg_match('#^[A-Za-z]:#', $b) === 1) {
            // 看起来像磁盘路径：S 这类值不可能是有意义的基础路径
            if (function_exists('log_event')) {
                log_event('warning', 'base_path_rejected', ['value' => $b]);
            }
            $b = '';
        }
        // 去掉可能的重复斜杠
        $b = rtrim($b, '/');

        $base = $b;
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    $out = $base . $path;
    // 双保险：任何情况下都不产出以 '//' 开头的站内地址
    if (str_starts_with($out, '//')) {
        $out = '/' . ltrim($out, '/');
    }
    return $out;
}

/**
 * 生成**绝对** URL（含协议与主机），用于"复制直链"。
 *
 * 为什么需要它：
 *   url() 产出的是站内相对路径（如 /uploads/xxx.jpg），这对页面内链接是正确的、
 *   可移植的；但"复制直链"的用途是**粘贴到站外**（论坛、聊天、Markdown），
 *   此时必须带协议与主机，否则对方拿到的是一个无意义的路径。
 *   设计要求 §8 给出的示例本身也是绝对地址（https://example.com/i/xxx.jpg）。
 *
 * 关于 Host 头注入：
 *   HTTP_HOST 由客户端提供，理论上可被伪造，从而让复制出来的直链指向攻击者域名。
 *   因此这里的策略是：
 *     1. 若配置里显式给了 site_url，一律以它为准（完全确定）；
 *     2. 否则从请求推导，但**严格校验 host 形态**，不合格就退回相对路径
 *        （宁可不带域名，也不给出一个可能被投毒的绝对地址）；
 *     3. host 只取 host[:port]，绝不保留 path/query 等多余内容。
 */
function absolute_url(string $path = '/'): string
{
    $relative = url($path);

    // 1) 显式配置优先
    $configured = trim((string)(cfg()['site_url'] ?? ''));
    if ($configured !== '' && preg_match('#^https?://#i', $configured) === 1) {
        return rtrim($configured, '/') . $relative;
    }

    // 2) 从请求推导，并严格校验
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // 只允许 主机名/IP + 可选端口；拒绝任何带路径、空格、控制字符的值
    if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host) !== 1) {
        return $relative;
    }

    // 协议判定：HTTPS 优先，其次看反代头
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';

    return $scheme . '://' . $host . $relative;
}

/**
 * 静态资源 URL，带版本号。
 *
 * 为什么需要：`assets/app.js` 与 `style.css` 会随开发不断变化，
 * 而 nginx 只发 ETag/Last-Modified、没有 Cache-Control，浏览器会按启发式
 * 规则缓存 —— 可能**不回源校验**就直接用旧副本。
 * 表现就是"代码明明改了，页面上却还是老行为"，排查时极具误导性。
 *
 * 用文件的修改时间作为版本号：内容一变 URL 就变，浏览器必然重新拉取。
 * 文件不存在时退回不带参数的 URL（不因为一个缺失文件而报错）。
 */
function asset_url(string $path): string
{
    $rel = str_replace('\\', '/', ltrim($path, '/'));
    $file = APP_ROOT . '/public/' . $rel;
    $ver = '';
    if (is_file($file)) {
        $m = @filemtime($file);
        if ($m !== false) {
            $ver = '?v=' . $m;
        }
    }
    return url('/' . $rel) . $ver;
}

/**
 * 输出全部样式表的 <link>，每个文件独立带版本号。
 *
 * 为什么不合并成单个 style.css 再用 @import：
 *   @import 里的 URL **无法带版本参数** —— CSS 是静态文件，没有 PHP 参与。
 *   而 nginx 给 .css 发了 7 天缓存，于是改了某个分片后浏览器最长 7 天
 *   仍在用旧样式，且没有任何提示。这正是本项目在 JS 上踩过的坑
 *   （资源无版本号 -> 用户拿到旧脚本 -> 行为对不上代码）。
 *
 * 拆成多个 <link> 的好处：
 *   - 每个文件用自己的 mtime 做版本，改哪个哪个失效，最精确；
 *   - 去掉 @import 的串行下载（@import 必须等入口下载完才开始）；
 *   - 不再需要 style.css 这个"入口壳"，少一个容易忘记同步的中间层。
 *
 * 顺序即层叠顺序，不可调换：base -> polish -> theme。
 */
function stylesheet_links(): string
{
    // 顺序是语义的一部分：后面的层覆盖前面的层。
    $files = [
        'assets/css/1-base.css',
        'assets/css/2-polish.css',
        'assets/css/3-theme.css',
    ];

    $out = '';
    foreach ($files as $rel) {
        $out .= '<link rel="stylesheet" href="' . e(asset_url('/' . $rel)) . '">' . "\n";
    }
    return $out;
}

/** 生成服务端随机文件名。用户输入不参与。 */
function random_filename(string $ext): string
{
    $allowed = ['jpg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $ext = 'jpg';
    }
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * 取用于**展示**的文件名（去掉已知的图片扩展名）。
 *
 * 用途：界面上只给人看有意义的部分（"海边日落" 而不是 "海边日落.jpg"）。
 * 注意：
 *   - 只剥离**已知的图片扩展名**，不动其它点号，例如 "my.photo.v2" 原样返回；
 *   - 若剥离后为空（例如文件名本身就是 ".jpg"），返回原值，避免显示空白；
 *   - 只影响展示，**落盘文件名与直链完全不受影响**。
 */
function display_name(string $name): string
{
    static $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $trimmed = $name;
    $dot = strrpos($trimmed, '.');
    if ($dot !== false && $dot > 0) {
        $ext = strtolower(substr($trimmed, $dot + 1));
        if (in_array($ext, $exts, true)) {
            $cut = substr($trimmed, 0, $dot);
            if ($cut !== '' && $cut !== false) {
                $trimmed = $cut;
            }
        }
    }
    return $trimmed;
}

/** 拼接公开直链路径。filename 必须已通过 safe_filename 校验。 */
function image_url(string $filename): string
{
    $safe = safe_filename($filename);
    return $safe === null ? '' : url('/uploads/' . $safe);
}

/** 拼接缩略图直链路径。 */
function thumb_url(string $filename): string
{
    $safe = safe_filename($filename);
    return $safe === null ? '' : url('/uploads/thumbs/' . $safe);
}
