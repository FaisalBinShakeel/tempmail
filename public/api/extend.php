<?php
/** POST /api/extend.php — add one hour, up to MAX_EXTENSIONS (FR-5.2). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Inbox.php';

api_boot();
api_require_method('POST');
api_require_csrf();
api_rate_limit('mutate', 60, 60);

$inbox = api_require_inbox();

try {
    $extended = Inbox::extend((int) $inbox['id']);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 422, ['max_extensions' => MAX_EXTENSIONS]);
}

json_response([
    'expires_at'  => $extended['expires_at'],
    'expires_in'  => $extended['expires_in'],
    'extensions'  => (int) $inbox['extensions'] + 1,
    'remaining'   => MAX_EXTENSIONS - ((int) $inbox['extensions'] + 1),
    'server_time' => date('Y-m-d H:i:s'),
]);
