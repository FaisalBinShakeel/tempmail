<?php
/** POST /api/create-custom.php — claim a chosen prefix (FR-2.5). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Inbox.php';

api_boot();
api_require_method('POST');
api_require_csrf();
[$max, $window] = rate_limit_for('generate');
api_rate_limit('generate', $max, $window); // NFR-7 — custom addresses count too

$body   = json_body();
$prefix = $body['prefix'] ?? ($_POST['prefix'] ?? '');
if (!is_string($prefix)) {
    json_error('Invalid prefix.', 400);
}

try {
    $inbox = Inbox::createCustom($prefix);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 422);
}

set_owner_token($inbox['token']);

json_response([
    'address'     => $inbox['address'],
    'expires_at'  => $inbox['expires_at'],
    'expires_in'  => (int) $inbox['expires_in'],
    'extensions'  => (int) $inbox['extensions'],
    'server_time' => date('Y-m-d H:i:s'),
]);
