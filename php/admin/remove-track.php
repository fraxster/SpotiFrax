<?php
/** Moderator: remove a track from the queue. POST { trackId }. */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$gid = guest_id();
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$trackId = (string) ($body['trackId'] ?? '');
if ($trackId === '') {
    json_response(['error' => 'bad_request', 'message' => 'trackId is required.'], 400);
}

$removed = $jukebox->removeTrack($trackId);
json_response(['ok' => true, 'removed' => $removed, 'queue' => $jukebox->getQueueFor($gid)]);
