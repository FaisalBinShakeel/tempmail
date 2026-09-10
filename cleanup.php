<?php
/**
 * Cron job (every 10 minutes): purge expired inboxes, their mail, and stale
 * rate-limit buckets. The work itself lives in src/Cleanup.php so the admin
 * panel can trigger the same code.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Cleanup.php';

try {
    $removed = Cleanup::run();

    printf(
        "[%s] cleanup: %d emails, %d inboxes, %d rate-limit rows removed%s",
        date('Y-m-d H:i:s'),
        $removed['emails'],
        $removed['inboxes'],
        $removed['rate_limits'],
        PHP_EOL
    );
} catch (Throwable $e) {
    log_error('cleanup: ' . $e->getMessage());
    fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
