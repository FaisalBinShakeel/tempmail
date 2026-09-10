<?php
/**
 * GET /api/messages.php?since=<last_id> — the polling hot path (FR-3.3).
 * One indexed query on (inbox_address, id); empty list is the common answer.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Message.php';

api_boot();
api_require_method('GET');
api_rate_limit('poll', 30, 60); // NFR-9

$inbox = api_require_inbox();
$since = filter_var($_GET['since'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);

$messages = Message::forInbox($inbox)->listSince((int) $since);

$out = [];
foreach ($messages as $row) {
    $out[] = [
        'id'             => (int) $row['id'],
        'sender_name'    => $row['sender_name'],
        'sender_email'   => $row['sender_email'],
        'subject'        => $row['subject'],
        'has_attachment' => (bool) $row['has_attachment'],
        'is_read'        => (bool) $row['is_read'],
        'received_at'    => $row['received_at'],
        'relative_time'  => relative_time((int) $row['age_seconds']),
    ];
}

json_response([
    'messages'    => $out,
    'server_time' => date('Y-m-d H:i:s'),
    'address'     => $inbox['address'],
    'expires_at'  => $inbox['expires_at'],
    'expires_in'  => (int) $inbox['expires_in'],   // seconds; the client counts down from this
    'extensions'  => (int) $inbox['extensions'],
]);
