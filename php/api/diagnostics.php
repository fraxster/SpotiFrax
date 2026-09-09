<?php
/**
 * Diagnostics: reports what the app actually sees for configuration, WITHOUT
 * revealing secrets. Use this to debug "client_id not present" and similar.
 *
 * Visit:  https://your-domain.com/api/diagnostics.php
 *
 * Delete this file once things work — it's a debugging aid, not part of the app.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__;                       // .../php/api
$phpRoot = dirname($dir);             // .../php
$localFile = $phpRoot . '/config.local.php';

// Load config the same way the app does.
$config = require $phpRoot . '/config.php';

function mask(?string $v): string
{
    $v = (string) $v;
    if ($v === '') {
        return '(empty)';
    }
    $len = strlen($v);
    // Show only length + first 2 chars so you can confirm it's set, not what it is.
    return substr($v, 0, 2) . str_repeat('*', max(0, $len - 2)) . " (len {$len})";
}

// Inspect the raw config.local.php array (never printing secret values) so we
// can see whether the client id/secret keys are actually present and non-empty.
$localRaw = is_file($localFile) ? @include $localFile : null;
function key_report($arr, string $key): array
{
    if (!is_array($arr) || !array_key_exists($key, $arr)) {
        return ['present' => false];
    }
    $v = (string) $arr[$key];
    $trimmed = trim($v);
    return [
        'present'          => true,
        'length'           => strlen($v),
        'blank_or_spaces'  => $trimmed === '',
        'looks_like_placeholder' => stripos($trimmed, 'your_') === 0 || stripos($trimmed, 'here') !== false,
        'has_surrounding_whitespace' => $v !== $trimmed,
    ];
}

$report = [
    'php_version'        => PHP_VERSION,
    'curl_loaded'        => extension_loaded('curl'),
    'app_dir'            => $phpRoot,
    'config_local_keys'  => [
        'all_keys'              => is_array($localRaw) ? array_keys($localRaw) : null,
        'SPOTIFY_CLIENT_ID'     => key_report($localRaw, 'SPOTIFY_CLIENT_ID'),
        'SPOTIFY_CLIENT_SECRET' => key_report($localRaw, 'SPOTIFY_CLIENT_SECRET'),
    ],
    'getenv_probe' => [
        // What getenv() literally returns on this host for the credential keys.
        'client_id_type'      => gettype(getenv('SPOTIFY_CLIENT_ID')),
        'client_id_is_false'  => getenv('SPOTIFY_CLIENT_ID') === false,
        'client_id_is_empty'  => getenv('SPOTIFY_CLIENT_ID') === '',
        'client_id_strlen'    => is_string(getenv('SPOTIFY_CLIENT_ID')) ? strlen(getenv('SPOTIFY_CLIENT_ID')) : null,
        // getenv with local_only vs including $_ENV/$_SERVER can differ per host.
        'server_has_key'      => array_key_exists('SPOTIFY_CLIENT_ID', $_SERVER),
        'env_superglobal_has_key' => array_key_exists('SPOTIFY_CLIENT_ID', $_ENV),
    ],
    // Byte-level view of the id value FROM YOUR FILE (hex of first 4 bytes only,
    // so we can spot invisible/leading characters without revealing the secret).
    'client_id_first_bytes_hex' => (is_array($localRaw) && isset($localRaw['SPOTIFY_CLIENT_ID']))
        ? bin2hex(substr((string) $localRaw['SPOTIFY_CLIENT_ID'], 0, 4))
        : null,
    'config_local' => [
        'expected_path' => $localFile,
        'exists'        => is_file($localFile),
        'readable'      => is_file($localFile) && is_readable($localFile),
        'returns_array' => is_file($localFile) ? is_array(@include $localFile) : null,
    ],
    'env_vars_seen' => [
        // true only if a NON-empty env var of this name exists.
        'SPOTIFY_CLIENT_ID'     => getenv('SPOTIFY_CLIENT_ID') !== false && getenv('SPOTIFY_CLIENT_ID') !== '',
        'SPOTIFY_CLIENT_SECRET' => getenv('SPOTIFY_CLIENT_SECRET') !== false && getenv('SPOTIFY_CLIENT_SECRET') !== '',
    ],
    'effective_config' => [
        'client_id'       => mask($config['client_id'] ?? ''),
        'client_secret'   => mask($config['client_secret'] ?? ''),
        'redirect_uri'    => $config['redirect_uri'] ?? '',
        'public_base_url' => $config['public_base_url'] ?? '',
        'data_dir'        => $config['data_dir'] ?? '',
    ],
    'data_dir_writable' => is_dir($config['data_dir'] ?? '') && is_writable($config['data_dir'] ?? ''),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
