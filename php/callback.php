<?php
/** OAuth redirect target: exchange the code for tokens, then go to the display. */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function render_message(string $title, string $detail): void
{
    $t = htmlspecialchars($title, ENT_QUOTES);
    $d = htmlspecialchars($detail, ENT_QUOTES);
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$t}</title>"
        . "<style>body{font-family:system-ui;background:#121212;color:#fff;display:grid;place-items:center;height:100vh;margin:0}"
        . ".card{max-width:520px;padding:32px;text-align:center}a{color:#1db954}</style></head>"
        . "<body><div class=\"card\"><h1>{$t}</h1><p>{$d}</p><p><a href=\"index.php\">Back to jukebox</a></p></div></body></html>";
    exit;
}

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if ($error) {
    render_message('Spotify authorization failed', (string) $error);
}

$expected = $_SESSION['oauth_state'] ?? null;
if (!$state || !$expected || !hash_equals((string) $expected, (string) $state)) {
    render_message('Invalid state', 'The login request could not be verified. Please try again.');
}
unset($_SESSION['oauth_state']);

if (!$code) {
    render_message('Login error', 'Spotify did not return an authorization code.');
}

try {
    $spotify->exchangeCodeForTokens((string) $code);
    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    error_log('[SpotiFrax] Token exchange failed: ' . $e->getMessage());
    render_message('Login error', $e->getMessage());
}
