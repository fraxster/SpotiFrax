<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

require_auth($spotify);

try {
    // Good moment to also advance the queue feeder.
    $jukebox->maybeFeed();
    json_response($spotify->getNowPlaying());
} catch (Throwable $e) {
    error_log('[SpotiFrax] Now playing failed: ' . $e->getMessage());
    json_response(['error' => 'now_playing_failed', 'message' => $e->getMessage()], 502);
}
