<?php
/** GET /api/message.php?id=<id> — one full message, scoped to this inbox. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Message.php';

api_boot();
api_require_method('GET');
api_rate_limit('read', 120, 60);

$inbox = api_require_inbox();
$id    = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);

if ($id === false || $id === 0) {
    json_error('Invalid message id.', 400);
}

$messages = Message::forInbox($inbox);
$row      = $messages->get((int) $id);

if ($row === null) {
    // 403 whether the id belongs to someone else or does not exist at all,
    // so the response can never confirm another inbox's message.
    json_error('You do not have access to that message.', 403);
}

$messages->markRead((int) $id);

json_response([
    'id'             => (int) $row['id'],
    'sender_name'    => $row['sender_name'],
    'sender_email'   => $row['sender_email'],
    'subject'        => $row['subject'],
    'body_html'      => $row['body_html'],
    'body_text'      => $row['body_text'],
    'raw_headers'    => $row['raw_headers'],
    'has_attachment' => (bool) $row['has_attachment'],
    'received_at'    => $row['received_at'],
    'relative_time'  => relative_time((int) $row['age_seconds']),
    'otp'            => Message::extractOtp($row['subject'], $row['body_text']),
    // Ready-made document for the sandboxed iframe: the client only ever
    // assigns this to srcdoc, never to innerHTML.
    'iframe_doc'     => Message::iframeDocument($row['body_html'], $row['body_text']),
]);
