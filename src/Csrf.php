<?php
/**
 * Session-bound CSRF token. One token per session, compared in constant time.
 */
declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';

final class Csrf
{
    private const KEY = 'csrf_token';

    /** The current session's token, created on first use. */
    public static function token(): string
    {
        start_session();
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = random_token();
        }
        return $_SESSION[self::KEY];
    }

    /** True when the supplied token matches this session's token. */
    public static function validate(?string $supplied): bool
    {
        start_session();
        $expected = $_SESSION[self::KEY] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($supplied) || $supplied === '') {
            return false;
        }
        return hash_equals($expected, $supplied);
    }

    /** The token sent with the current request, from header or form field. */
    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $field = $_POST['csrf_token'] ?? null;
        return is_string($field) && $field !== '' ? $field : null;
    }
}
