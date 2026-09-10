<?php
/** Admin: save display settings. POST JSON with any of logoUrl,title,*Scale. */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_response(['error' => 'bad_request', 'message' => 'Expected a JSON object.'], 400);
}

try {
    $saved = $settings->save($body);
    json_response(['ok' => true, 'settings' => $saved]);
} catch (Throwable $e) {
    error_log('[SpotiFrax] Save settings failed: ' . $e->getMessage());
    json_response(['error' => 'save_failed', 'message' => $e->getMessage()], 500);
}
