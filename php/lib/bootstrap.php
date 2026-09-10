<?php
/**
 * Shared bootstrap: builds the config, Store, Spotify client, and Jukebox, and
 * assigns each visitor an anonymous guest id cookie. Include this at the top of
 * every entry-point script.
 */

declare(strict_types=1);

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Spotify.php';
require_once __DIR__ . '/Jukebox.php';
require_once __DIR__ . '/Settings.php';

/** @var array $config */
$config = require __DIR__ . '/../config.php';

$store = new Store((string) $config['data_dir']);
$spotify = new Spotify($config, $store);
$jukebox = new Jukebox($store, $spotify);
$settings = new Settings($store);

/** Return this visitor's guest id, setting a cookie if they don't have one. */
function guest_id(): string
{
    if (!empty($_COOKIE['jukebox_gid']) && preg_match('/^[a-f0-9]{24}$/', $_COOKIE['jukebox_gid'])) {
        return $_COOKIE['jukebox_gid'];
    }
    $gid = bin2hex(random_bytes(12));
    // 30-day anonymous identity. Not security-grade — it's a party.
    setcookie('jukebox_gid', $gid, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
    $_COOKIE['jukebox_gid'] = $gid;
    return $gid;
}

/** Send a JSON response and stop. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Guard: require the host to be authenticated for an API call. */
function require_auth(Spotify $spotify): void
{
    if (!$spotify->isAuthenticated()) {
        json_response(['error' => 'not_authenticated', 'message' => 'Host has not logged in to Spotify yet.'], 401);
    }
}

/** Start a session if one isn't running (used for admin login state). */
function ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/** True if the current session is a logged-in admin/moderator. */
function is_admin(): bool
{
    ensure_session();
    return !empty($_SESSION['is_admin']);
}

/**
 * Attempt admin login with a password. Uses a constant-time comparison and
 * refuses to authenticate when no ADMIN_PASSWORD is configured.
 */
function admin_login(array $config, string $password): bool
{
    $expected = (string) ($config['admin_password'] ?? '');
    if ($expected === '') {
        return false; // admin disabled until a password is set
    }
    if (!hash_equals($expected, $password)) {
        return false;
    }
    ensure_session();
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    return true;
}

function admin_logout(): void
{
    ensure_session();
    unset($_SESSION['is_admin']);
}

/** Guard: require an admin session for a protected API call. */
function require_admin(): void
{
    if (!is_admin()) {
        json_response(['error' => 'forbidden', 'message' => 'Admin login required.'], 403);
    }
}
