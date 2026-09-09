<?php
/** POST /api/delete.php — delete one message from this inbox (FR-6.1). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Message.php';

api_boot();
api_require_method('POST');
api_require_csrf();
api_rate_limit('mutate', 60, 60);

$inbox = api_require_inbox();

$body = json_body();
$id   = filter_var($body['id'] ?? ($_POST['id'] ?? 0), FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);

if ($id === false || $id === 0) {
    json_error('Invalid message id.', 400);
}

if (!Message::forInbox($inbox)->delete((int) $id)) {
    // Same 403 for someone else's message and for one that never existed.
    json_error('You do not have access to that message.', 403);
}

json_response(['ok' => true]);
