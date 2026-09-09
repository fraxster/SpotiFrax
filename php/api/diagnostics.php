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

$report = [
    'php_version'        => PHP_VERSION,
    'curl_loaded'        => extension_loaded('curl'),
    'app_dir'            => $phpRoot,
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
