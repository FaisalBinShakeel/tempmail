<?php
/**
 * Email reads and deletes, always bound to one inbox.
 *
 * A Message handle can only be built from an inbox that was already resolved
 * from the owner cookie (Message::forInbox), and every statement carries a
 * `inbox_address = ?` predicate. There is no code path that takes an address
 * or a message id from the client and reaches a row without that predicate,
 * so cross-inbox reads are structurally impossible rather than merely checked.
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Message
{
    /** How much of a body we scan when hunting for an OTP. */
    private const OTP_SCAN_BYTES = 20000;

    private function __construct(private readonly string $address)
    {
    }

    /** Build a handle from an inbox row returned by Inbox::resolveByToken(). */
    public static function forInbox(array $inbox): self
    {
        $address = $inbox['address'] ?? '';
        if (!is_string($address) || $address === '') {
            throw new InvalidArgumentException('Cannot bind messages to an unresolved inbox.');
        }
        return new self($address);
    }

    /**
     * Messages newer than $sinceId, newest first. Pass 0 for the full list.
     *
     * @return list<array<string,mixed>>
     */
    public function listSince(int $sinceId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $stmt = Database::pdo()->prepare(
            'SELECT id, sender_name, sender_email, subject, has_attachment, is_read, received_at
               FROM emails
              WHERE inbox_address = ? AND id > ?
              ORDER BY id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $this->address, PDO::PARAM_STR);
        $stmt->bindValue(2, max(0, $sinceId), PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Full message, or null when it is not this inbox's message. */
    public function get(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, sender_name, sender_email, subject, body_text, body_html,
                    raw_headers, has_attachment, is_read, received_at
               FROM emails
              WHERE id = ? AND inbox_address = ?
              LIMIT 1'
        );
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->address, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function markRead(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE emails SET is_read = 1 WHERE id = ? AND inbox_address = ?'
        );
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->address, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** FR-6.1 — delete one message. False when it is not ours. */
    public function delete(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM emails WHERE id = ? AND inbox_address = ?'
        );
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->address, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** FR-6.2 — empty this inbox. Returns how many rows went. */
    public function clearAll(): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM emails WHERE inbox_address = ?');
        $stmt->execute([$this->address]);

        return $stmt->rowCount();
    }

    public function unreadCount(): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM emails WHERE inbox_address = ? AND is_read = 0'
        );
        $stmt->execute([$this->address]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * FR-4.4 — pull a verification code out of a message.
     *
     * Two passes: a code sitting next to a word like "code"/"OTP" wins, then a
     * digit-only line on its own. Years, prices, phone numbers and digits that
     * are part of a longer run are rejected.
     */
    public static function extractOtp(?string $subject, ?string $bodyText): ?string
    {
        $subject = trim((string) $subject);
        $body    = substr((string) $bodyText, 0, self::OTP_SCAN_BYTES);

        foreach ([$subject, $body] as $haystack) {
            $code = self::keywordCode($haystack);
            if ($code !== null) {
                return $code;
            }
        }

        foreach ([$subject, $body] as $haystack) {
            $code = self::standaloneCode($haystack);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** A code introduced by, or introducing, an OTP keyword. */
    private static function keywordCode(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $keywords = 'code|otp|o\.t\.p|pin|passcode|password|token|verification|verify|confirmation';

        // "Your verification code is 123456"
        $after = '/(?:' . $keywords . ')\b[^\d\n]{0,40}?(?<![\d.,$€£#-])(\d{4,8})(?![\d.,-]?\d)/iu';
        // "123456 is your verification code"
        $before = '/(?<![\d.,$€£#-])(\d{4,8})(?![\d.,-]?\d)[^\d\n]{0,40}?\b(?:' . $keywords . ')\b/iu';

        foreach ([$after, $before] as $pattern) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                foreach ($matches[1] as $candidate) {
                    if (self::isPlausibleCode($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        // Codes printed in groups, e.g. "Your code is 743 291".
        $grouped = '/(?:' . $keywords . ')\b[^\d\n]{0,40}?(?<![\d.,-])(\d{3})[ -](\d{3})(?![\d-])/iu';
        if (preg_match($grouped, $text, $m) === 1) {
            $candidate = $m[1] . $m[2];
            if (self::isPlausibleCode($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A line that is nothing but the code. */
    private static function standaloneCode(string $text): ?string
    {
        if ($text === '' || preg_match_all('/^[\s>*]*(\d{4,8})[\s.!]*$/mu', $text, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $candidate) {
            if (self::isPlausibleCode($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Reject the usual false positives: years and dates-as-numbers. */
    private static function isPlausibleCode(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 4 || $length > 8) {
            return false;
        }

        // A bare four-digit year reads as a code far too often.
        if ($length === 4) {
            $value = (int) $digits;
            if ($value >= 1900 && $value <= 2100) {
                return false;
            }
        }

        // 8 digits shaped like 20240131 is a date stamp, not a code.
        if ($length === 8 && preg_match('/^(?:19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])$/', $digits) === 1) {
            return false;
        }

        return true;
    }
}
