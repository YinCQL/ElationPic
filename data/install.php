<?php
declare(strict_types=1);

/**
 * 一次性初始化脚本（CLI 专用）。
 *
 * 用法（Windows PowerShell）：
 *   php data/install.php
 *
 * 作用：
 *   1. 创建 data/ 与 data/logs/ 目录
 *   2. 交互式设置管理员密码，生成 password_hash
 *   3. 写出 data/config.php
 *   4. 创建 SQLite 数据库与 images 表
 *   5. 创建 public/uploads/ 与 public/uploads/thumbs/
 *
 * 安全：本脚本必须仅通过命令行运行，禁止经 Web 访问。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit(1);
}

$root    = dirname(__DIR__);
$dataDir = $root . '/data';
$pubDir  = $root . '/public';
$upDir   = $pubDir . '/uploads';
$thDir   = $upDir . '/thumbs';
$logDir  = $dataDir . '/logs';
$cfgFile = $dataDir . '/config.php';
$dbFile  = $dataDir . '/database.sqlite';

function say(string $s): void { fwrite(STDOUT, $s . "\n"); }

/** 普通输入（会回显）。 */
function ask(string $prompt): string {
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

/**
 * 读取密码：尽量不回显。
 *
 * 为什么需要：fgets(STDIN) 会把输入原样回显到终端，管理员密码因此会
 * 留在屏幕与终端滚动缓冲里。对一个以安全为第一优先级的项目，
 * 这个细节值得处理。
 *
 * 实现策略（按可用性依次尝试）：
 *   1. Windows：调用 PowerShell 的 Read-Host -AsSecureString，不回显
 *   2. 类 Unix：调用 stty -echo
 *   3. 都不行：退回普通输入，但**明确提示输入可见**
 *
 * 另外提供非交互方式（见文件末尾说明）：环境变量或 --password 参数，
 * 让用户自行选择不在终端留下痕迹的方式。
 */
function askPassword(string $prompt): string
{
    // --- Windows：用 PowerShell 读取，不回显 ---
    //
    // 关键：让 PowerShell 输出 **Base64 编码的 UTF-8 字节**，而不是直接输出密码。
    // 原因：exec() 捕获的是原始字节，而 Windows 控制台按系统 ANSI 代码页（中文系统
    // 是 GBK）输出。若密码含非 ASCII 字符，直接捕获会得到 GBK 字节却被当成 UTF-8，
    // 于是哈希算在了错误的字符上——**当时不报错，之后却永远登不进去**。
    // Base64 只有 ASCII，可安全跨任何代码页传输。
    if (DIRECTORY_SEPARATOR === '\\' && function_exists('exec')) {
        // 提示语固定为 ASCII，不经由用户输入拼装，因此无需转义——
        // 混用 escapeshellarg 与 PowerShell 的引号规则会造成双重转义，反而易错。
        $cmd = 'powershell -NoProfile -Command "'
             . '$s = Read-Host -AsSecureString AdminPassword; '
             . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
             . '$p = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b); '
             . '[Console]::Out.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($p)))"';
        // 先打印提示（PowerShell 的 Read-Host 会显示变量名，不够友好）
        fwrite(STDOUT, $prompt);
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code === 0) {
            $b64 = trim(implode('', $out));
            if ($b64 !== '' && preg_match('/^[A-Za-z0-9+\/]+=*$/', $b64) === 1) {
                $decoded = base64_decode($b64, true);
                if (is_string($decoded)) {
                    return trim($decoded);
                }
            }
        }
        // 失败则继续往下试
    }

    // --- 类 Unix：stty -echo ---
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        $stty = @shell_exec('stty -g 2>/dev/null');
        if (is_string($stty) && trim($stty) !== '') {
            @shell_exec('stty -echo 2>/dev/null');
            $val = ask($prompt);
            @shell_exec('stty ' . trim($stty) . ' 2>/dev/null');
            fwrite(STDOUT, "\n");
            return $val;
        }
    }

    // --- 退路：普通输入，但必须告知用户 ---
    fwrite(STDOUT, "[提示] 当前环境无法隐藏输入，你键入的密码会显示在屏幕上。\n");
    fwrite(STDOUT, "       若在意，可改用非交互方式（见下方说明）。\n");
    return ask($prompt);
}

/** 非交互获取密码：环境变量优先。 */
function envPassword(): string
{
    $v = getenv('ELATION_ADMIN_PASSWORD');
    return is_string($v) ? trim($v) : '';
}

say('');
say('=========================================');
say('  ElationPic — 初始化');
say('=========================================');
say('');

// ---- 目录 ----
// data/sessions 与 data/tmp 是 deploy/php.ini 中 session.save_path 与
// upload_tmp_dir 指向的位置。若不存在，PHP 会退回系统临时目录（安全性下降）
// 或直接无法启动会话，因此必须在此一并创建。
$sessDir = $dataDir . '/sessions';
$tmpDir  = $dataDir . '/tmp';

foreach ([$dataDir, $logDir, $sessDir, $tmpDir, $upDir, $thDir] as $d) {
    if (!is_dir($d)) {
        if (!@mkdir($d, 0775, true)) {
            say('[错误] 无法创建目录：' . $d);
            exit(1);
        }
        say('[创建] ' . $d);
    } else {
        say('[存在] ' . $d);
    }
}

// 上传目录占位文件（避免被目录列表或误删）
foreach ([$upDir . '/.gitkeep', $thDir . '/.gitkeep'] as $f) {
    if (!is_file($f)) { @file_put_contents($f, ''); }
}

// ---- 配置 ----
$existingHash = '';
if (is_file($cfgFile)) {
    // 已存在的 config.php 可能被手工改坏（语法错误、返回非数组）。
    // require 一个语法错误的文件会直接 fatal，用户只会看到 Parse error 而无从下手。
    // 这里先做语法检查再载入，失败则提示并允许继续覆盖。
    $lintOut = [];
    $lintCode = 0;
    @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($cfgFile), $lintOut, $lintCode);
    if ($lintCode !== 0) {
        say('[警告] 现有的 config.php 语法有误，将被重新生成：');
        foreach ($lintOut as $l) { say('        ' . $l); }
    } else {
        // 注意：PHP 8 中 include 一个存在语法错误的文件会抛 ParseError（属于 Throwable），
        // 加 @ 并不能抑制。因此必须用 try/catch 兜住。
        try {
            $cur = include $cfgFile;
            if (is_array($cur) && isset($cur['admin_password_hash']) && is_string($cur['admin_password_hash'])) {
                $existingHash = $cur['admin_password_hash'];
            } else {
                say('[警告] 现有的 config.php 未返回有效配置数组，将被重新生成。');
            }
        } catch (Throwable $e) {
            say('[警告] 读取现有 config.php 失败（' . get_class($e) . '），将被重新生成。');
        }
    }
}

$hash = '';
if ($existingHash !== '' && !str_contains($existingHash, 'REPLACE_ME')) {
    say('[提示] 已存在 config.php。');
    $ans = strtolower(ask('要重新设置管理员密码吗？(y/N) '));
    if ($ans === 'y' || $ans === 'yes') {
        $existingHash = '';
    } else {
        $hash = $existingHash;
    }
}

// 非交互模式：优先读环境变量，便于脚本化且不在终端留下痕迹。
//   set ELATION_ADMIN_PASSWORD=你的密码
//   php data\install.php
$envPw = envPassword();
if ($envPw !== '') {
    if (strlen($envPw) < 10) {
        say('[错误] 环境变量 ELATION_ADMIN_PASSWORD 少于 10 位。');
        exit(1);
    }
    $hash = password_hash($envPw, PASSWORD_DEFAULT);
    unset($envPw);
    if (!is_string($hash) || $hash === '') {
        say('[错误] password_hash 失败。');
        exit(1);
    }
    say('[完成] 已从环境变量读取密码并生成哈希。');
}

while ($hash === '') {
    $p1 = askPassword('请输入管理员密码（至少 10 位，建议 16 位以上）：');
    if (strlen($p1) < 10) {
        say('[拒绝] 密码太短，至少 10 位。');
        unset($p1);
        continue;
    }
    $p2 = askPassword('请再次输入以确认：');
    if ($p1 !== $p2) {
        say('[拒绝] 两次输入不一致。');
        unset($p1, $p2);
        continue;
    }
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    // 用后立即清除明文，减少驻留时间
    unset($p1, $p2);
    if (!is_string($hash) || $hash === '') {
        say('[错误] password_hash 失败。');
        exit(1);
    }
    say('[完成] 已生成密码哈希。');
}

// ---- 写出配置 ----
// 默认值内嵌在此，避免因 config.sample.php 被删除/移动而写出缺项的配置。
// 若模板存在则以模板为准（方便用户预先定制），但仍强制覆盖哈希。
$defaults = [
    'admin_password_hash' => '',
    'site_name'           => 'ElationPic',
    'site_description'    => 'A lightweight personal image hosting system.',
    'footer_note'         => '',         // 底栏右侧文字（备案号等），留空用默认
    'public_gallery'      => true,       // 设为 false 关闭首页公开（直链仍有效）
    'timezone'            => 'Asia/Shanghai',
    'site_url'            => '',         // 可选：如 'https://img.example.com'
    'base_path'           => '',         // 子目录部署时改为 '/子目录/public'
    'per_page'            => 20,
    'max_file_bytes'      => 10485760,   // 10 MB；改 20 MB 用 20971520
    'thumb_max_edge'      => 480,        // 设为 0 关闭缩略图
    'strip_metadata'      => true,       // 上传时移除 EXIF/GPS（隐私）
    'force_https'         => true,       // ★ 本地 HTTP 调试请改为 false
    'login_max_attempts'  => 5,
    'login_window_secs'   => 900,
    'login_lockout_secs'  => 900,
    'log_level'           => 'info',
];

$sample = $dataDir . '/config.sample.php';
$base = $defaults;
if (is_file($sample)) {
    $fromSample = require $sample;
    if (is_array($fromSample)) {
        $base = $fromSample + $defaults;
    } else {
        say('[警告] config.sample.php 未返回数组，改用内置默认值。');
    }
} else {
    say('[提示] 未找到 config.sample.php，使用内置默认值。');
}
$base['admin_password_hash'] = $hash;

$guard = "<?php\ndeclare(strict_types=1);\n\n"
       . "// 由 data/install.php 生成。请勿提交到版本库。\n"
       . "// 本文件位于 Web 根之外，正常情况下无法被 HTTP 访问。\n\n"
       . "// 纵深防御：若本文件被当作入口脚本直接请求（即站点根被误设为项目根），\n"
       . "// 则拒绝并返回 404，而不是安静地返回配置数组。\n"
       . "// include 时 __FILE__ 与 SCRIPT_FILENAME 不同，因此不影响正常加载。\n"
       . "if (isset(\$_SERVER['SCRIPT_FILENAME']) && is_string(\$_SERVER['SCRIPT_FILENAME'])\n"
       . "    && realpath(\$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {\n"
       . "    http_response_code(404);\n"
       . "    header('Content-Type: text/plain; charset=utf-8');\n"
       . "    echo \"Not Found\\n\";\n"
       . "    exit;\n"
       . "}\n\n";

$export = $guard . "return " . var_export($base, true) . ";\n";

if (@file_put_contents($cfgFile, $export, LOCK_EX) === false) {
    say('[错误] 无法写入 ' . $cfgFile);
    exit(1);
}
@chmod($cfgFile, 0660);
say('[完成] 已写入 ' . $cfgFile);

// ---- 数据库 ----
try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS images (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            filename      TEXT    NOT NULL UNIQUE,
            original_name TEXT    NOT NULL,
            mime          TEXT    NOT NULL,
            size          INTEGER NOT NULL,
            width         INTEGER NOT NULL DEFAULT 0,
            height        INTEGER NOT NULL DEFAULT 0,
            sha256        TEXT    NOT NULL DEFAULT \'\',
            thumb         INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_images_filename   ON images(filename)');
    $pdo->exec('CREATE INDEX        IF NOT EXISTS idx_images_created_at ON images(created_at DESC)');
    // 不建 sha256 索引：本图床无排重需求（§1），该索引仅服务于已被移除的排重函数
    @chmod($dbFile, 0660);
    say('[完成] 已创建数据库 ' . $dbFile);
} catch (Throwable $e) {
    say('[错误] 数据库初始化失败：' . $e->getMessage());
    exit(1);
}

// ---- 哈希自检：用一个必然错误的密码探测，必须返回 false ----
if (password_verify('___probe_should_never_match___', $hash) !== false) {
    say('[错误] 哈希自检异常（探测密码意外通过），请重新运行。');
    exit(1);
}
say('[完成] 哈希自检通过。');

say('');
say('=========================================');
say('  初始化完成');
say('=========================================');
say('下一步：');
say('  1. 配置 Nginx，站点根指向 ' . $pubDir);
say('  2. 使用 deploy/php.ini 的设置调整 PHP');
say('  3. 访问首页验证');
say('');
if (!empty($base['force_https'])) {
    say('★ 注意：当前 force_https = true。');
    say('  若你打算在纯 HTTP（如 http://127.0.0.1:8099）上测试，');
    say('  请先把 data/config.php 的 force_https 改为 false，');
    say('  否则浏览器不会保存 Session Cookie，表现为「密码正确却停在登录页」。');
    say('  生产 HTTPS 环境请保持 true。');
}
say('');
