<?php
/** Start the Spotify OAuth flow (host login). */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

// Fail early with a clear message instead of bouncing to Spotify with an empty
// client_id (which returns the cryptic "client_id: not present" error there).
if (empty($config['client_id']) || empty($config['client_secret'])) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Config error</title>'
        . '<div style="font-family:system-ui;background:#121212;color:#fff;'
        . 'min-height:100vh;display:grid;place-items:center;text-align:center;padding:24px">'
        . '<div style="max-width:560px"><h1>Spotify credentials are not loaded</h1>'
        . '<p style="color:#b3b3b3">The app started but <code>client_id</code>/<code>client_secret</code> are empty, '
        . 'so it can\'t start the Spotify login. This almost always means <code>config.local.php</code> '
        . 'wasn\'t found or didn\'t load.</p>'
        . '<p style="color:#b3b3b3">Open <a style="color:#1db954" href="api/diagnostics.php">api/diagnostics.php</a> '
        . 'to see exactly what the app sees, then fix it and reload this page.</p></div></div>';
    exit;
}

// CSRF-protect the callback with a random state stored in the session cookie.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

header('Location: ' . $spotify->buildAuthorizeUrl($state));
exit;
