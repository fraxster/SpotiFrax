<?php
/**
 * Copy this file to config.local.php and fill in your values.
 * config.local.php is git-ignored so your secrets stay out of version control.
 *
 * On shared hosting where you can't set environment variables, this file is the
 * simplest way to configure the app — just upload it with your values.
 */

return [
    // From https://developer.spotify.com/dashboard
    'SPOTIFY_CLIENT_ID'     => 'your_client_id_here',
    'SPOTIFY_CLIENT_SECRET' => 'your_client_secret_here',

    // Must EXACTLY match a Redirect URI registered in your Spotify app.
    // In production use your https domain, e.g. https://your-domain.com/callback.php
    'SPOTIFY_REDIRECT_URI'  => 'http://127.0.0.1:8888/callback.php',

    // The public base URL guests reach (used to build the QR code).
    'PUBLIC_BASE_URL'       => 'http://127.0.0.1:8888',

    // Password for the management page (admin.php) and moderator actions like
    // removing songs. Choose a strong value. Leave empty to disable admin.
    'ADMIN_PASSWORD'        => 'change_this_admin_password',

    // Optional: absolute path to a writable directory for JSON state.
    // Defaults to php/data. Point this OUTSIDE the web root if you can.
    // 'DATA_DIR'           => '/home/youruser/spotifrax-data',
];
