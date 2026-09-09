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

/** @var array $config */
$config = require __DIR__ . '/../config.php';

$store = new Store((string) $config['data_dir']);
$spotify = new Spotify($config, $store);
$jukebox = new Jukebox($store, $spotify);

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
