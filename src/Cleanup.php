<?php
/**
 * The purge logic behind cleanup.php, in a class so the admin panel can run
 * the same code on demand without shelling out.
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';

final class Cleanup
{
    private const BATCH_SIZE  = 1000;
    private const MAX_BATCHES = 500; // at most 500k rows per stage per run

    /**
     * @return array{emails:int,inboxes:int,rate_limits:int}
     */
    public static function run(): array
    {
        $pdo = Database::pdo();

        // Emails first: an inbox row must still exist while its mail is matched.
        $result = [
            'emails'      => self::purgeOrphanEmails($pdo),
            'inboxes'     => self::purgeInBatches($pdo, 'DELETE FROM inboxes WHERE expires_at <= NOW() LIMIT ?'),
            'rate_limits' => self::purgeInBatches(
                $pdo,
                'DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR) LIMIT ?'
            ),
        ];

        Settings::stamp('cleanup_last_run', gmdate('Y-m-d H:i:s'));
        Settings::stamp('cleanup_last_result', json_encode($result) ?: '{}');

        return $result;
    }

    /** Wipe every rate-limit bucket — the "I am testing and hit 429" button. */
    public static function clearRateLimits(): int
    {
        $stmt = Database::pdo()->query('DELETE FROM rate_limits');
        return $stmt === false ? 0 : $stmt->rowCount();
    }

    private static function purgeOrphanEmails(PDO $pdo): int
    {
        $find = $pdo->prepare(
            'SELECT e.id
               FROM emails e
               LEFT JOIN inboxes i
                 ON i.address = e.inbox_address AND i.expires_at > NOW()
              WHERE i.id IS NULL
              LIMIT ?'
        );
        $find->bindValue(1, self::BATCH_SIZE, PDO::PARAM_INT);

        $total = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $find->execute();
            $ids = $find->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                break;
            }

            // The only SQL built at runtime: a row of '?' marks sized to the
            // batch. No value is interpolated — every id is bound below.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $delete = $pdo->prepare('DELETE FROM emails WHERE id IN (' . $placeholders . ')');
            $delete->execute($ids);
            $total += $delete->rowCount();

            if (count($ids) < self::BATCH_SIZE) {
                break;
            }
        }

        return $total;
    }

    private static function purgeInBatches(PDO $pdo, string $sql): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, self::BATCH_SIZE, PDO::PARAM_INT);
        $total = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $stmt->execute();
            $removed = $stmt->rowCount();
            $total  += $removed;
            if ($removed < self::BATCH_SIZE) {
                break;
            }
        }

        return $total;
    }
}
