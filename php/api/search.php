<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

require_auth($spotify);

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '') {
    json_response(['tracks' => []]);
}

try {
    $tracks = $spotify->searchTracks($q);
    json_response(['tracks' => $tracks]);
} catch (Throwable $e) {
    error_log('[SpotiFrax] Search failed: ' . $e->getMessage());
    json_response(['error' => 'search_failed', 'message' => $e->getMessage()], 502);
}
