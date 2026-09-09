<?php
/**
 * Postfix pipe target: `receive.php <recipient>` with the raw message on stdin.
 *
 * Always exits 0 — a non-zero exit makes Postfix retry and eventually bounce,
 * and a disposable mail host has nothing useful to say to a sender. Problems
 * go to the log instead.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Inbox.php';

const RECEIVE_LOG = 'receive: ';

/** Log and stop, without upsetting Postfix. */
function bail(string $reason): never
{
    log_error(RECEIVE_LOG . $reason);
    exit(0);
}

/** Force a string to valid UTF-8 so MySQL never rejects it. */
function to_utf8(?string $value): string
{
    $value = (string) $value;
    if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    return is_string($converted) ? $converted : '';
}

/** Cap a stored body part; truncate rather than drop the mail. */
function cap(string $value): string
{
    if (strlen($value) <= MAX_BODY_BYTES) {
        return $value;
    }
    $truncated = mb_strcut($value, 0, MAX_BODY_BYTES - 64, 'UTF-8');
    return $truncated . "\n\n[truncated — the original message was larger than "
        . (int) (MAX_BODY_BYTES / 1024) . "KB]";
}

// ------------------------------------------------------------- recipient ----
$recipient = strtolower(trim((string) ($argv[1] ?? '')));
$recipient = trim($recipient, '<>');

if ($recipient === '' || !str_contains($recipient, '@')) {
    bail('missing or malformed recipient argument');
}

// Drop plus-addressing: someone+tag@domain belongs to someone@domain.
[$local, $domain] = explode('@', $recipient, 2);
if (str_contains($local, '+')) {
    $local = substr($local, 0, strpos($local, '+'));
}
$address = $local . '@' . $domain;

if ($domain !== strtolower(DOMAIN)) {
    bail('recipient domain not served: ' . $address);
}

if (!Inbox::isLive($address)) {
    // Expired or never existed — nothing to store, and no bounce either.
    exit(0);
}

// ----------------------------------------------------------------- parse ----
$raw = stream_get_contents(STDIN);
if (!is_string($raw) || trim($raw) === '') {
    bail('empty message body on stdin for ' . $address);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

try {
    // php-mime-mail-parser (ext-mailparse) is the real parser. The built-in
    // fallback keeps mail flowing on hosts where that extension is missing;
    // it handles the common shapes, not every exotic MIME construction.
    $mail = class_exists(\PhpMimeMailParser\Parser::class)
        ? parse_with_library($raw)
        : parse_fallback($raw);

    if ($mail['text'] === '' && $mail['html'] === '') {
        $mail['text'] = '(This message had no readable text or HTML body.)';
    }

    $stmt = Database::pdo()->prepare(
        'INSERT INTO emails
            (inbox_address, sender_name, sender_email, subject, body_text, body_html, raw_headers, has_attachment)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $address,
        mb_strcut($mail['name'], 0, 255, 'UTF-8'),
        mb_strcut($mail['email'], 0, 255, 'UTF-8'),
        $mail['subject'],
        cap($mail['text']),
        cap($mail['html']),
        mb_strcut($mail['headers'], 0, 60000, 'UTF-8'),
        $mail['has_attachment'] ? 1 : 0,
    ]);
} catch (Throwable $e) {
    bail('failed to store mail for ' . $address . ': ' . $e->getMessage());
}

exit(0);

// ------------------------------------------------------------------ parsers --

/** @return array{name:string,email:string,subject:string,text:string,html:string,headers:string,has_attachment:bool} */
function parse_with_library(string $raw): array
{
    $parser = new \PhpMimeMailParser\Parser();
    $parser->setText($raw);

    $from   = $parser->getAddresses('from');
    $sender = is_array($from) && isset($from[0]) ? $from[0] : [];

    $name  = to_utf8((string) ($sender['display'] ?? ''));
    $email = to_utf8((string) ($sender['address'] ?? ''));
    if ($name === $email) {
        $name = ''; // don't repeat the address as a display name
    }

    $attachments = $parser->getAttachments();

    return [
        'name'           => $name,
        'email'          => $email,
        'subject'        => to_utf8((string) $parser->getHeader('subject')),
        'text'           => to_utf8((string) $parser->getMessageBody('text')),
        'html'           => to_utf8((string) $parser->getMessageBody('html')),
        'headers'        => to_utf8((string) $parser->getHeadersRaw()),
        'has_attachment' => is_array($attachments) && count($attachments) > 0,
    ];
}

/** Minimal MIME reader used only when ext-mailparse is unavailable. */
function parse_fallback(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    [$headerBlock, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

    $headers = parse_headers($headerBlock);
    $result  = [
        'name'           => '',
        'email'          => '',
        'subject'        => decode_header_value($headers['subject'] ?? ''),
        'text'           => '',
        'html'           => '',
        'headers'        => to_utf8($headerBlock),
        'has_attachment' => false,
    ];

    $from = decode_header_value($headers['from'] ?? '');
    if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m) === 1) {
        $result['name']  = trim($m[1], " \t\"'");
        $result['email'] = trim($m[2]);
    } else {
        $result['email'] = trim($from);
    }

    walk_part($headers, $body, $result);

    return $result;
}

/** Header block to a lowercase-keyed map, folded lines joined. */
function parse_headers(string $block): array
{
    $headers = [];
    $current = '';

    foreach (explode("\n", $block) as $line) {
        if ($line === '') {
            continue;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && $current !== '') {
            $headers[$current] .= ' ' . trim($line);
            continue;
        }
        $split = strpos($line, ':');
        if ($split === false) {
            continue;
        }
        $current = strtolower(trim(substr($line, 0, $split)));
        $value   = trim(substr($line, $split + 1));
        $headers[$current] = isset($headers[$current]) ? $headers[$current] . ' ' . $value : $value;
    }

    return $headers;
}

function decode_header_value(string $value): string
{
    if ($value === '') {
        return '';
    }
    $decoded = @mb_decode_mimeheader($value);
    return to_utf8(is_string($decoded) && $decoded !== '' ? $decoded : $value);
}

/** A single MIME part: recurse into multiparts, collect text/html/attachments. */
function walk_part(array $headers, string $body, array &$result, int $depth = 0): void
{
    if ($depth > 8) {
        return; // don't follow pathological nesting
    }

    $contentType = strtolower($headers['content-type'] ?? 'text/plain');
    $disposition = strtolower($headers['content-disposition'] ?? '');

    if (str_starts_with($contentType, 'multipart/')) {
        $boundary = header_param($headers['content-type'] ?? '', 'boundary');
        if ($boundary === '') {
            return;
        }
        foreach (split_multipart($body, $boundary) as $part) {
            [$partHeaderBlock, $partBody] = array_pad(explode("\n\n", $part, 2), 2, '');
            walk_part(parse_headers($partHeaderBlock), $partBody, $result, $depth + 1);
        }
        return;
    }

    $isAttachment = str_contains($disposition, 'attachment')
        || header_param($disposition, 'filename') !== ''
        || header_param($headers['content-type'] ?? '', 'name') !== '';

    if ($isAttachment) {
        $result['has_attachment'] = true;
        return;
    }

    $decoded = decode_body(
        $body,
        strtolower(trim($headers['content-transfer-encoding'] ?? '')),
        header_param($headers['content-type'] ?? '', 'charset')
    );

    if (str_starts_with($contentType, 'text/html')) {
        if ($result['html'] === '') {
            $result['html'] = $decoded;
        }
        return;
    }

    if (str_starts_with($contentType, 'text/') && $result['text'] === '') {
        $result['text'] = $decoded;
    }
}

/** @return list<string> */
function split_multipart(string $body, string $boundary): array
{
    $parts = [];
    $chunks = explode('--' . $boundary, $body);
    array_shift($chunks); // preamble

    foreach ($chunks as $chunk) {
        if (str_starts_with(ltrim($chunk), '--')) {
            break; // closing boundary
        }
        $parts[] = ltrim($chunk, "\n");
    }

    return $parts;
}

function header_param(string $headerValue, string $name): string
{
    if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $headerValue, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $headerValue, $m) === 1) {
        return trim($m[1], '"\'');
    }
    return '';
}

function decode_body(string $body, string $encoding, string $charset): string
{
    if ($encoding === 'base64') {
        $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', false);
        $body = is_string($decoded) ? $decoded : $body;
    } elseif ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }

    $charset = strtoupper(trim($charset));
    if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
        if (is_string($converted) && $converted !== '') {
            $body = $converted;
        }
    }

    return to_utf8(rtrim($body, "\n"));
}
