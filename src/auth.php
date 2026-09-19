<?php
declare(strict_types=1);

/**
 * 管理员认证与登录限速（§9 §10 §28）。
 * 单管理员，无用户表；密码用 password_hash / password_verify。
 */

const AUTH_SESSION_KEY = 'admin_authenticated';

/** 是否已登录。 */
function auth_is_logged_in(): bool
{
    return ($_SESSION[AUTH_SESSION_KEY] ?? false) === true;
}

/** 取得登录限速状态文件路径。 */
function auth_rate_file(): string
{
    return DATA_DIR . '/logs/login_attempts.json';
}

/**
 * 读取限速表。结构： { "<ip>": {"fails":[ts,...], "locked_until":ts} }
 * 纯文件实现：无 Redis（§28 禁止），且不受攻击者丢弃 Cookie 影响。
 */
function auth_rate_load(): array
{
    $f = auth_rate_file();
    if (!is_file($f)) {
        return [];
    }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** 原子写入限速表。 */
function auth_rate_save(array $data): void
{
    $f = auth_rate_file();
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data), LOCK_EX) !== false) {
        @rename($tmp, $f);
    } else {
        @unlink($tmp);
    }
}

/** 清理过期条目，避免文件无限增长。 */
function auth_rate_gc(array $data, int $now, int $window): array
{
    $out = [];
    foreach ($data as $ip => $rec) {
        if (!is_array($rec)) { continue; }
        $fails = array_values(array_filter(
            (array)($rec['fails'] ?? []),
            static fn($t) => is_int($t) && $t > $now - $window
        ));
        $locked = (int)($rec['locked_until'] ?? 0);
        if ($fails === [] && $locked <= $now) { continue; }
        $out[$ip] = ['fails' => $fails, 'locked_until' => $locked];
    }
    return $out;
}

/** 当前 IP 是否处于锁定中。返回剩余秒数，0 表示未锁定。 */
function auth_is_locked(): int
{
    $cfg = cfg();
    $now = time();
    $ip = client_ip();
    $data = auth_rate_gc(auth_rate_load(), $now, (int)$cfg['login_window_secs']);
    $lockedUntil = (int)($data[$ip]['locked_until'] ?? 0);
    return $lockedUntil > $now ? $lockedUntil - $now : 0;
}

/** 记录一次登录失败，必要时施加锁定。 */
function auth_record_failure(): void
{
    $cfg = cfg();
    $now = time();
    $ip = client_ip();
    $window = (int)$cfg['login_window_secs'];

    $data = auth_rate_gc(auth_rate_load(), $now, $window);
    $rec = $data[$ip] ?? ['fails' => [], 'locked_until' => 0];
    $rec['fails'][] = $now;

    if (count($rec['fails']) >= (int)$cfg['login_max_attempts']) {
        $rec['locked_until'] = $now + (int)$cfg['login_lockout_secs'];
        $rec['fails'] = [];
        log_event('warning', 'login_locked', ['ip' => $ip, 'secs' => (int)$cfg['login_lockout_secs']]);
    }

    $data[$ip] = $rec;
    auth_rate_save($data);
}

/** 登录成功后清除该 IP 的失败记录。 */
function auth_clear_failures(): void
{
    $now = time();
    $ip = client_ip();
    $data = auth_rate_gc(auth_rate_load(), $now, (int)cfg()['login_window_secs']);
    unset($data[$ip]);
    auth_rate_save($data);
}

/**
 * 尝试登录。
 * 返回 ['ok'=>bool, 'locked'=>int, 'message'=>string]
 */
function auth_login(string $password): array
{
    $cfg = cfg();

    $remain = auth_is_locked();
    if ($remain > 0) {
        log_event('warning', 'login_blocked_locked', ['ip' => client_ip(), 'remain' => $remain]);
        return ['ok' => false, 'locked' => $remain, 'message' => '尝试次数过多，请 ' . ceil($remain / 60) . ' 分钟后再试。'];
    }

    // 与 bootstrap.php 的自检保持一致：必须是字符串，且形如哈希。
    // 这里再查一次是因为 auth_login() 是公开函数，不应假设调用方已做过校验。
    $hash = $cfg['admin_password_hash'] ?? null;
    if (!is_string($hash) || $hash === '' || str_contains($hash, 'REPLACE_ME')
        || $hash[0] !== '$' || strlen($hash) < 20) {
        log_event('error', 'login_misconfigured');
        return ['ok' => false, 'locked' => 0, 'message' => fail_message()];
    }

    // 刻意不写 "$password === '' ||" 短路：
    // 空密码若跳过 password_verify，会比错误密码快 ~50-100ms（bcrypt 成本），
    // 形成可测量的时序差异，且允许攻击者以极低成本空请求刷接口。
    // password_verify('', $hash) 本身就会正确返回 false。
    if (!password_verify($password, $hash)) {
        auth_record_failure();
        // 固定延迟，抬高暴力破解成本
        usleep(300000);
        log_event('warning', 'login_failed', ['ip' => client_ip()]);

        // auth_record_failure() 达到阈值时会清空 fails 并设置 locked_until，
        // 因此必须先判断是否已被锁定，再计算剩余次数，否则会误报"剩余 5 次"。
        $locked = auth_is_locked();
        if ($locked > 0) {
            return [
                'ok'      => false,
                'locked'  => $locked,
                'message' => '尝试次数过多，请 ' . (int)ceil($locked / 60) . ' 分钟后再试。',
            ];
        }

        $ip = client_ip();
        $rate = auth_rate_load();
        $used = count($rate[$ip]['fails'] ?? []);
        $left = max(0, (int)$cfg['login_max_attempts'] - $used);

        return [
            'ok'      => false,
            'locked'  => 0,
            'message' => '密码错误。' . ($left > 0 ? '剩余尝试次数 ' . $left . ' 次。' : ''),
        ];
    }

    // 登录成功：防会话固定（§10）
    session_regenerate_id(true);
    $_SESSION[AUTH_SESSION_KEY] = true;
    $_SESSION['admin_login_at'] = time();
    auth_clear_failures();
    log_event('info', 'login_success', ['ip' => client_ip()]);
    return ['ok' => true, 'locked' => 0, 'message' => ''];
}

/** 登出：彻底销毁会话与 Cookie（§10）。 */
function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    log_event('info', 'logout', ['ip' => client_ip()]);
}

/**
 * 强制管理员权限（§12 §36）。
 * HTML 页面跳转登录；AJAX/JSON 请求返回 403 JSON。
 * 每个入口文件独立调用，不依赖前端隐藏按钮。
 */
function require_admin(): void
{
    if (auth_is_logged_in()) {
        return;
    }

    log_event('warning', 'forbidden_request', [
        'uri'  => $_SERVER['REQUEST_URI'] ?? '',
        'ip'   => client_ip(),
    ]);

    if (wants_json()) {
        json_out(['ok' => false, 'error' => '需要管理员登录。'], 403);
    }

    header('Location: ' . url('/login.php'), true, 302);
    exit;
}

/**
 * 强制管理员权限，且必须以 JSON 响应失败（用于 upload.php / delete.php 等 API 端点）。
 *
 * 与 require_admin() 的区别：绝不返回 302 重定向。
 * §12 要求「即使攻击者直接访问 /upload.php 也必须因为没有管理员 Session 而拒绝」，
 * 端点返回 302 会把匿名攻击者引导到登录页而非明确拒绝，语义不正确。
 */
function require_admin_json(): void
{
    if (auth_is_logged_in()) {
        return;
    }

    log_event('warning', 'forbidden_request', [
        'uri'  => $_SERVER['REQUEST_URI'] ?? '',
        'ip'   => client_ip(),
        'api'  => true,
    ]);

    json_out(['ok' => false, 'error' => '需要管理员登录。'], 403);
}

/** 请求是否期望 JSON 响应。 */
function wants_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $xrw === 'xmlhttprequest';
}
