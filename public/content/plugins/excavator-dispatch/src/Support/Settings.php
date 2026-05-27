<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Support;

final class Settings
{
    private const OPTION = 'excdis_settings';

    private const SECRET_KEYS = [
        'm365_client_secret',
        'google_service_account_json',
        'whatsapp_access_token',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        foreach (self::SECRET_KEYS as $key) {
            if (!empty($raw[$key])) {
                $raw[$key] = self::decrypt((string) $raw[$key]);
            }
        }
        return self::$cache = $raw;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function save(array $values): void
    {
        $sanitized = [];
        foreach ($values as $k => $v) {
            $sanitized[(string) $k] = is_string($v) ? wp_unslash($v) : $v;
        }
        foreach (self::SECRET_KEYS as $key) {
            if (!empty($sanitized[$key])) {
                $sanitized[$key] = self::encrypt((string) $sanitized[$key]);
            }
        }
        update_option(self::OPTION, $sanitized);
        self::$cache = null;
    }

    public static function is_configured(): bool
    {
        $s = self::all();
        return !empty($s['m365_tenant_id'])
            && !empty($s['m365_client_id'])
            && !empty($s['m365_client_secret'])
            && !empty($s['whatsapp_phone_number_id'])
            && !empty($s['whatsapp_access_token']);
    }

    private static function key(): string
    {
        if (defined('EXCDIS_ENCRYPTION_KEY') && is_string(EXCDIS_ENCRYPTION_KEY) && strlen(EXCDIS_ENCRYPTION_KEY) >= 32) {
            return substr((string) EXCDIS_ENCRYPTION_KEY, 0, 32);
        }
        $fallback = defined('AUTH_KEY') ? AUTH_KEY : 'excdis-fallback-key-please-override';
        return substr(hash('sha256', (string) $fallback, true), 0, 32);
    }

    private static function encrypt(string $plain): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return base64_encode($plain);
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());
        return 'v1:' . base64_encode($nonce . $cipher);
    }

    private static function decrypt(string $value): string
    {
        if (strncmp($value, 'v1:', 3) !== 0) {
            $decoded = base64_decode($value, true);
            return $decoded === false ? '' : $decoded;
        }
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }
        $bin = base64_decode(substr($value, 3), true);
        if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            return '';
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        return $plain === false ? '' : $plain;
    }
}
