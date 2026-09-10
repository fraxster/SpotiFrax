<?php
/** Admin login: POST { password } -> starts an admin session. */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$password = (string) ($body['password'] ?? '');

if ((string) ($config['admin_password'] ?? '') === '') {
    json_response(['error' => 'admin_disabled', 'message' => 'No ADMIN_PASSWORD is set in config.'], 400);
}

if (admin_login($config, $password)) {
    json_response(['ok' => true]);
}

// Small delay to make brute-forcing over the network less pleasant.
usleep(400000);
json_response(['error' => 'invalid_password', 'message' => 'Incorrect password.'], 401);
