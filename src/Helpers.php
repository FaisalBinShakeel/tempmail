<?php
/**
 * Small, dependency-free helpers shared by every entry point.
 * Loading this file also loads config.php.
 */
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("config.php is missing. Copy config.example.php to config.php and fill it in.\n");
}
require_once $configPath;

/** Escape a value for HTML output. The only escaping helper in this codebase. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and stop. Always JSON, never HTML — even on error. */
function json_response(array $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Send a JSON error and stop. Never leaks SQL, file paths or stack traces. */
function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(['error' => $message] + $extra, $status);
}

/**
 * The client's IP address.
 *
 * Proxy headers are honoured only when the immediate peer is a configured
 * trusted proxy, and then only the right-most forwarded entry is used — the
 * one our own proxy appended. Anything else would let a client set its own
 * rate-limit bucket by sending a header.
 */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!in_array($remote, TRUSTED_PROXIES, true)) {
        return $remote;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));
        $candidate = end($parts);
        if ($candidate !== false && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $real = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
        return $real;
    }

    return $remote;
}

/**
 * "just now", "2 min ago", "3 hours ago" from an age in seconds.
 *
 * Takes seconds rather than a timestamp on purpose: the age is computed by
 * MySQL, so a server whose PHP timezone differs from the database's cannot
 * turn a fresh email into "5 hours ago".
 */
function relative_time(int $ageSeconds): string
{
    $seconds = max(0, $ageSeconds);

    if ($seconds < 45) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return (int) round($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        $hours = (int) floor($seconds / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int) floor($seconds / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

/**
 * URL for a file under public/assets, with a cache-busting version query.
 *
 * The version is the file's own last-modified time, so it changes exactly
 * when the file changes — no manual step, no build tool. This is what makes
 * a plain `git pull` show up immediately: the browser and any CDN in front
 * (Cloudflare included) treat a different query string as a different URL,
 * so the old cached copy is never reused for the new file. $prefix lets a
 * page under a subdirectory (admin/index.php) point at "../assets/..." while
 * everything else uses the default "assets/...".
 */
function asset_url(string $relativePath, string $prefix = 'assets/'): string
{
    $file = dirname(__DIR__) . '/public/assets/' . ltrim($relativePath, '/');
    $version = is_file($file) ? (string) filemtime($file) : (string) time();

    return $prefix . $relativePath . '?v=' . $version;
}

/** A 64-character cryptographically random hex token. */
function random_token(): string
{
    return bin2hex(random_bytes(32));
}

/** Append a line to the application log; never fatal if logging fails. */
function log_error(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0775, true);
    }
    @file_put_contents(LOG_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/** Start the PHP session with hardened cookie parameters (idempotent). */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Read/write the long-lived owner cookie that identifies an inbox. */
function owner_token(): ?string
{
    $token = $_COOKIE['tempmail_token'] ?? '';
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : null;
}

function set_owner_token(string $token): void
{
    if (!headers_sent()) {
        setcookie('tempmail_token', $token, [
            'expires'  => time() + COOKIE_LIFETIME_DAYS * 86400,
            'path'     => '/',
            'secure'   => COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE['tempmail_token'] = $token;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['tempmail_token'] = $token;
    }
}

/** Decode a JSON request body into an array; [] when absent or malformed. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ------------------------------------------------------------- API plumbing --
// Shared request handling for public/api/*.php. Every endpoint boots the same
// way so error handling, headers and authorization can't drift apart.

/**
 * Prepare a JSON endpoint: JSON headers up front, errors hidden from the
 * client, and any uncaught error turned into a generic 500 JSON body.
 */
function api_boot(): void
{
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');

    start_session();

    set_exception_handler(static function (Throwable $e): void {
        log_error(sprintf(
            '%s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
    });

    register_shutdown_function(static function (): void {
        $fatal = error_get_last();
        if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_error(sprintf('Fatal: %s in %s:%d', $fatal['message'], $fatal['file'], $fatal['line']));
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo json_encode(['error' => 'Something went wrong. Please try again.']);
        }
    });
}

/** Reject anything but the expected HTTP method. */
function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_error('Method not allowed.', 405);
    }
}

/** NFR-3 — every state-changing request carries a valid session CSRF token. */
function api_require_csrf(): void
{
    require_once __DIR__ . '/Csrf.php';
    if (!Csrf::validate(Csrf::fromRequest())) {
        json_error('Your session expired. Reload the page and try again.', 403);
    }
}

/**
 * NFR-4 — the inbox for this request, resolved only from the owner cookie.
 * The client never says which inbox it wants.
 */
function api_require_inbox(): array
{
    require_once __DIR__ . '/Inbox.php';

    $token = owner_token();
    $inbox = $token === null ? null : Inbox::resolveByToken($token);

    if ($inbox === null) {
        json_error('This inbox has expired. Generate a new address.', 403, ['expired' => true]);
    }

    return $inbox;
}

/**
 * The configured ceiling for one of the named limits, so the admin panel can
 * raise it without a code change.
 */
function rate_limit_for(string $action): array
{
    require_once __DIR__ . '/Settings.php';

    switch ($action) {
        case 'generate':
            return [Settings::int('rate_generate_per_hour'), 3600];
        case 'availability':
            return [Settings::int('rate_availability_per_minute'), 60];
        case 'poll':
            return [Settings::int('rate_poll_per_minute'), 60];
        default:
            throw new InvalidArgumentException('Unknown rate limit: ' . $action);
    }
}

/** NFR-10 — enforce a bucket, or answer 429 with a friendly retry hint. */
function api_rate_limit(string $action, int $maxRequests, int $windowSeconds): void
{
    require_once __DIR__ . '/RateLimiter.php';

    $limiter = RateLimiter::forCurrentRequest();
    if (!$limiter->check($action, $maxRequests, $windowSeconds)) {
        $retry = $limiter->remainingSeconds();
        header('Retry-After: ' . $retry);
        json_error(
            'Too many requests. Try again in ' . ceil($retry / 60) . ' minute(s).',
            429,
            ['retry_after' => $retry]
        );
    }
}
