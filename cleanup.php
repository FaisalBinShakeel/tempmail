<?php
/**
 * Cron job (every 10 minutes): purge expired inboxes, their mail, and stale
 * rate-limit buckets. Deletes in batches so a large backlog never holds a
 * table lock long enough to stall the site.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Database.php';

const BATCH_SIZE  = 1000;
const MAX_BATCHES = 500; // safety valve: at most 500k rows per run per stage

$pdo = Database::pdo();

/** Emails whose inbox has expired or no longer exists. */
function purge_orphan_emails(PDO $pdo): int
{
    $find = $pdo->prepare(
        'SELECT e.id
           FROM emails e
           LEFT JOIN inboxes i
             ON i.address = e.inbox_address AND i.expires_at > NOW()
          WHERE i.id IS NULL
          LIMIT ?'
    );
    $find->bindValue(1, BATCH_SIZE, PDO::PARAM_INT);

    $total = 0;
    for ($batch = 0; $batch < MAX_BATCHES; $batch++) {
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

        if (count($ids) < BATCH_SIZE) {
            break;
        }
    }

    return $total;
}

function purge_in_batches(PDO $pdo, string $sql): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, BATCH_SIZE, PDO::PARAM_INT);
    $total = 0;

    for ($batch = 0; $batch < MAX_BATCHES; $batch++) {
        $stmt->execute();
        $removed = $stmt->rowCount();
        $total  += $removed;
        if ($removed < BATCH_SIZE) {
            break;
        }
    }

    return $total;
}

try {
    // Emails first: an inbox row must still exist while its mail is matched.
    $emails = purge_orphan_emails($pdo);
    $inboxes = purge_in_batches($pdo, 'DELETE FROM inboxes WHERE expires_at <= NOW() LIMIT ?');
    $limits = purge_in_batches(
        $pdo,
        'DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR) LIMIT ?'
    );

    printf(
        "[%s] cleanup: %d emails, %d inboxes, %d rate-limit rows removed%s",
        date('Y-m-d H:i:s'),
        $emails,
        $inboxes,
        $limits,
        PHP_EOL
    );
} catch (Throwable $e) {
    log_error('cleanup: ' . $e->getMessage());
    fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
