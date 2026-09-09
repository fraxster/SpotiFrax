<?php
/** Start the Spotify OAuth flow (host login). */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

// CSRF-protect the callback with a random state stored in the session cookie.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

header('Location: ' . $spotify->buildAuthorizeUrl($state));
exit;
