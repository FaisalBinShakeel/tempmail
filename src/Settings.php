<?php
/**
 * Runtime settings: the values an operator can change from the admin panel
 * without editing files.
 *
 * config.php still holds everything that must not be web-editable — database
 * credentials, the mail domain, the admin password hash — and supplies the
 * default for every tunable below. A missing settings table is not an error:
 * the defaults simply stand, so an install that has not run the migration yet
 * keeps working exactly as before.
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Settings
{
    private static ?array $cache = null;
    private static ?bool $tableExists = null;

    /**
     * Every editable setting: type, bounds, and the text the admin panel shows.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function spec(): array
    {
        return [
            'site_name' => [
                'type'    => 'string',
                'default' => 'TempMail',
                'max'     => 40,
                'label'   => 'Site name',
                'help'    => 'Shown in the header and the browser tab.',
                'group'   => 'General',
            ],
            'inbox_lifetime_minutes' => [
                'type'    => 'int',
                'default' => defined('INBOX_LIFETIME_MINUTES') ? INBOX_LIFETIME_MINUTES : 60,
                'min'     => 5,
                'max'     => 1440,
                'label'   => 'Inbox lifetime (minutes)',
                'help'    => 'How long a new address lives before it expires.',
                'group'   => 'Inbox lifetime',
            ],
            'extension_minutes' => [
                'type'    => 'int',
                'default' => defined('EXTENSION_MINUTES') ? EXTENSION_MINUTES : 60,
                'min'     => 5,
                'max'     => 1440,
                'label'   => 'Minutes added per extension',
                'help'    => 'What the "+1 hour" button adds.',
                'group'   => 'Inbox lifetime',
            ],
            'max_extensions' => [
                'type'    => 'int',
                'default' => defined('MAX_EXTENSIONS') ? MAX_EXTENSIONS : 3,
                'min'     => 0,
                'max'     => 24,
                'label'   => 'Extensions allowed per inbox',
                'help'    => 'Lifetime plus this many extensions is the ceiling. 0 disables extending.',
                'group'   => 'Inbox lifetime',
            ],
            'rate_generate_per_hour' => [
                'type'    => 'int',
                'default' => 10,
                'min'     => 1,
                'max'     => 1000,
                'label'   => 'New addresses per IP per hour',
                'help'    => 'Counts random and custom addresses together. Raise it if real visitors hit the limit.',
                'group'   => 'Rate limits',
            ],
            'rate_availability_per_minute' => [
                'type'    => 'int',
                'default' => 30,
                'min'     => 1,
                'max'     => 600,
                'label'   => 'Availability checks per IP per minute',
                'help'    => 'The live check while someone types a custom address.',
                'group'   => 'Rate limits',
            ],
            'rate_poll_per_minute' => [
                'type'    => 'int',
                'default' => 30,
                'min'     => 6,
                'max'     => 600,
                'label'   => 'Inbox polls per IP per minute',
                'help'    => 'Each open tab polls 12 times a minute. Keep this above 12 per tab you expect.',
                'group'   => 'Rate limits',
            ],
        ];
    }

    /** All settings, defaults merged with whatever the database holds. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];
        foreach (self::spec() as $key => $meta) {
            $values[$key] = $meta['default'];
        }

        foreach (self::stored() as $key => $raw) {
            if (!isset($values[$key])) {
                continue; // unknown key left over from an older version
            }
            $values[$key] = self::spec()[$key]['type'] === 'int' ? (int) $raw : (string) $raw;
        }

        self::$cache = $values;
        return $values;
    }

    /** @return string|int */
    public static function get(string $key)
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            throw new InvalidArgumentException('Unknown setting: ' . $key);
        }
        return $all[$key];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /**
     * Validate and save. Returns per-field error messages; nothing is written
     * unless every field is valid.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function put(array $input): array
    {
        $spec   = self::spec();
        $errors = [];
        $clean  = [];

        foreach ($spec as $key => $meta) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = is_string($input[$key]) ? trim($input[$key]) : $input[$key];

            if ($meta['type'] === 'int') {
                if (!is_numeric($raw)) {
                    $errors[$key] = 'Enter a number.';
                    continue;
                }
                $value = (int) $raw;
                if ($value < $meta['min'] || $value > $meta['max']) {
                    $errors[$key] = sprintf('Use a value between %d and %d.', $meta['min'], $meta['max']);
                    continue;
                }
                $clean[$key] = (string) $value;
                continue;
            }

            $value = (string) $raw;
            if ($value === '') {
                $errors[$key] = 'This cannot be empty.';
                continue;
            }
            if (mb_strlen($value) > $meta['max']) {
                $errors[$key] = sprintf('Keep it under %d characters.', $meta['max']);
                continue;
            }
            $clean[$key] = $value;
        }

        if ($errors !== [] || $clean === []) {
            return $errors;
        }

        if (!self::tableExists()) {
            return ['_table' => 'The settings table is missing — run the migration shown on the dashboard.'];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($clean as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        self::$cache = null;
        return [];
    }

    /** Used by cleanup.php and the dashboard to record/report the last run. */
    public static function stamp(string $key, string $value): void
    {
        if (!self::tableExists()) {
            return;
        }
        Database::pdo()
            ->prepare('INSERT INTO settings (name, value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$key, $value]);
        self::$cache = null;
    }

    public static function raw(string $key): ?string
    {
        $stored = self::stored();
        return isset($stored[$key]) ? (string) $stored[$key] : null;
    }

    public static function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute(['settings']);
            self::$tableExists = $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            self::$tableExists = false;
        }
        return self::$tableExists;
    }

    /** @return array<string,string> */
    private static function stored(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        try {
            $rows = Database::pdo()->query('SELECT name, value FROM settings')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) $row['value'];
        }
        return $out;
    }
}
