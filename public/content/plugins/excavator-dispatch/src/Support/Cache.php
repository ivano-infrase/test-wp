<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Support;

final class Cache
{
    public const TTL_DEFAULT = 300;

    public static function remember(string $key, int $ttl, callable $producer)
    {
        $full = 'excdis_' . $key;
        $hit = get_transient($full);
        if ($hit !== false) {
            return $hit;
        }
        $value = $producer();
        set_transient($full, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): void
    {
        delete_transient('excdis_' . $key);
    }

    public static function flush(): void
    {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_excdis_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        $like2 = $wpdb->esc_like('_transient_timeout_excdis_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2));
    }
}
