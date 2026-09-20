<?php
declare(strict_types=1);

/**
 * 首次安装向导（网页版）。
 *
 * 分为两步：
 *   1. 环境检查 —— 不通过则**不允许安装**
 *   2. 填写站点信息与管理密码，完成安装
 *
 * 为什么把环境检查放在安装之前：
 *   缺扩展、目录不可写这类问题，如果拖到安装之后才暴露，用户会看到一个
 *   「装好了但用不了」的站点，而且报错信息通常指向别处，很难定位。
 *   在写任何配置之前先确认环境可用，问题会以明确的语言呈现。
 *
 * 安全设计：
 *   - 已配置（存在有效的 admin_password_hash）时，本页拒绝运行
 *   - 仅允许来自回环地址的请求执行安装，或必须提供 data/setup-token.txt 中的令牌
 *   - 仅接受 POST，且校验 CSRF
 *   - 密码不落日志、不回显、只存哈希
 *   - 本页**不依赖** public/assets/style.css —— 安装可能发生在样式表
 *     尚未就绪或路径推导错误的时刻，自带样式更稳妥
 */

// 本页在配置完成前必须可用，因此**不能**加载 bootstrap.php（后者会因缺配置而终止）。
define('APP_ROOT_SETUP', dirname(__DIR__));
$dataDir = APP_ROOT_SETUP . '/data';
$pubDir  = APP_ROOT_SETUP . '/public';
$cfgFile = $dataDir . '/config.php';
$dbFile  = $dataDir . '/database.sqlite';
$tokFile = $dataDir . '/setup-token.txt';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** 最低 PHP 版本。项目不使用 8.1+ 语法，因此 8.0.2 是可用下限。 */
const SETUP_MIN_PHP = '8.0.2';

function s_e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 渲染页面。
 *
 * 样式**内联**在页面里，不引用外部 CSS：
 *   安装发生在应用尚未配置时，此时资源路径可能还推导不出来；
 *   而且安装页只出现一次，内联不会带来缓存问题。
 */
function s_page(string $title, string $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . s_e($title) . ' · ElationPic</title>';
    echo s_css();
    echo '</head><body class="setup-body"><div class="setup-page">';
    echo '<header class="setup-head"><span class="setup-mark"></span>';
    echo '<span class="setup-brand">ElationPic</span></header>';
    echo '<main class="setup-card">' . $body . '</main>';
    echo '<footer class="setup-foot">安装完成后本页自动失效</footer>';
    echo '</div></body></html>';
    exit;
}

/** 安装页自带的样式（不依赖外部 CSS）。 */
function s_css(): string {
    // 用 nowdoc（<<<'CSS'）而不是字符串拼接：CSS 里有大量引号与特殊字符，
    // 拼接写法既容易出错，改起来也要给每一行加引号。
    return <<<'CSS'
<style>
    .setup-body{margin:0;padding:32px 16px 48px;background:#f4f6f9;color:#16181d;
        font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;}
    .setup-page{max-width:760px;margin:0 auto;}
    .setup-head{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:22px;}
    .setup-mark{width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,#2f6feb,#5b94ff);position:relative;}
    .setup-mark::after{content:"";position:absolute;right:5px;bottom:5px;width:9px;height:9px;border-radius:50%;background:#fff;}
    .setup-brand{font-size:17px;font-weight:650;letter-spacing:.01em;}
    .setup-card{background:#fff;border:1px solid #e3e7ee;border-radius:14px;
        box-shadow:0 1px 3px rgba(16,24,40,.06),0 8px 24px rgba(16,24,40,.05);padding:30px 32px;}
    .setup-foot{margin-top:16px;text-align:center;font-size:12.5px;color:#8b939f;}
    .setup-card h1{margin:0 0 6px;font-size:22px;font-weight:650;letter-spacing:-.01em;}
    .setup-card h2{margin:26px 0 4px;font-size:15px;font-weight:650;}
    .setup-card h2:first-of-type{margin-top:8px;}
    .setup-lead{margin:0 0 4px;color:#6b7280;font-size:14px;line-height:1.65;}
    .setup-muted{color:#6b7280;}
    .setup-field{display:block;margin:16px 0;}
    .setup-field>span{display:block;font-size:13px;color:#6b7280;margin-bottom:6px;}
    .setup-field input[type=text],.setup-field input[type=password],.setup-field input[type=number]{
        width:100%;box-sizing:border-box;padding:10px 12px;font:inherit;font-size:14.5px;
        color:#16181d;background:#fff;border:1px solid #d3d9e3;border-radius:8px;}
    .setup-field input:focus{outline:2px solid #2f6feb;outline-offset:1px;border-color:#2f6feb;}
    .setup-hint{display:block;margin-top:5px;font-size:12.5px;color:#8b939f;line-height:1.55;}
    .setup-btn{display:inline-block;padding:11px 20px;font:inherit;font-size:14.5px;font-weight:600;
        color:#fff;background:#2f6feb;border:0;border-radius:9px;cursor:pointer;text-decoration:none;}
    .setup-btn:hover{background:#2258c9;}
    .setup-btn-plain{color:#16181d;background:#fff;border:1px solid #d3d9e3;}
    .setup-btn-plain:hover{background:#f4f6f9;}
    .setup-alert{margin:16px 0;padding:12px 15px;border-radius:9px;font-size:13.5px;line-height:1.65;
        background:#fdecea;border:1px solid #f3c2bd;color:#7c2d22;}
    .setup-ok{margin:16px 0;padding:12px 15px;border-radius:9px;font-size:13.5px;line-height:1.65;
        background:#f0f9f2;border:1px solid #bfe3c8;color:#1e6b34;}
    .setup-list{list-style:none;margin:12px 0 0;padding:0;}
    .setup-list li{display:flex;align-items:flex-start;gap:10px;padding:10px 0;
        border-bottom:1px solid #f0f2f5;font-size:13.5px;}
    .setup-list li:last-child{border-bottom:0;}
    .setup-ico{flex:none;width:19px;height:19px;border-radius:50%;margin-top:1px;
        display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;}
    .setup-ico.ok{background:#2e9e52;}
    .setup-ico.bad{background:#c0392b;}
    .setup-ico.warn{background:#d98324;}
    .setup-txt{flex:1;min-width:0;}
    .setup-txt b{display:block;font-weight:600;}
    .setup-txt small{display:block;color:#8b939f;font-size:12.5px;margin-top:2px;line-height:1.6;}
    .setup-txt code{background:#f4f6f9;padding:1px 5px;border-radius:4px;font-size:12.5px;}
    .setup-actions{margin-top:22px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .setup-steps{display:flex;gap:8px;margin:0 0 18px;font-size:12.5px;color:#8b939f;}
    .setup-steps span{padding:3px 10px;border-radius:20px;background:#f0f2f5;}
    .setup-steps span.on{background:#e6efff;color:#2f6feb;font-weight:600;}
    @media (max-width:520px){
        .setup-body{padding:20px 12px 36px;}
        .setup-card{padding:22px 18px;border-radius:12px;}
        .setup-card h1{font-size:19px;}
        .setup-actions .setup-btn{width:100%;text-align:center;}
    }
</style>
CSS;
}

/** 一行检查结果。 */
function s_item(string $state, string $title, string $detail): string {
    $cls = $state === 'ok' ? 'ok' : ($state === 'warn' ? 'warn' : 'bad');
    $ico = $state === 'ok' ? '✓' : ($state === 'warn' ? '!' : '×');
    return '<li><span class="setup-ico ' . $cls . '">' . $ico . '</span>'
         . '<span class="setup-txt"><b>' . $title . '</b><small>' . $detail . '</small></span></li>';
}

/**
 * 环境检查。
 *
 * 返回 ['items'=>[], 'fatal'=>bool]。fatal 为真时**禁止继续安装**。
 *
 * 只检查真正影响本程序运行的项 —— 不做泛泛的"服务器体检"。
 */
function s_env_check(string $dataDir, string $pubDir): array
{
    $items = [];
    $fatal = false;

    // ---- PHP 版本 ----
    $ver = PHP_VERSION;
    if (version_compare($ver, SETUP_MIN_PHP, '>=')) {
        $items[] = s_item('ok', 'PHP 版本 ' . s_e($ver), '满足最低要求 ' . SETUP_MIN_PHP . '。');
    } else {
        $fatal = true;
        $items[] = s_item('bad', 'PHP 版本 ' . s_e($ver) . ' 过低',
            '需要 ' . SETUP_MIN_PHP . ' 或更高。请在面板中切换 PHP 版本后重试。');
    }

    // ---- 必需扩展 ----
    // 说明每个扩展"为什么需要"，而不是只列名字 —— 缺了才知道该怎么办。
    $exts = [
        'pdo_sqlite' => '数据库（本程序使用 SQLite，无需额外安装数据库服务）',
        'gd'         => '生成缩略图',
        'fileinfo'   => '检测上传文件的真实类型（上传安全的第一道防线）',
        'json'       => '接口返回 JSON',
        'zip'        => '备份与恢复（打包数据库与图片）',
    ];
    $missing = [];
    foreach ($exts as $ext => $why) {
        if (!extension_loaded($ext)) { $missing[] = $ext; }
    }
    if ($missing === []) {
        $items[] = s_item('ok', '所需 PHP 扩展齐备', '已加载：' . s_e(implode('、', array_keys($exts))) . '。');
    } else {
        $fatal = true;
        $lines = [];
        foreach ($missing as $m) {
            $lines[] = '<code>' . s_e($m) . '</code>（' . s_e($exts[$m]) . '）';
        }
        $items[] = s_item('bad', '缺少 PHP 扩展',
            '缺少：' . implode('、', $lines) . '。请在 PHP 配置中启用后重试。');
    }

    // ---- 目录可写 ----
    $dirs = [
        $dataDir . '/logs'          => 'data/logs（运行日志）',
        $dataDir . '/sessions'      => 'data/sessions（会话文件）',
        $dataDir . '/tmp'           => 'data/tmp（上传临时文件）',
        $pubDir . '/uploads'        => 'public/uploads（图片存放）',
        $pubDir . '/uploads/thumbs' => 'public/uploads/thumbs（缩略图）',
    ];
    $writeFail = [];
    $created = [];
    foreach ($dirs as $d => $label) {
        if (!is_dir($d)) {
            if (@mkdir($d, 0775, true)) { $created[] = $label; }
            else { $writeFail[] = $label; continue; }
        }
        // 真正写一个文件试试 —— is_writable() 在部分环境下会给出误导结果
        $probe = rtrim($d, '/\\') . '/.setup-write-test';
        if (@file_put_contents($probe, 'x') === false) {
            $writeFail[] = $label;
        } else {
            @unlink($probe);
        }
    }
    if ($writeFail === []) {
        $extra = $created === [] ? '' : '（已自动创建：' . s_e(implode('、', $created)) . '）';
        $items[] = s_item('ok', '所需目录可写', '已验证可写入。' . $extra);
    } else {
        $fatal = true;
        $items[] = s_item('bad', '目录不可写',
            '无法写入：' . s_e(implode('、', $writeFail)) . '。请检查目录权限（或属主）后重试。');
    }

    // ---- data 是否落在 Web 可访问范围 ----
    // 这是本项目最重要的部署要求：data/ 必须在网站根之外。
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $dataReal = realpath($dataDir);
    if ($docRoot !== false && $dataReal !== false) {
        $docRootNorm = rtrim(str_replace('\\', '/', $docRoot), '/');
        $dataNorm    = str_replace('\\', '/', $dataReal);
        if (strncmp($dataNorm, $docRootNorm . '/', strlen($docRootNorm) + 1) === 0) {
            $fatal = true;
            $items[] = s_item('bad', 'data 目录位于网站根之内',
                '<code>data/</code> 当前在 <code>' . s_e($docRoot) . '</code> 之下，'
              . '这会让数据库与配置（含密码哈希）可能被直接下载。'
              . '请把网站根指向项目下的 <code>public/</code> 目录。');
        } else {
            $items[] = s_item('ok', 'data 目录在网站根之外',
                '网站根为 <code>' . s_e($docRoot) . '</code>，数据库与配置不在其中。');
        }
    } else {
        $items[] = s_item('warn', '无法确认 data 目录位置',
            '服务器未提供 DOCUMENT_ROOT，无法自动判断。请自行确认网站根指向 <code>public/</code>。');
    }

    // ---- uploads 不得执行 PHP ----
    //
    // 做法：往 uploads/ 写一个会输出标记的 .php，然后用**原始 socket**
    // 请求它。若返回的是标记文本，说明服务器把上传目录里的脚本当代码执行了
    // —— 这是最严重的配置错误，必须阻断安装。
    //
    // 为什么用 fsockopen 而不是 file_get_contents：
    //   PHP 内置开发服务器是**单线程**的，处理当前请求时无法响应发往自身的
    //   第二个请求，file_get_contents 会一直等到超时。原始 socket 配极短超时
    //   可以避免长时间挂起，同时在内置服务器下快速失败并给出说明。
    $probeName = '.setup-exec-probe-' . bin2hex(random_bytes(4)) . '.php';
    $probeDir  = rtrim($pubDir, '/\\') . '/uploads';
    $probePath = $probeDir . '/' . $probeName;
    $isDevServer = (PHP_SAPI === 'cli-server');

    if (!is_dir($probeDir) || @file_put_contents($probePath, '<?php echo "ELATION_EXEC_MARKER";') === false) {
        $items[] = s_item('warn', 'uploads 执行检查被跳过',
            '无法在 <code>public/uploads/</code> 写入探针文件，未能自动验证。'
          . '请手动确认该目录下的 .php 不会被当作代码执行。');
    } else {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $base = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/setup.php')));
        if ($base === '.' || $base === '/') { $base = ''; }
        $path = $base . '/uploads/' . $probeName;

        // 目标主机与端口：优先用 HTTP_HOST（含端口），否则退回 SERVER_PORT
        $targetHost = $host;
        $port = 80;
        if ($targetHost === '') {
            $targetHost = '127.0.0.1';
            $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        } elseif (strpos($targetHost, ':') !== false) {
            $parts = explode(':', $targetHost, 2);
            $targetHost = $parts[0];
            $port = (int)$parts[1];
        } else {
            $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $port === 80) {
            $port = 443;
        }

        $response = '';
        $reached = false;
        if ($host !== '' && function_exists('fsockopen')) {
            $fp = @fsockopen($targetHost, $port, $errno, $errstr, 2);
            if ($fp) {
                $reached = true;
                stream_set_timeout($fp, 2);
                $req = "GET " . $path . " HTTP/1.0\r\n"
                     . "Host: " . $host . "\r\n"
                     . "User-Agent: ElationPic-Setup\r\n"
                     . "Connection: close\r\n\r\n";
                @fwrite($fp, $req);
                // 最多读 8 KB 就够判断
                $buf = '';
                while (!feof($fp) && strlen($buf) < 8192) {
                    $chunk = @fread($fp, 1024);
                    if ($chunk === false || $chunk === '') { break; }
                    $buf .= $chunk;
                }
                @fclose($fp);
                $response = $buf;
            }
        }
        @unlink($probePath);

        // 判定必须基于"确实读到了 HTTP 响应"这一事实。
        //
        // 注意：**读到 0 字节不等于安全**。单线程服务器（PHP 内置开发服务器）
        // 会接受连接却无法在处理当前请求时响应第二个请求，于是读取为空。
        // 若把空响应当作"未执行"，就会得出一个**没有依据的通过结论** ——
        // 那比不检查更糟，因为它会让人误以为已经验证过了。
        $gotHttpResponse = ($reached && strpos($response, 'HTTP/') === 0);

        if ($gotHttpResponse && strpos($response, 'ELATION_EXEC_MARKER') !== false) {
            // ★ 脚本被执行了 —— 最严重的情况
            $fatal = true;
            $items[] = s_item('bad', 'uploads 目录会执行 PHP（严重安全问题）',
                '上传目录里的 .php 被当作代码执行了。这意味着任何人只要能上传一个 .php 文件，'
              . '就能完全控制你的站点。'
              . '请在 Web 服务器配置中加入禁止该目录执行脚本的规则'
              . '（参考 <code>deploy/FIX-uploads-no-exec.conf</code>），然后刷新本页重新检查。');
        } elseif ($gotHttpResponse) {
            $items[] = s_item('ok', 'uploads 目录不执行 PHP',
                '已实际请求探针脚本，服务器返回了内容但没有执行它。');
        } else {
            $note = $isDevServer
                ? '当前使用 PHP 内置开发服务器，它是单线程的，无法在处理本请求时响应发往自身的第二个请求，因此该项无法自动验证。'
                : '服务器无法连接或未能响应自身地址，无法自动验证。';
            $items[] = s_item('warn', 'uploads 执行检查未能完成（请手动确认）',
                $note . '<br>请务必手动确认 <code>uploads/</code> 下的 .php 不会被当作代码执行 —— '
              . '这是保护站点最关键的一项。验证方法：往该目录放一个 <code>test.php</code>，'
              . '浏览器访问 <code>/uploads/test.php</code>；应当返回 404 或下载，'
              . '**绝不能看到脚本输出**。验证后请删除该文件。');
        }
    }

    // ---- 磁盘剩余空间 ----
    $free = @disk_free_space($dataDir !== '' ? $dataDir : '/');
    if ($free !== false) {
        $mb = (int)round($free / 1048576);
        if ($free < 500 * 1048576) {
            $fatal = true;
            $items[] = s_item('bad', '磁盘剩余空间不足（' . $mb . ' MB）',
                '写入随时可能失败 —— 不只是上传，会话文件也写不进去。请清理后重试。');
        } elseif ($free < 2048 * 1048576) {
            $items[] = s_item('warn', '磁盘剩余空间偏低（' . $mb . ' MB）',
                '当前可以安装，但建议留意。图片会持续占用空间。');
        } else {
            $items[] = s_item('ok', '磁盘剩余空间充足', '可用约 ' . number_format($free / 1073741824, 1) . ' GB。');
        }
    } else {
        $items[] = s_item('warn', '无法读取磁盘剩余空间', '服务器禁用了相关函数，跳过此项。');
    }

    // ---- 上传大小限制（提醒，不阻断）----
    $uploadMax = (string)ini_get('upload_max_filesize');
    $postMax   = (string)ini_get('post_max_size');
    if ($uploadMax !== '' && $postMax !== '') {
        $items[] = s_item('ok', 'PHP 上传限制',
            'upload_max_filesize = <code>' . s_e($uploadMax) . '</code>，'
          . 'post_max_size = <code>' . s_e($postMax) . '</code>。'
          . '可在后台「设置」中调整本站的上传上限（不会超过 PHP 自身的限制）。');
    }

    return ['items' => $items, 'fatal' => $fatal];
}
// ---------- 已配置则拒绝 ----------
$alreadyConfigured = false;
if (is_file($cfgFile)) {
    $existing = @include $cfgFile;
    if (is_array($existing)
        && isset($existing['admin_password_hash'])
        && is_string($existing['admin_password_hash'])
        && $existing['admin_password_hash'] !== ''
        && $existing['admin_password_hash'] !== 'REPLACE_ME') {
        $alreadyConfigured = true;
    }
}
if ($alreadyConfigured) {
    s_page('已完成安装',
        '<h1>本站已完成安装</h1>'
      . '<p class="setup-lead">安装向导已经失效，无需再次运行。</p>'
      . '<p class="setup-muted">如需修改站点信息或管理员密码，请登录后在后台「设置」中操作。</p>'
      . '<div class="setup-actions"><a class="setup-btn" href="./">返回首页</a>'
      . '<a class="setup-btn setup-btn-plain" href="login.php">前往登录</a></div>', 403);
}

// ---------- 来源校验 ----------
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLoopback = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
$tokenRequired = !$isLoopback;
$expectedToken = '';
if ($tokenRequired) {
    if (is_file($tokFile)) {
        $expectedToken = trim((string)@file_get_contents($tokFile));
    }
    if ($expectedToken === '') {
        s_page('需要安装令牌',
            '<h1>需要安装令牌</h1>'
          . '<p class="setup-lead">本页默认只允许从本机（127.0.0.1）访问。</p>'
          . '<p class="setup-muted">若确需从远程安装，请先在服务器上创建'
          . ' <code>data/setup-token.txt</code>，内容为任意随机字符串，然后刷新本页。</p>', 403);
    }
}

// ---------- 会话：仅用于 CSRF ----------
session_name('elation_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['setup_csrf']) || !is_string($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['setup_csrf'];

$error  = '';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$stage  = isset($_GET['stage']) && $_GET['stage'] === 'form' ? 'form' : 'check';

// 每次请求都重新检查环境 —— 用户可能刚按提示改完服务器配置。
$env = s_env_check($dataDir, $pubDir);

// 环境不通过时只显示检查结果，不提供表单。
if ($env['fatal']) {
    $stage = 'check';
}

if ($method === 'POST') {
    $posted = isset($_POST['setup_csrf']) && is_string($_POST['setup_csrf']) ? $_POST['setup_csrf'] : '';
    if ($env['fatal']) {
        // 理论上走不到这里（页面不会给出表单），但服务端必须自己拦一次
        $error = '环境检查未通过，无法安装。请先解决上方列出的问题。';
    } elseif (!hash_equals($csrf, $posted)) {
        $error = '会话已过期，请刷新页面后重试。';
    } else {
        $pw1 = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $pw2 = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
        $siteName = isset($_POST['site_name']) && is_string($_POST['site_name']) ? trim($_POST['site_name']) : '';
        $siteDesc = isset($_POST['site_description']) && is_string($_POST['site_description']) ? trim($_POST['site_description']) : '';
        $siteUrl  = isset($_POST['site_url']) && is_string($_POST['site_url']) ? trim($_POST['site_url']) : '';
        $perPage  = isset($_POST['per_page']) ? (int)$_POST['per_page'] : 20;
        $tokenIn  = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';

        // 站点网址：留空表示按访问主机自动推导
        $urlOk = true;
        if ($siteUrl !== '') {
            $urlOk = (preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#i', $siteUrl) === 1);
            if ($urlOk) { $siteUrl = rtrim($siteUrl, '/'); }
        }
        if ($perPage < 1)  { $perPage = 1; }
        if ($perPage > 120) { $perPage = 120; }

        if ($tokenRequired && !hash_equals($expectedToken, $tokenIn)) {
            $error = '安装令牌不正确。';
        } elseif (strlen($pw1) < 10) {
            $error = '密码至少 10 位。';
        } elseif ($pw1 !== $pw2) {
            $error = '两次输入的密码不一致。';
        } elseif (!$urlOk) {
            $error = '站点网址格式不正确，应形如 https://img.example.com（留空表示自动推导）。';
        } else {
            // ---- 执行安装 ----
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            unset($pw1, $pw2);
            if (!is_string($hash) || $hash === '') {
                $error = '密码哈希生成失败。';
            } else {
                // 自动推导 base_path —— 这是修复 "No input file specified." 的关键。
                //
                // 问题：若站点根是项目根（而非 public/），浏览器实际访问的是
                // /public/index.php。此时 url('/login.php') 会生成 /login.php，
                // 而该文件在项目根下并不存在 —— PHP-FPM 返回
                // "No input file specified."。
                //
                // 由 SCRIPT_NAME 反推基础路径，无需用户填写：
                //   '/public/setup.php' -> dirname '/public'  -> base_path '/public'
                //   '/setup.php'        -> dirname '/'        -> base_path ''
                $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/setup.php');
                $derivedBase = str_replace('\\', '/', dirname($scriptName));
                if ($derivedBase === '.' || $derivedBase === '/') {
                    $derivedBase = '';
                }

                $cfg = [
                    'admin_password_hash' => $hash,
                    'site_name'           => $siteName !== '' ? $siteName : 'ElationPic',
                    'site_description'    => $siteDesc,
                    'timezone'            => date_default_timezone_get() ?: 'UTC',
                    'site_url'            => $siteUrl,   // 留空则按访问主机自动推导
                    'base_path'           => $derivedBase,
                    'per_page'            => $perPage,
                    'max_file_bytes'      => 10485760,
                    'thumb_max_edge'      => 480,
                    // 默认开启：手机照片带 GPS，公开图床会把拍摄地点一起公开
                    'strip_metadata'      => true,
                    'force_https'         => false,
                    'login_max_attempts'  => 5,
                    'login_window_secs'   => 900,
                    'login_lockout_secs'  => 900,
                    'log_level'           => 'info',
                ];
                $export = "<?php\ndeclare(strict_types=1);\n\n"
                        . "// 由 public/setup.php 生成。请勿提交到版本库。\n\n"
                        . "// 纵深防御：被当作入口脚本直接请求时返回 404。\n"
                        . "if (isset(\$_SERVER['SCRIPT_FILENAME']) && is_string(\$_SERVER['SCRIPT_FILENAME'])\n"
                        . "    && realpath(\$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {\n"
                        . "    http_response_code(404);\n"
                        . "    header('Content-Type: text/plain; charset=utf-8');\n"
                        . "    echo \"Not Found\\n\";\n"
                        . "    exit;\n"
                        . "}\n\n"
                        . "return " . var_export($cfg, true) . ";\n";
                if (@file_put_contents($cfgFile, $export, LOCK_EX) === false) {
                    $error = '无法写入 data/config.php，请检查目录权限。';
                } else {
                    @chmod($cfgFile, 0660);
                    try {
                        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        ]);
                        $pdo->exec('PRAGMA journal_mode = WAL');
                        $pdo->exec('PRAGMA synchronous = NORMAL');
                        $pdo->exec('CREATE TABLE IF NOT EXISTS images ('
                            . ' id INTEGER PRIMARY KEY AUTOINCREMENT,'
                            . ' filename TEXT NOT NULL UNIQUE,'
                            . ' original_name TEXT NOT NULL,'
                            . ' mime TEXT NOT NULL,'
                            . ' size INTEGER NOT NULL,'
                            . ' width INTEGER NOT NULL DEFAULT 0,'
                            . ' height INTEGER NOT NULL DEFAULT 0,'
                            . ' sha256 TEXT NOT NULL DEFAULT \'\','
                            . ' thumb INTEGER NOT NULL DEFAULT 0,'
                            . ' created_at TEXT NOT NULL'
                            . ')');
                        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_images_filename ON images(filename)');
                        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_created_at ON images(created_at DESC)');
                        @chmod($dbFile, 0660);
                    } catch (Throwable $e) {
                        $error = '数据库初始化失败，请检查 data 目录权限。';
                    }
                    if ($error === '') {
                        // 成功后销毁会话与令牌，避免本页被再次利用
                        @unlink($tokFile);
                        $_SESSION = [];
                        @session_destroy();
                        s_page('安装完成',
                            '<h1>安装完成</h1>'
                          . '<p class="setup-lead">站点已就绪，可以开始使用了。</p>'
                          . '<ul class="setup-list">'
                          . s_item('ok', '管理员密码已设置', '登录时只需要密码，没有用户名。')
                          . s_item('ok', '数据库已创建', '使用 SQLite，无需额外配置。')
                          . s_item('ok', '目录已就绪', '图片将保存到 <code>public/uploads/</code>。')
                          . '</ul>'
                          . '<div class="setup-actions">'
                          . '<a class="setup-btn" href="login.php">前往登录</a>'
                          . '<a class="setup-btn setup-btn-plain" href="./">返回首页</a></div>');
                    }
                }
            }
        }
    }
}
// ---------- 渲染 ----------
$errHtml = $error !== '' ? '<p class="setup-alert" role="alert">' . s_e($error) . '</p>' : '';
$stepBar = '<div class="setup-steps">'
         . '<span class="' . ($stage === 'check' ? 'on' : '') . '">1 环境检查</span>'
         . '<span class="' . ($stage === 'form' ? 'on' : '') . '">2 站点信息</span>'
         . '</div>';

$listHtml = '<ul class="setup-list">' . implode('', $env['items']) . '</ul>';

if ($stage === 'check') {
    $badCount = 0;
    foreach ($env['items'] as $it) {
        if (strpos($it, 'setup-ico bad') !== false) { $badCount++; }
    }
    if ($env['fatal']) {
        $verdict = '<p class="setup-alert" role="alert">'
                 . '<b>发现 ' . $badCount . ' 项必须解决的问题，暂时无法安装。</b><br>'
                 . '请按下方提示处理，然后刷新本页重新检查。'
                 . '</p>';
        $actions = '<div class="setup-actions">'
                  . '<a class="setup-btn setup-btn-plain" href="setup.php">重新检查</a>'
                  . '</div>';
    } else {
        $verdict = '<p class="setup-ok" role="status">'
                 . '<b>环境检查通过，可以安装。</b>'
                 . '</p>';
        $actions = '<div class="setup-actions">'
                  . '<a class="setup-btn" href="setup.php?stage=form">继续安装</a>'
                  . '<a class="setup-btn setup-btn-plain" href="setup.php">重新检查</a>'
                  . '</div>';
    }
    s_page('环境检查',
        $stepBar
      . '<h1>环境检查</h1>'
      . '<p class="setup-lead">安装前先确认服务器满足运行条件。'
      . '这样问题会以明确的语言说明，而不是等到装完才出现一个「用不了」的站点。</p>'
      . $listHtml . $verdict . $actions);
}

// ---- 第二步：填写站点信息 ----
$tokenField = '';
if ($tokenRequired) {
    $tokenField = '<label class="setup-field"><span>安装令牌</span>'
                . '<input type="text" name="token" required autocomplete="off"></label>';
}

// 表单里的站点网址用一个「猜出来」的默认值：当前访问的主机。
// 这只是给用户的提示，仍然是可留空的 —— 留空表示每次按实际访问主机推导，
// 对「用 IP + 端口访问」或「同时用多个域名访问」的场景更合适。
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$guessOrigin = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

s_page('站点设置',
    $stepBar
  . '<h1>设置站点</h1>'
  . '<p class="setup-lead">填写下面几项，本站即可使用。'
  . '除管理员密码外，其余都可以稍后在后台「设置」里修改。</p>'
  . $errHtml
  . '<form method="post" action="setup.php?stage=form" autocomplete="off">'
  . '<input type="hidden" name="setup_csrf" value="' . s_e($csrf) . '">'

  . '<h2>站点</h2>'
  . '<label class="setup-field"><span>站点标题</span>'
  . '<input type="text" name="site_name" value="ElationPic" maxlength="60" required>'
  . '<small class="setup-hint">显示在浏览器标签页与页面顶部。</small></label>'
  . '<label class="setup-field"><span>站点描述（可选）</span>'
  . '<input type="text" name="site_description" maxlength="160" placeholder="A lightweight personal image hosting system.">'
  . '<small class="setup-hint">显示在首页标题下方。</small></label>'
  . '<label class="setup-field"><span>站点网址（可选）</span>'
  . '<input type="text" name="site_url" maxlength="200" placeholder="' . s_e($guessOrigin) . '">'
  . '<small class="setup-hint">用于生成「复制直链」的域名前缀。'
  . '留空则自动按访问时的主机推导（推荐，除非你绑定了固定域名）。</small></label>'
  . '<label class="setup-field"><span>每页显示图片数</span>'
  . '<input type="number" name="per_page" value="20" min="1" max="120">'
  . '<small class="setup-hint">1 ~ 120。</small></label>'

  . '<h2>管理员</h2>'
  . '<label class="setup-field"><span>管理员密码（至少 10 位）</span>'
  . '<input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password">'
  . '<small class="setup-hint">本站只有一个管理员账户，没有用户名，登录时只输密码。</small></label>'
  . '<label class="setup-field"><span>再次输入密码</span>'
  . '<input type="password" name="password2" required minlength="10" maxlength="200" autocomplete="new-password">'
  . '<small class="setup-hint">请务必记牢 —— 密码无法找回，只能重装。</small></label>'

  . $tokenField
  . '<div class="setup-actions">'
  . '<button type="submit" class="setup-btn">完成安装</button>'
  . '<a class="setup-btn setup-btn-plain" href="setup.php">返回检查</a>'
  . '</div>'
  . '</form>');