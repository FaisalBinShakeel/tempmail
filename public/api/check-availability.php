<?php
/** GET /api/check-availability.php?prefix=xyz — live availability (FR-2.3). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Inbox.php';

api_boot();
api_require_method('GET');
api_rate_limit('availability', 30, 60); // NFR-8

$prefix = $_GET['prefix'] ?? '';
if (!is_string($prefix)) {
    json_error('Invalid prefix.', 400);
}

$result = Inbox::isAvailable($prefix);

json_response([
    'available' => $result['available'],
    'reason'    => $result['reason'],
    // Only echo an address the user could actually be given.
    'address'   => $result['available'] ? Inbox::addressFor(Inbox::normalizePrefix($prefix)) : null,
]);
