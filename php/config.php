<?php
/**
 * Configuration loader.
 *
 * Reads settings from environment variables when present (good for hosts that
 * let you set env vars), otherwise falls back to a local config.local.php file
 * so it also works on plain shared hosting where you just upload files.
 *
 * Copy config.local.example.php to config.local.php and fill it in.
 */

declare(strict_types=1);

function env_or(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    return $default;
}

// Load optional local overrides (returns an associative array).
$local = [];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    /** @var array $local */
    $local = require $localFile;
    if (!is_array($local)) {
        $local = [];
    }
}

function cfg_get(array $local, string $key, ?string $default = null): ?string
{
    // config.local.php is the explicit, uploaded source of truth: if it provides
    // a non-empty value, use it. Only fall back to an environment variable when
    // the file doesn't set the key. (Some shared hosts expose phantom/empty env
    // vars that otherwise shadowed the file — this ordering avoids that trap.)
    if (array_key_exists($key, $local)) {
        $fileVal = trim((string) $local[$key]);
        if ($fileVal !== '') {
            return $fileVal;
        }
    }
    $env = env_or($key);
    if ($env !== null) {
        return trim($env);
    }
    return $default;
}

$config = [
    'client_id'     => cfg_get($local, 'SPOTIFY_CLIENT_ID', ''),
    'client_secret' => cfg_get($local, 'SPOTIFY_CLIENT_SECRET', ''),
    'redirect_uri'  => cfg_get($local, 'SPOTIFY_REDIRECT_URI', 'http://127.0.0.1:8888/callback.php'),
    'public_base_url' => rtrim((string) cfg_get($local, 'PUBLIC_BASE_URL', 'http://127.0.0.1:8888'), '/'),
    // Where JSON state files live. Must be writable by the web server.
    'data_dir'      => cfg_get($local, 'DATA_DIR', __DIR__ . '/data'),
    // Scopes required to read playback + control the queue.
    'scopes'        => 'user-read-playback-state user-modify-playback-state user-read-currently-playing',
];

// Basic sanity warnings (shown only in server logs, never to guests).
if ($config['client_id'] === '' || $config['client_secret'] === '') {
    error_log('[SpotiFrax] Missing SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET.');
}

return $config;
