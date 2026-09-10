<?php
/**
 * Admin panel logic: authentication, health checks and statistics.
 *
 * Every health check reports a status and, when something is wrong, the exact
 * commands to fix it — with this install's real paths and domain filled in, so
 * the panel is usable without going back to the documentation.
 */
declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Cleanup.php';

final class Admin
{
    private const SESSION_KEY = 'admin_authed';

    // ------------------------------------------------------------- auth ----

    /** True once ADMIN_PASSWORD_HASH is set in config.php. */
    public static function isConfigured(): bool
    {
        return defined('ADMIN_PASSWORD_HASH')
            && is_string(ADMIN_PASSWORD_HASH)
            && strlen(ADMIN_PASSWORD_HASH) > 20;
    }

    public static function isAuthed(): bool
    {
        start_session();
        return self::isConfigured() && !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function login(string $password): bool
    {
        start_session();

        if (!self::isConfigured() || !password_verify($password, ADMIN_PASSWORD_HASH)) {
            return false;
        }

        // New session id on privilege change.
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION['admin_since']     = time();

        return true;
    }

    public static function logout(): void
    {
        start_session();
        unset($_SESSION[self::SESSION_KEY], $_SESSION['admin_since']);
        session_regenerate_id(true);
    }

    // ---------------------------------------------------------- checks -----

    /**
     * @return list<array{id:string,label:string,status:string,detail:string,fix:?string,group:string}>
     */
    public static function health(): array
    {
        $checks = [];
        $root   = dirname(__DIR__);
        $domain = defined('DOMAIN') ? (string) DOMAIN : '';
        $php    = PHP_BINARY;

        // ---- platform
        $checks[] = self::check(
            'php',
            'PHP version',
            PHP_VERSION_ID >= 80100 ? 'pass' : 'fail',
            'PHP ' . PHP_VERSION,
            PHP_VERSION_ID >= 80100 ? null : 'This app needs PHP 8.1 or newer. In aaPanel: Website → Settings → PHP version.',
            'Platform'
        );

        $missing = array_values(array_filter(
            ['pdo_mysql', 'mbstring', 'json', 'session'],
            static fn (string $ext): bool => !extension_loaded($ext)
        ));
        $checks[] = self::check(
            'extensions',
            'Required PHP extensions',
            $missing === [] ? 'pass' : 'fail',
            $missing === [] ? 'pdo_mysql, mbstring, json, session all loaded' : 'Missing: ' . implode(', ', $missing),
            $missing === [] ? null : "Install them for this PHP version.\naaPanel: Software Store → PHP → Setting → Install extensions.",
            'Platform'
        );

        $checks[] = self::check(
            'mailparse',
            'MIME parser (optional)',
            extension_loaded('mailparse') && is_file($root . '/vendor/autoload.php') ? 'pass' : 'warn',
            extension_loaded('mailparse')
                ? (is_file($root . '/vendor/autoload.php') ? 'php-mime-mail-parser in use' : 'mailparse loaded, but composer packages are not installed')
                : 'Not installed — receive.php is using its built-in parser',
            extension_loaded('mailparse') && is_file($root . '/vendor/autoload.php')
                ? null
                : "Optional. Mail already works without it.\n\n"
                    . "# aaPanel: remove proc_open + putenv from PHP → Disabled functions first\n"
                    . "cd {$root}\n"
                    . "{$php} /usr/local/bin/composer install --no-dev --optimize-autoloader",
            'Platform'
        );

        // ---- database
        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
            $dbDetail = 'Connected to ' . (defined('DB_NAME') ? DB_NAME : '?') . ' on ' . (defined('DB_HOST') ? DB_HOST : '?');
        } catch (Throwable $e) {
            $dbOk = false;
            $dbDetail = 'Cannot connect: ' . $e->getMessage();
        }
        $checks[] = self::check(
            'database',
            'Database connection',
            $dbOk ? 'pass' : 'fail',
            $dbDetail,
            $dbOk ? null : "Check DB_HOST / DB_NAME / DB_USER / DB_PASS in {$root}/config.php against the panel's Database page.",
            'Database'
        );

        if ($dbOk) {
            $missingTables = [];
            foreach (['inboxes', 'emails', 'rate_limits'] as $table) {
                if (!self::tableExists($table)) {
                    $missingTables[] = $table;
                }
            }
            $checks[] = self::check(
                'tables',
                'Core tables',
                $missingTables === [] ? 'pass' : 'fail',
                $missingTables === [] ? 'inboxes, emails, rate_limits present' : 'Missing: ' . implode(', ', $missingTables),
                $missingTables === [] ? null : "mysql -u " . (defined('DB_USER') ? DB_USER : 'DBUSER')
                    . " -p " . (defined('DB_NAME') ? DB_NAME : 'DBNAME') . " < {$root}/schema.sql",
                'Database'
            );

            $checks[] = self::check(
                'settings_table',
                'Settings table',
                Settings::tableExists() ? 'pass' : 'warn',
                Settings::tableExists()
                    ? 'Present — settings on this page are saved to the database'
                    : 'Missing. The panel is read-only for settings until you run the migration; the app keeps using config.php defaults.',
                Settings::tableExists() ? null : "mysql -u " . (defined('DB_USER') ? DB_USER : 'DBUSER')
                    . " -p " . (defined('DB_NAME') ? DB_NAME : 'DBNAME') . " < {$root}/migrations/001_settings.sql",
                'Database'
            );
        }

        // ---- configuration
        $domainOk = $domain !== '' && $domain !== 'example.com';
        $checks[] = self::check(
            'domain',
            'Mail domain',
            $domainOk ? 'pass' : 'fail',
            $domainOk ? $domain : 'DOMAIN is still the example value',
            $domainOk ? null : "Set DOMAIN in {$root}/config.php to the domain your MX record points at.",
            'Configuration'
        );

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $cookieSecure = defined('COOKIE_SECURE') && COOKIE_SECURE;
        $checks[] = self::check(
            'https',
            'HTTPS and cookie flags',
            $https && $cookieSecure ? 'pass' : ($https || $cookieSecure ? 'warn' : 'fail'),
            ($https ? 'Served over HTTPS' : 'This page is not on HTTPS')
                . ' · COOKIE_SECURE = ' . ($cookieSecure ? 'true' : 'false'),
            $https && $cookieSecure
                ? null
                : (!$https
                    ? "Issue a certificate (aaPanel: Website → SSL → Let's Encrypt) and turn on Force HTTPS."
                    : "Set COOKIE_SECURE = true in {$root}/config.php now that HTTPS works."),
            'Configuration'
        );

        $logDir = defined('LOG_DIR') ? LOG_DIR : $root . '/logs';
        $logOk  = is_dir($logDir) && is_writable($logDir);
        $checks[] = self::check(
            'logs',
            'Log directory writable',
            $logOk ? 'pass' : 'fail',
            $logDir . ($logOk ? ' is writable' : ' is not writable by ' . (get_current_user() ?: 'the web user')),
            $logOk ? null : "install -d -o www -g www -m 2770 {$logDir}\n"
                . "install -o www -g www -m 664 /dev/null {$logDir}/app.log",
            'Configuration'
        );

        // ---- mail delivery
        $checks[] = self::dnsCheck($domain);
        $checks[] = self::smtpCheck();
        $checks[] = self::intakeCheck($domain, $root, $php);

        // ---- housekeeping
        $checks[] = self::cronCheck($root, $php);
        $checks[] = self::timezoneCheck();

        return array_values(array_filter($checks));
    }

    /** Does the MX for our domain resolve to this server? */
    private static function dnsCheck(string $domain): array
    {
        if ($domain === '' || $domain === 'example.com') {
            return self::check('dns', 'MX record', 'warn', 'Skipped — set DOMAIN first.', null, 'Mail delivery');
        }

        $serverIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $records  = @dns_get_record($domain, DNS_MX);

        if (!is_array($records) || $records === []) {
            return self::check(
                'dns',
                'MX record',
                'fail',
                "No MX record found for {$domain}. No mail server will ever deliver here.",
                "Add at your DNS provider:\n\n"
                    . "  Type: MX   Name: {$domain}   Value: {$domain}   Priority: 10\n\n"
                    . "Write the value as the full hostname. On Cloudflare a bare word like \"mail\"\n"
                    . "is stored as \"mail.\" (a top-level domain) and delivers nowhere.",
                'Mail delivery'
            );
        }

        usort($records, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $target = (string) ($records[0]['target'] ?? '');
        $ips    = $target === '' ? [] : array_filter([gethostbyname($target)], static fn ($ip) => $ip !== $target);
        $ipList = $ips === [] ? 'does not resolve' : implode(', ', $ips);

        $cloudflare = false;
        foreach ($ips as $ip) {
            if (self::looksLikeCloudflare((string) $ip)) {
                $cloudflare = true;
            }
        }

        if ($cloudflare) {
            return self::check(
                'dns',
                'MX record',
                'fail',
                "MX {$target} resolves to {$ipList} — that is Cloudflare's proxy, which does not carry SMTP.",
                "In Cloudflare → DNS, open the A record for this hostname and set\n"
                    . "Proxy status to \"DNS only\" (grey cloud), pointing at this server:\n\n"
                    . "  Type: A    Name: {$domain}    Content: " . ($serverIp !== '' ? $serverIp : 'your server IP') . "    Proxy: DNS only\n"
                    . "  Type: MX   Name: {$domain}    Value: {$domain}   Priority: 10\n\n"
                    . "Also delete any AAAA record for it that points at Cloudflare.",
                'Mail delivery'
            );
        }

        $matches = $serverIp !== '' && in_array($serverIp, array_map('strval', $ips), true);

        return self::check(
            'dns',
            'MX record',
            $ips === [] ? 'fail' : ($matches ? 'pass' : 'warn'),
            "MX {$target} → {$ipList}" . ($serverIp !== '' ? " · this server is {$serverIp}" : ''),
            $ips === []
                ? (str_contains(rtrim($target, '.'), '.')
                    ? "The MX target {$target} does not resolve. Add an A record for it:\n\n"
                        . "  Type: A   Name: {$target}   Content: "
                        . ($serverIp !== '' ? $serverIp : 'your server IP') . "   Proxy: DNS only"
                    : "The MX value was saved as the bare label \"{$target}\", which is not a real\n"
                        . "hostname — Cloudflare and some other panels treat a bare word as absolute,\n"
                        . "so \"mail\" becomes \"mail.\" and delivers nowhere.\n\n"
                        . "Edit the MX record and write the full hostname:\n\n"
                        . "  Type: MX   Name: {$domain}   Value: {$domain}   Priority: 10\n\n"
                        . "Then make sure that hostname has an A record pointing here:\n\n"
                        . "  Type: A    Name: {$domain}   Content: "
                        . ($serverIp !== '' ? $serverIp : 'your server IP') . "   Proxy: DNS only (grey cloud)")
                : ($matches ? null : "The MX points somewhere other than this server's own address.\n"
                    . "That is fine behind NAT or a floating IP — otherwise point the A record at "
                    . ($serverIp !== '' ? $serverIp : 'this server') . '.'),
            'Mail delivery'
        );
    }

    /** Is a mail server listening on this host? */
    private static function smtpCheck(): array
    {
        $errno = 0;
        $error = '';
        $socket = @fsockopen('127.0.0.1', 25, $errno, $error, 3);

        if ($socket === false) {
            return self::check(
                'smtp',
                'Mail server on port 25',
                'fail',
                'Nothing is listening on 127.0.0.1:25 — Postfix is not running.',
                "apt -y install postfix          # CentOS: yum -y install postfix\n"
                    . "systemctl enable --now postfix\n"
                    . "systemctl status postfix",
                'Mail delivery'
            );
        }

        $banner = trim((string) fgets($socket, 256));
        fclose($socket);

        return self::check(
            'smtp',
            'Mail server on port 25',
            'pass',
            $banner !== '' ? $banner : 'Something is listening on port 25',
            null,
            'Mail delivery'
        );
    }

    /** Has any mail ever actually arrived? */
    private static function intakeCheck(string $domain, string $root, string $php): array
    {
        try {
            $row = Database::pdo()->query(
                'SELECT COUNT(*) AS total,
                        MAX(TIMESTAMPDIFF(SECOND, received_at, NOW())) AS oldest,
                        MIN(TIMESTAMPDIFF(SECOND, received_at, NOW())) AS newest
                   FROM emails'
            )->fetch();
        } catch (Throwable $e) {
            return self::check('intake', 'Mail intake', 'warn', 'Could not query the emails table.', null, 'Mail delivery');
        }

        $total = (int) ($row['total'] ?? 0);
        if ($total > 0) {
            return self::check(
                'intake',
                'Mail intake',
                'pass',
                $total . ' message(s) stored · last one ' . relative_time((int) ($row['newest'] ?? 0)),
                null,
                'Mail delivery'
            );
        }

        $pipeUser = 'tempmail';

        return self::check(
            'intake',
            'Mail intake',
            'warn',
            'No mail has been received yet. If you have already sent a test, the pipe is not working.',
            "1. Check Postfix accepted and piped it:\n"
                . "   tail -50 /var/log/mail.log      # CentOS: /var/log/maillog\n\n"
                . "2. Check the app's own log:\n"
                . "   tail -50 {$root}/logs/app.log\n\n"
                . "3. Confirm the routing:\n"
                . "   postconf -n | grep -E 'mydestination|virtual_(mailbox|transport)'\n"
                . "   postconf -M | grep tempmail\n\n"
                . "   mydestination must NOT contain {$domain}\n"
                . "   virtual_mailbox_domains = {$domain}\n"
                . "   virtual_transport = tempmail\n\n"
                . "4. Try the pipe by hand:\n"
                . "   printf 'From: t <t@x.test>\\nSubject: hi\\n\\nbody\\n' \\\n"
                . "     | su -s /bin/sh {$pipeUser} -c '{$php} {$root}/receive.php YOUR-ADDRESS@{$domain}'",
            'Mail delivery'
        );
    }

    /** Is the cleanup cron actually running? */
    private static function cronCheck(string $root, string $php): array
    {
        $last = Settings::raw('cleanup_last_run');
        $fix  = "Add a cron job that runs every 10 minutes:\n\n"
            . "  {$php} {$root}/cleanup.php\n\n"
            . "aaPanel: Cron → Add task → Type \"Shell Script\", Period: every 10 minutes.\n"
            . "Plain server: */10 * * * * www-data {$php} {$root}/cleanup.php";

        if ($last === null) {
            return self::check(
                'cron',
                'Cleanup cron',
                Settings::tableExists() ? 'warn' : 'warn',
                Settings::tableExists()
                    ? 'Has never run. Expired inboxes and their mail are piling up.'
                    : 'Cannot tell — the settings table is missing, so runs are not recorded.',
                $fix,
                'Housekeeping'
            );
        }

        $age = time() - (int) strtotime($last . ' UTC');

        return self::check(
            'cron',
            'Cleanup cron',
            $age < 3600 ? 'pass' : 'warn',
            'Last run ' . relative_time($age) . ($age < 3600 ? '' : ' — expected every 10 minutes'),
            $age < 3600 ? null : $fix,
            'Housekeeping'
        );
    }

    /** PHP and MySQL clocks, for information — the app no longer compares them. */
    private static function timezoneCheck(): array
    {
        try {
            $dbNow = (string) Database::pdo()->query('SELECT NOW()')->fetchColumn();
        } catch (Throwable $e) {
            return self::check('timezone', 'Clocks', 'warn', 'Could not read the database time.', null, 'Housekeeping');
        }

        $phpNow = date('Y-m-d H:i:s');
        $drift  = abs(strtotime($dbNow) - strtotime($phpNow));

        return self::check(
            'timezone',
            'Clocks',
            'pass',
            "PHP {$phpNow} (" . date_default_timezone_get() . ") · MySQL {$dbNow}"
                . ($drift > 60 ? ' — different timezones, which is fine: durations are computed by MySQL' : ''),
            null,
            'Housekeeping'
        );
    }

    // ------------------------------------------------------------ stats ----

    public static function stats(): array
    {
        $pdo = Database::pdo();

        $one = static function (string $sql) use ($pdo) {
            try {
                return $pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                return null;
            }
        };

        return [
            'inboxes_live'    => (int) $one('SELECT COUNT(*) FROM inboxes WHERE expires_at > NOW()'),
            'inboxes_total'   => (int) $one('SELECT COUNT(*) FROM inboxes'),
            'inboxes_custom'  => (int) $one('SELECT COUNT(*) FROM inboxes WHERE is_custom = 1 AND expires_at > NOW()'),
            'emails_total'    => (int) $one('SELECT COUNT(*) FROM emails'),
            'emails_24h'      => (int) $one('SELECT COUNT(*) FROM emails WHERE received_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'emails_unread'   => (int) $one('SELECT COUNT(*) FROM emails WHERE is_read = 0'),
            'created_24h'     => (int) $one('SELECT COUNT(*) FROM inboxes WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'rate_rows'       => (int) $one('SELECT COUNT(*) FROM rate_limits'),
            'last_email_age'  => $one('SELECT TIMESTAMPDIFF(SECOND, MAX(received_at), NOW()) FROM emails'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recentInboxes(int $limit = 12): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT i.address, i.is_custom, i.extensions,
                    TIMESTAMPDIFF(SECOND, i.created_at, NOW()) AS age_seconds,
                    TIMESTAMPDIFF(SECOND, NOW(), i.expires_at) AS expires_in,
                    (SELECT COUNT(*) FROM emails e WHERE e.inbox_address = i.address) AS email_count
               FROM inboxes i
              ORDER BY i.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function topSenders(int $limit = 8): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT sender_email, COUNT(*) AS hits
               FROM emails
              WHERE received_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND sender_email <> \'\'
              GROUP BY sender_email
              ORDER BY hits DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min($limit, 50)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------- helpers ---

    private static function check(
        string $id,
        string $label,
        string $status,
        string $detail,
        ?string $fix,
        string $group
    ): array {
        return compact('id', 'label', 'status', 'detail', 'fix', 'group');
    }

    private static function tableExists(string $table): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    /** Cloudflare's published proxy ranges, enough of them to catch the common case. */
    private static function looksLikeCloudflare(string $ip): bool
    {
        $prefixes = [
            '104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.', '104.22.',
            '104.23.', '104.24.', '104.25.', '104.26.', '104.27.', '104.28.',
            '172.64.', '172.65.', '172.66.', '172.67.', '172.68.', '172.69.', '172.70.', '172.71.',
            '162.158.', '162.159.', '198.41.', '188.114.', '190.93.', '197.234.', '141.101.',
            '108.162.', '173.245.', '103.21.244.', '103.22.200.', '103.31.4.', '2606:4700',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
