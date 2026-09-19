<?php
declare(strict_types=1);

/**
 * 登出（§10）。仅 POST + CSRF，避免被诱导链接登出。
 */

require __DIR__ . '/../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    log_event('warning', 'forbidden_request', ['reason' => 'logout_not_post']);
    header('Location: ' . url('/'), true, 302);
    exit;
}

if (!csrf_verify(csrf_from_request())) {
    log_event('warning', 'csrf_failed', ['uri' => '/logout.php']);
    header('Location: ' . url('/'), true, 302);
    exit;
}

auth_logout();
header('Location: ' . url('/'), true, 302);
exit;
