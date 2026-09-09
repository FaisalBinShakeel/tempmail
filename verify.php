<?php
/**
 * Post-install check: run `php verify.php` and fix whatever prints FAIL
 * before debugging anything else.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
$warnings = 0;

/**
 * Run one check. $required false turns a failure into a warning: the app
 * still runs without it, just with a degraded path.
 */
function check(string $label, callable $test, bool $required = true): void
{
    global $failures, $warnings;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = 'error: ' . $e->getMessage();
    }

    $ok = $result === true;
    if (!$ok) {
        $required ? $failures++ : $warnings++;
    }

    printf(
        "[%s] %-38s %s%s",
        $ok ? 'PASS' : ($required ? 'FAIL' : 'WARN'),
        $label,
        $ok ? '' : (is_string($result) ? $result : 'failed'),
        PHP_EOL
    );
}

echo "TempMail installation check\n---------------------------\n";

check('PHP 8.1 or newer', static fn () => PHP_VERSION_ID >= 80100 ? true : 'found PHP ' . PHP_VERSION);

check('config.php present', static function () {
    return is_file(__DIR__ . '/config.php') ? true : 'copy config.example.php to config.php';
});

if (!is_file(__DIR__ . '/config.php')) {
    echo "\nStopping: nothing else can be checked without config.php.\n";
    exit(1);
}

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Database.php';

foreach (['pdo_mysql', 'mbstring', 'json', 'session'] as $extension) {
    check('extension ' . $extension, static fn () => extension_loaded($extension) ? true : 'not loaded');
}

check('extension mailparse (mail intake)', static function () {
    return extension_loaded('mailparse')
        ? true
        : 'not loaded — receive.php falls back to its built-in parser';
}, false);

check('composer dependencies installed', static function () {
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        return 'run "composer install"';
    }
    require_once __DIR__ . '/vendor/autoload.php';
    return class_exists(\PhpMimeMailParser\Parser::class) ? true : 'php-mime-mail-parser not found in vendor/';
}, false);

foreach ([
    'DOMAIN'                 => static fn () => is_string(DOMAIN) && DOMAIN !== '' && DOMAIN !== 'example.com',
    'INBOX_LIFETIME_MINUTES' => static fn () => INBOX_LIFETIME_MINUTES > 0,
    'MAX_EXTENSIONS'         => static fn () => MAX_EXTENSIONS >= 0,
    'EXTENSION_MINUTES'      => static fn () => EXTENSION_MINUTES > 0,
    'COOKIE_LIFETIME_DAYS'   => static fn () => COOKIE_LIFETIME_DAYS > 0,
    'MAX_BODY_BYTES'         => static fn () => MAX_BODY_BYTES > 1024,
] as $constant => $test) {
    check('config constant ' . $constant, static function () use ($constant, $test) {
        if (!defined($constant)) {
            return 'not defined in config.php';
        }
        return $test() === true ? true : 'set a real value in config.php';
    });
}

check('log directory writable', static function () {
    if (!is_dir(LOG_DIR) && !@mkdir(LOG_DIR, 0775, true)) {
        return LOG_DIR . ' does not exist and could not be created';
    }
    return is_writable(LOG_DIR) ? true : LOG_DIR . ' is not writable by ' . (get_current_user() ?: 'this user');
});

check('database connection', static function () {
    Database::pdo()->query('SELECT 1');
    return true;
});

foreach (['inboxes', 'emails', 'rate_limits'] as $table) {
    check('table ' . $table, static function () use ($table) {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false ? true : 'missing — import schema.sql';
    });
}

check('inboxes index on token', static function () {
    $stmt = Database::pdo()->query("SHOW INDEX FROM inboxes WHERE Column_name = 'token'");
    return $stmt->fetch() !== false ? true : 'missing — re-import schema.sql';
});

check('emails index on (inbox_address, id)', static function () {
    $stmt = Database::pdo()->query("SHOW INDEX FROM emails WHERE Column_name = 'inbox_address'");
    return $stmt->fetch() !== false ? true : 'missing — polling will be slow; re-import schema.sql';
});

check('write + read a row', static function () {
    $pdo = Database::pdo();
    $probe = 'verify-' . bin2hex(random_bytes(4)) . '@' . DOMAIN;
    $pdo->prepare('INSERT INTO inboxes (address, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE))')
        ->execute([$probe, str_repeat('0', 64)]);
    $stmt = $pdo->prepare('SELECT id FROM inboxes WHERE address = ?');
    $stmt->execute([$probe]);
    $found = $stmt->fetchColumn() !== false;
    $pdo->prepare('DELETE FROM inboxes WHERE address = ?')->execute([$probe]);
    return $found ? true : 'insert succeeded but the row could not be read back';
});

check('config.php outside the web root', static function () {
    return !is_file(__DIR__ . '/public/config.php') ? true : 'config.php must not live in public/';
});

echo "---------------------------\n";
if ($failures === 0) {
    printf(
        "All required checks passed.%s%s",
        $warnings > 0 ? sprintf(' %d warning(s) — see WARN lines above.', $warnings) : '',
        PHP_EOL
    );
    exit(0);
}

printf("%d check(s) failed, %d warning(s).%s", $failures, $warnings, PHP_EOL);
exit(1);
