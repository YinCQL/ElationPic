<?php
declare(strict_types=1);

/**
 * 首次安装向导（网页版，§9 §10）。
 *
 * 为什么提供网页版：本项目面向的是"面板托管 + 直接在浏览器里操作"的场景
 * （如小皮面板）。用户不必打开命令行、不必寻找 php.exe 路径，
 * 打开站点即可完成初始化。
 *
 * 这只做三件事：创建目录、写入 data/config.php（含密码哈希）、建立数据库。
 * 完成后本页自动失效。
 *
 * 安全设计：
 *   - 已配置（存在有效的 admin_password_hash）时，本页拒绝运行
 *   - 仅允许来自回环地址的请求执行安装，或必须提供 data/setup-token.txt 中的令牌
 *     （回环判断覆盖"本机面板"场景；令牌覆盖"远程部署"场景）
 *   - 仅接受 POST，且校验 CSRF
 *   - 密码不落日志、不回显、只存哈希
 */

// 本页在配置完成前必须可用，因此**不能**加载 bootstrap.php（后者会因缺配置而终止）。
// 这里只做最小限度的引导。
define('APP_ROOT_SETUP', dirname(__DIR__));
$dataDir = APP_ROOT_SETUP . '/data';
$pubDir  = APP_ROOT_SETUP . '/public';
$cfgFile = $dataDir . '/config.php';
$dbFile  = $dataDir . '/database.sqlite';
$tokFile = $dataDir . '/setup-token.txt';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function s_e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function s_page(string $title, string $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . s_e($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<main class="wrap"><section class="panel panel-narrow">';
    echo '<h1>' . s_e($title) . '</h1>' . $body;
    echo '</section></main></body></html>';
    exit;
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
    s_page('已完成安装', '<p class="muted">本站点已经完成初始化。</p>'
        . '<p class="muted">如需修改站点信息或管理员密码，请登录后在后台「设置」中操作。</p>'
        . '<p><a class="btn btn-primary" href="./">返回首页</a></p>', 403);
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
            '<p class="muted">本页只能从本机（127.0.0.1）访问。若你确实需要从远程安装，'
          . '请先在服务器上创建 <code>data/setup-token.txt</code>，内容为任意随机字符串，'
          . '然后在下方填入相同内容。</p>',
            403);
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

$error = '';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $posted = isset($_POST['setup_csrf']) && is_string($_POST['setup_csrf']) ? $_POST['setup_csrf'] : '';
    if (!hash_equals($csrf, $posted)) {
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
            $dirs = [$dataDir, $dataDir . '/logs', $dataDir . '/sessions', $dataDir . '/tmp',
                     $pubDir . '/uploads', $pubDir . '/uploads/thumbs'];
            $dirFail = '';
            foreach ($dirs as $d) {
                if (!is_dir($d) && !@mkdir($d, 0775, true)) { $dirFail = $d; break; }
            }
            if ($dirFail !== '') {
                $error = '无法创建目录，请检查权限。';
            } else {
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
                        'site_name'           => $siteName !== '' ? $siteName : 'Elation Image',
                        'site_description'    => $siteDesc,
                        'timezone'            => 'Asia/Shanghai',
                        'site_url'            => $siteUrl,   // 留空则按访问主机自动推导
                        'base_path'           => $derivedBase,
                        'per_page'            => $perPage,
                        'max_file_bytes'      => 10485760,
                        'thumb_max_edge'      => 480,
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
                                '<p class="muted">初始化成功。请立即登录并开始使用。</p>'
                              . '<p><a class="btn btn-primary" href="login.php">前往登录</a></p>');
                        }
                    }
                }
            }
        }
    }
}

// ---------- 渲染表单 ----------
$errHtml = $error !== '' ? '<p class="alert" role="alert">' . s_e($error) . '</p>' : '';
$tokenField = '';
if ($tokenRequired) {
    $tokenField = '<label class="field"><span>安装令牌</span>'
                . '<input type="text" name="token" required autocomplete="off"></label>';
}

// 表单里的站点网址用一个"猜出来"的默认值：当前访问的主机。
// 这只是给用户的提示，仍然是可留空的 —— 留空表示每次按实际访问主机推导，
// 对"用 IP + 端口访问"或"同时用多个域名访问"的场景更合适。
$guessOrigin = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

$body = $errHtml
      . '<p class="muted">这是首次安装向导。设置好下面几项，本站即可使用。'
      . '除管理员密码外都可以稍后在后台「设置」里修改。</p>'
      . '<form method="post" action="setup.php" autocomplete="off">'
      . '<input type="hidden" name="setup_csrf" value="' . s_e($csrf) . '">'

      . '<h2 class="settings-group">站点</h2>'
      . '<label class="field"><span>站点标题</span>'
      . '<input type="text" name="site_name" value="Elation Image" maxlength="60" required>'
      . '<small class="hint">显示在浏览器标签页与页面顶部。</small></label>'
      . '<label class="field"><span>站点描述（可选）</span>'
      . '<input type="text" name="site_description" maxlength="160" placeholder="私人图片托管">'
      . '<small class="hint">显示在首页标题下方。</small></label>'
      . '<label class="field"><span>站点网址（可选）</span>'
      . '<input type="text" name="site_url" maxlength="200" placeholder="' . s_e($guessOrigin) . '">'
      . '<small class="hint">用于生成「复制直链」的域名前缀。'
      . '留空则自动按访问时的主机推导（推荐，除非你绑定了固定域名）。</small></label>'
      . '<label class="field"><span>每页显示图片数</span>'
      . '<input type="number" name="per_page" value="20" min="1" max="120">'
      . '<small class="hint">1 ~ 120。</small></label>'

      . '<h2 class="settings-group">管理员</h2>'
      . '<label class="field"><span>管理员密码（至少 10 位）</span>'
      . '<input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password">'
      . '<small class="hint">本站只有一个管理员账户，没有用户名，登录时只输密码。</small></label>'
      . '<label class="field"><span>再次输入密码</span>'
      . '<input type="password" name="password2" required minlength="10" maxlength="200" autocomplete="new-password">'
      . '<small class="hint">请务必记牢 —— 密码无法找回，只能重装。</small></label>'

      . $tokenField
      . '<button type="submit" class="btn btn-primary">完成安装</button>'
      . '</form>'
      . '<p class="muted" style="margin-top:14px;font-size:12px">'
      . '提示：本页面在安装完成后会自动失效。日后如需修改站点信息或密码，'
      . '登录后在后台「设置」中操作即可，无需重装。</p>';

s_page('安装 Elation Image', $body);
