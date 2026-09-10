<?php
/** Public: the display customization settings (logo, widget sizes, title). */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

json_response([
    'settings' => $settings->get(),
    'isAdmin'  => is_admin(),
    'adminEnabled' => (string) ($config['admin_password'] ?? '') !== '',
]);
