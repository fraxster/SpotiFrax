<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$gid = guest_id();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    // Add a song to the request queue (implicitly upvotes it).
    require_auth($spotify);

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $uri = (string) ($body['uri'] ?? '');
    if (strpos($uri, 'spotify:track:') !== 0) {
        json_response(['error' => 'bad_uri', 'message' => 'A valid track URI is required.'], 400);
    }
    $trackId = explode(':', $uri)[2] ?? '';

    try {
        $track = $spotify->getTrack($trackId);
        $jukebox->addTrack($track, $gid);
        json_response(['ok' => true, 'queue' => $jukebox->getQueueFor($gid)]);
    } catch (Throwable $e) {
        error_log('[SpotiFrax] Add to queue failed: ' . $e->getMessage());
        json_response(['error' => 'queue_failed', 'message' => $e->getMessage()], 502);
    }
}

// GET: return the vote-sorted queue. This is also a good moment to run the
// request-time feeder (no background process on shared hosting).
$jukebox->maybeFeed();
json_response(['queue' => $jukebox->getQueueFor($gid)]);
