<?php
/**
 * Inbox lifecycle: create, resolve by owner token, extend.
 *
 * An inbox is only ever reachable through the 64-char owner token stored in
 * the visitor's cookie. No method here accepts an address as a way in.
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';

final class Inbox
{
    /** FR-2.4 — prefixes nobody may claim. */
    public const RESERVED = [
        'admin', 'postmaster', 'abuse', 'root', 'support', 'noreply', 'no-reply',
        'webmaster', 'mail', 'info', 'security', 'billing', 'hostmaster',
    ];

    private const RANDOM_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const CREATE_ATTEMPTS = 8;

    /**
     * FR-1.1 / FR-1.3 — a fresh inbox on a unique random local part.
     *
     * @return array{id:int,address:string,token:string,is_custom:int,extensions:int,expires_at:string}
     */
    public static function createRandom(): array
    {
        for ($attempt = 0; $attempt < self::CREATE_ATTEMPTS; $attempt++) {
            $local = self::randomLocalPart();
            $inbox = self::insert($local, false);
            if ($inbox !== null) {
                return $inbox;
            }
        }
        throw new RuntimeException('Could not allocate a unique address.');
    }

    /**
     * FR-2.x — an inbox on a user-chosen prefix.
     *
     * @throws InvalidArgumentException with a message safe to show the user.
     */
    public static function createCustom(string $prefix): array
    {
        $prefix = self::normalizePrefix($prefix);

        $problem = self::validationError($prefix);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        $inbox = self::insert($prefix, true);
        if ($inbox === null) {
            throw new InvalidArgumentException('That address is already taken.');
        }
        return $inbox;
    }

    /** The active, non-expired inbox owned by this token, or null. */
    public static function resolveByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, address, token, is_custom, extensions, created_at, expires_at
               FROM inboxes
              WHERE token = ? AND expires_at > NOW()
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * FR-5.2 — add one extension. Refuses past MAX_EXTENSIONS.
     *
     * @return string the new expiry as 'Y-m-d H:i:s'
     * @throws RuntimeException when the ceiling is reached or the inbox is gone.
     */
    public static function extend(int $inboxId): string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'UPDATE inboxes
                SET expires_at = DATE_ADD(expires_at, INTERVAL ? MINUTE),
                    extensions = extensions + 1
              WHERE id = ? AND extensions < ? AND expires_at > NOW()'
        );
        $stmt->execute([EXTENSION_MINUTES, $inboxId, MAX_EXTENSIONS]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('This inbox cannot be extended any further.');
        }

        $read = $pdo->prepare('SELECT expires_at FROM inboxes WHERE id = ?');
        $read->execute([$inboxId]);
        $expiry = $read->fetchColumn();

        return is_string($expiry) ? $expiry : '';
    }

    /**
     * FR-2.3 — availability for the live check.
     *
     * @return array{available:bool,reason:?string}
     */
    public static function isAvailable(string $prefix): array
    {
        $prefix = self::normalizePrefix($prefix);

        $problem = self::validationError($prefix);
        if ($problem !== null) {
            return ['available' => false, 'reason' => $problem];
        }

        return ['available' => true, 'reason' => null];
    }

    /** Validation message for a prefix, or null when it is usable. */
    public static function validationError(string $prefix): ?string
    {
        $length = strlen($prefix);
        if ($length < 3 || $length > 30) {
            return 'Use between 3 and 30 characters.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', $prefix) !== 1) {
            return 'Use letters, numbers, dot, underscore and dash only, starting and ending with a letter or number.';
        }
        if (in_array($prefix, self::RESERVED, true)) {
            return 'That address is reserved.';
        }
        if (self::isTaken($prefix)) {
            return 'That address is already taken.';
        }
        return null;
    }

    /** Lowercase and trim, so comparisons are case-insensitive (FR-2.2). */
    public static function normalizePrefix(string $prefix): string
    {
        return strtolower(trim($prefix));
    }

    public static function addressFor(string $localPart): string
    {
        return $localPart . '@' . DOMAIN;
    }

    /**
     * True when this full address has a live inbox.
     *
     * For mail intake only (receive.php): it answers yes/no about an address
     * and hands back nothing an inbox could be accessed with, so it is not a
     * way around token-based authorization.
     */
    public static function isLive(string $address): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM inboxes WHERE address = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([strtolower(trim($address))]);

        return $stmt->fetchColumn() !== false;
    }

    /** True when a live (non-expired) inbox already holds this prefix. */
    private static function isTaken(string $prefix): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM inboxes WHERE address = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([self::addressFor($prefix)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Insert an inbox, reclaiming the address if an expired row still holds it.
     * Returns null when the address is live and taken (or lost a race).
     */
    private static function insert(string $localPart, bool $isCustom): ?array
    {
        $pdo     = Database::pdo();
        $address = self::addressFor($localPart);
        $token   = random_token();

        // Reclaim an expired row so the UNIQUE index does not block a free
        // address; cleanup.php would have done this eventually anyway.
        $reclaim = $pdo->prepare('DELETE FROM inboxes WHERE address = ? AND expires_at <= NOW()');
        $reclaim->execute([$address]);
        if ($reclaim->rowCount() > 0) {
            $orphans = $pdo->prepare('DELETE FROM emails WHERE inbox_address = ?');
            $orphans->execute([$address]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inboxes (address, token, is_custom, extensions, expires_at)
             VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );

        try {
            $stmt->execute([$address, $token, $isCustom ? 1 : 0, INBOX_LIFETIME_MINUTES]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // duplicate address — taken meanwhile
                return null;
            }
            throw $e;
        }

        $id = (int) $pdo->lastInsertId();

        $read = $pdo->prepare(
            'SELECT id, address, token, is_custom, extensions, created_at, expires_at
               FROM inboxes WHERE id = ?'
        );
        $read->execute([$id]);
        $row = $read->fetch();

        return $row === false ? null : $row;
    }

    private static function randomLocalPart(): string
    {
        $length   = random_int(8, 10);
        $alphabet = self::RANDOM_ALPHABET;
        $max      = strlen($alphabet) - 1;

        $local = '';
        for ($i = 0; $i < $length; $i++) {
            $local .= $alphabet[random_int(0, $max)];
        }
        return $local;
    }
}
