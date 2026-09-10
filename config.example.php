<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must not be readable over HTTP.
 */
declare(strict_types=1);

// ---------------------------------------------------------------- database --
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'tempmail';
const DB_USER = 'tempmail';
const DB_PASS = 'change-me';

// ------------------------------------------------------------------- mail ---
/** The domain addresses are issued on. Must be the domain Postfix accepts. */
const DOMAIN = 'example.com';

// ------------------------------------------------------------- lifetimes ----
/** Minutes a freshly created inbox stays alive. */
const INBOX_LIFETIME_MINUTES = 60;
/** How many times a user may extend one inbox. */
const MAX_EXTENSIONS = 3;
/** Minutes added per extension. */
const EXTENSION_MINUTES = 60;
/** Days the owner cookie survives, so a returning visitor keeps their inbox. */
const COOKIE_LIFETIME_DAYS = 7;

// -------------------------------------------------------------- admin ------
/**
 * Password hash for /admin. Generate it on the server and paste the result:
 *
 *   php -r 'echo password_hash("YOUR-PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
 *
 * Leave it empty to keep the admin panel closed.
 */
const ADMIN_PASSWORD_HASH = '';

// -------------------------------------------------------------- runtime -----
/** Writable directory for application logs. */
const LOG_DIR = __DIR__ . '/logs';
/** Max bytes stored per body part; longer bodies are truncated, not rejected. */
const MAX_BODY_BYTES = 524288;

/**
 * Set false only for local http:// development — cookies are Secure otherwise.
 */
const COOKIE_SECURE = true;

/**
 * Reverse proxies whose X-Forwarded-For / X-Real-IP headers may be trusted.
 * Leave empty when PHP-FPM sits directly behind nginx on the same host
 * (REMOTE_ADDR is already the real client there). Never add 0.0.0.0/0 —
 * a spoofable client IP defeats every rate limit.
 */
const TRUSTED_PROXIES = [];
