<?php
/**
 * Fixed-window rate limiting backed by the rate_limits table (NFR-7..NFR-11).
 *
 * The bucket key is (ip, action, window_start). The IP comes from client_ip(),
 * which only honours proxy headers from configured trusted proxies — otherwise
 * a client could pick its own bucket.
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';

final class RateLimiter
{
    private int $windowEnd = 0;

    public function __construct(private readonly string $ip)
    {
    }

    public static function forCurrentRequest(): self
    {
        return new self(client_ip());
    }

    /**
     * Count this request against the bucket.
     *
     * @return bool true when the request is within the limit.
     */
    public function check(string $action, int $maxRequests, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();
        $windowStart   = $now - ($now % $windowSeconds);
        $this->windowEnd = $windowStart + $windowSeconds;

        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (ip_address, action, window_start, count)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$this->ip, $action, date('Y-m-d H:i:s', $windowStart)]);

        $read = $pdo->prepare(
            'SELECT count FROM rate_limits
              WHERE ip_address = ? AND action = ? AND window_start = ?'
        );
        $read->execute([$this->ip, $action, date('Y-m-d H:i:s', $windowStart)]);
        $count = (int) $read->fetchColumn();

        return $count <= $maxRequests;
    }

    /** Seconds until the current window resets — for the retry message. */
    public function remainingSeconds(): int
    {
        return max(1, $this->windowEnd - time());
    }
}
