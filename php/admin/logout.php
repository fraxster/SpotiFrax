<?php
/** Admin logout: ends the admin session. */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

admin_logout();
json_response(['ok' => true]);
