<?php
declare(strict_types=1);

/**
 * 统一入口引导（§37）。
 * 生产行为：display_errors=Off、错误只写日志、用户只看到固定文案。
 */

// ---------- 版本 ----------
//
// 唯一版本来源。发布新版本时改这里一处即可：
// 底栏展示、将来的升级提示都从这里取。
// 与 GitHub Release 的 tag 保持一致（不带 v 前缀）。
define('APP_VERSION', '1.0.1');

// ---------- 路径常量（§5.2 冻结契约） ----------
define('APP_ROOT',   dirname(__DIR__));
define('DATA_DIR',   APP_ROOT . '/data');
define('PUBLIC_DIR', APP_ROOT . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');
define('THUMB_DIR',  UPLOAD_DIR . '/thumbs');
define('LOG_FILE',   DATA_DIR . '/logs/app.log');
define('DB_FILE',    DATA_DIR . '/database.sqlite');
define('APP_DEBUG',  false);

// ---------- 生产错误策略 ----------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---------- 加载共享库 ----------
require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/csrf.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/images.php';
require_once APP_ROOT . '/src/thumbnails.php';
require_once APP_ROOT . '/src/metadata.php';
require_once APP_ROOT . '/src/upload.php';
require_once APP_ROOT . '/src/settings.php';
require_once APP_ROOT . '/src/backup.php';

/**
 * 计算安装向导的绝对路径。
 *
 * 为什么不用相对的 "setup.php"：相对 Location 依赖当前请求 URL 的形态，
 * 在不同客户端/代理下行为不一致。用 SCRIPT_NAME 推导出绝对路径更可靠，
 * 也便于在子目录部署下正确定位（与 error.php 采用同一思路）。
 *
 * SCRIPT_NAME 由 Web 服务器根据匹配到的 location 设置，属于服务端可信信息；
 * 且 Nginx 的 PHP location 只匹配 [A-Za-z0-9_-] ，因此其内容不含引号等字符。
 */
function app_setup_url(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $dir . '/setup.php';
}

// ---------- 加载配置 ----------
$configFile = DATA_DIR . '/config.php';
if (!is_file($configFile)) {
    // 尚未初始化：引导到网页安装向导，而不是甩一个 500 错误页。
    // 对共享主机与面板托管环境而言，"打开网站即可安装"是最自然的方式。
    // 注意：setup.php 自身不加载 bootstrap，因此不会造成重定向循环。
    $self = isset($_SERVER['SCRIPT_NAME']) ? basename((string)$_SERVER['SCRIPT_NAME']) : '';
    if ($self !== 'setup.php' && !headers_sent()) {
        header('Location: ' . app_setup_url(), true, 302);
        exit;
    }
    app_fatal('config_missing');
}
$loaded = require $configFile;
if (!is_array($loaded)) {
    app_fatal('config_invalid');
}

/** 全局配置存储。helpers/auth/csrf 通过 cfg() 访问。 */
$GLOBALS['__cfg'] = $loaded + [
    'site_name'          => 'ElationPic',
    'site_description'   => 'A lightweight personal image hosting system.',
    // 底栏右侧文字。留空则显示 "Powered by ElationPic"。
    'footer_note'        => '',
    // 首页是否公开。关闭后访客看不到图片列表，但直链仍然有效
    // （图片由 Web 服务器直接返回，不经过 PHP，因此不受此开关影响）。
    'public_gallery'     => true,
    'timezone'           => 'UTC',
    'site_url'           => '',
    'base_path'          => '',
    'per_page'           => 20,
    'max_file_bytes'     => 10485760,
    'thumb_max_edge'     => 480,
    // 默认开启：手机照片普遍带 GPS，公开图床会把拍摄地点一起公开
    'strip_metadata'     => true,
    'force_https'        => true,
    'login_max_attempts' => 5,
    'login_window_secs'  => 900,
    'login_lockout_secs' => 900,
    'log_level'          => 'info',
];

/** 取得全局配置。helpers/auth/images/upload 均通过此函数访问。 */
function cfg(): array
{
    $c = $GLOBALS['__cfg'] ?? null;
    return is_array($c) ? $c : [];
}

/**
 * 从磁盘重新载入配置。
 *
 * 用途：后台设置页保存后，同一请求内后续的 cfg() 调用应当读到新值，
 * 否则页面会用旧值渲染，看起来像"保存了但没生效"。
 *
 * 只覆盖已知键，保持默认值兜底逻辑不变（与首次加载一致）。
 */
function cfg_reload(): void
{
    $f = DATA_DIR . '/config.php';
    if (!is_file($f)) {
        return;
    }
    $loaded = @include $f;
    if (!is_array($loaded)) {
        return;
    }
    $GLOBALS['__cfg'] = $loaded + ($GLOBALS['__cfg'] ?? []);
}

// 配置自检：哈希必须是「非空且非占位符的字符串」。
//
// 注意：这里刻意不用 (string) 强转。若用户把 admin_password_hash 误写成数组，
// (string)$array 会得到字符串 "Array"（并伴随一条 Warning），从而**通过**校验，
// 导致 password_verify 永远失败、无法登录，且没有任何提示指向真正的原因。
// 先用 is_string 判定可以避免这种沉默失败。
$__hash = $GLOBALS['__cfg']['admin_password_hash'] ?? null;
$__hashOk = is_string($__hash)
    && $__hash !== ''
    && $__hash !== 'REPLACE_ME'
    && $__hash[0] === '$'
    && strlen($__hash) >= 20;

if (!$__hashOk) {
    // 配置存在但尚未设置有效密码：同样引导到安装向导，
    // 用户在浏览器里设一次密码即可，无需改文件。
    $__self = isset($_SERVER['SCRIPT_NAME']) ? basename((string)$_SERVER['SCRIPT_NAME']) : '';
    if ($__self !== 'setup.php' && !headers_sent()) {
        header('Location: ' . app_setup_url(), true, 302);
        exit;
    }
    app_fatal('password_not_initialized');
}
unset($__hash, $__hashOk, $__self);

// ---------- 统一错误 / 异常处理 ----------
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    log_event('error', 'php_error', [
        'no'   => $no,
        'msg'  => $str,
        'file' => basename($file),
        'line' => $line,
    ]);
    return true;
});

set_exception_handler(static function (Throwable $ex): void {
    log_event('error', 'uncaught_exception', [
        'class' => get_class($ex),
        'msg'   => $ex->getMessage(),
        'file'  => basename($ex->getFile()),
        'line'  => $ex->getLine(),
    ]);
    app_respond_fatal();
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (is_array($err) && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_event('error', 'fatal_shutdown', [
            'msg'  => $err['message'],
            'file' => basename($err['file']),
            'line' => $err['line'],
        ]);
        app_respond_fatal();
    }
});

/** 输出统一失败响应。绝不泄露路径、堆栈、SQL 信息（§37）。 */
function app_respond_fatal(): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>出错了</title>'
       . '<p style="font-family:system-ui;padding:2rem;color:#333">操作失败，请稍后重试。</p>';
}

/** 配置缺失等启动期致命错误。 */
function app_fatal(string $reason): void
{
    log_event('error', 'boot_failed', ['reason' => $reason]);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>未配置</title>'
       . '<p style="font-family:system-ui;padding:2rem;color:#333">站点尚未完成配置，请联系管理员。</p>';
    exit;
}

// ---------- 部署安全检查（纵深防御） ----------
//
// 最危险的误配置：把站点根指向**项目根**而不是 public/。
// 此时 data/database.sqlite、deploy/、docs/、tests/fixtures/ 全部可被匿名下载。
//
// PHP 无法阻止静态文件被下载，但**可以检测**这种错配并留下明确线索，
// 避免用户在一无所知的情况下长期暴露数据。
if (!defined('APP_DEPLOY_CHECKED')) {
    define('APP_DEPLOY_CHECKED', true);

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
    if ($docRoot !== '' && !headers_sent()) {
        $realDoc = @realpath($docRoot);
        $realApp = @realpath(APP_ROOT);
        // 站点根 == 项目根 -> data/ 与其它敏感目录都在 Web 可达范围内
        if ($realDoc !== false && $realApp !== false && $realDoc === $realApp) {
            log_event('error', 'insecure_document_root', [
                'hint' => '站点根指向了项目根而非 public/，data/database.sqlite 等文件可被匿名下载。请把站点根改为 <项目>/public，或用 Web 服务器规则屏蔽 data/、src/、deploy/、docs/、tests/',
            ]);
        }
        // 站点根在项目根之内（如 public/），但 data/ 仍可能通过上级路径可达时，
        // Nginx 会做规范化，此处无法可靠判断，故只报上面这一种确定的情况。
    }
}

// ---------- 安全响应头（与 Nginx 配合，PHP 侧兜底） ----------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
}

// ---------- 会话加固（§10） ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Secure 标志的判定：
    //   实际上就在 HTTPS 上 -> 必然加 Secure（最安全）
    //   否则若 force_https=true（生产默认）-> 也加 Secure
    // 注意：在纯 HTTP 环境（如本地 http://127.0.0.1:8099 调试）下，
    // force_https=true 会让浏览器拒绝保存 Cookie，表现为「登录后仍停留在登录页」。
    // 因此当配置要求 HTTPS 但当前请求是 HTTP 时，记一条警告，便于排查。
    $onHttps = is_https();
    $wantHttps = (bool)cfg()['force_https'];
    $secure = $onHttps || $wantHttps;

    if ($wantHttps && !$onHttps && !defined('APP_HTTP_WARNED')) {
        define('APP_HTTP_WARNED', true);
        log_event('warning', 'cookie_secure_over_http', [
            'hint' => 'force_https=true 但当前为 HTTP 请求，浏览器将不保存会话 Cookie；本地调试请把 config.php 的 force_https 设为 false',
        ]);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '5');
    session_name('elation_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
