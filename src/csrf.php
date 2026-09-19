<?php
declare(strict_types=1);

/**
 * CSRF 防护（§24）。Token 由 random_bytes 生成，hash_equals 恒时比较。
 */

const CSRF_SESSION_KEY = 'csrf_token';
const CSRF_FIELD_NAME  = 'csrf_token';
const CSRF_HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

/** 取得（必要时生成）当前会话的 CSRF token。 */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_SESSION_KEY]) || !is_string($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

/** 恒时校验 CSRF token。 */
function csrf_verify(?string $token): bool
{
    $expected = $_SESSION[CSRF_SESSION_KEY] ?? '';
    if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

/** 从 POST 字段或请求头取 token。 */
function csrf_from_request(): ?string
{
    if (isset($_POST[CSRF_FIELD_NAME]) && is_string($_POST[CSRF_FIELD_NAME])) {
        return $_POST[CSRF_FIELD_NAME];
    }
    $h = $_SERVER[CSRF_HEADER_NAME] ?? null;
    return is_string($h) ? $h : null;
}

/** 渲染隐藏 input。 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD_NAME . '" value="' . e(csrf_token()) . '">';
}

/**
 * 校验请求中的 CSRF token，失败即记录并终止。
 * 仅允许 POST。
 */
function csrf_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        log_event('warning', 'forbidden_request', ['reason' => 'method_not_post', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        header('Allow: POST');
        json_out(['ok' => false, 'error' => fail_message()], 405);
    }
    if (!csrf_verify(csrf_from_request())) {
        log_event('warning', 'csrf_failed', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        json_out(['ok' => false, 'error' => fail_message()], 403);
    }
}
