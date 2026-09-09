<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

// The add page guests should land on.
$target = $config['public_base_url'] . '/add.php';

// We return the target URL; the display renders the QR client-side with a small
// bundled JS library (no Composer/native extension needed on shared hosting).
json_response(['url' => $target]);
