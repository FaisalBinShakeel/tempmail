<?php
/** POST /api/clear.php — empty this inbox (FR-6.2). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Message.php';

api_boot();
api_require_method('POST');
api_require_csrf();
api_rate_limit('mutate', 60, 60);

$inbox   = api_require_inbox();
$deleted = Message::forInbox($inbox)->clearAll();

json_response(['ok' => true, 'deleted' => $deleted]);
