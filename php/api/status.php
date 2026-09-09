<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

guest_id(); // ensure the visitor has a cookie
json_response(['authenticated' => $spotify->isAuthenticated()]);
