<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$gid = guest_id();
require_auth($spotify);

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$trackId = (string) ($body['trackId'] ?? '');
if ($trackId === '') {
    json_response(['error' => 'bad_request', 'message' => 'trackId is required.'], 400);
}

try {
    $jukebox->toggleVote($trackId, $gid);
    json_response(['ok' => true, 'queue' => $jukebox->getQueueFor($gid)]);
} catch (Throwable $e) {
    // toggleVote throws a plain RuntimeException when the track is gone.
    json_response(['error' => 'not_found', 'message' => $e->getMessage()], 404);
}
