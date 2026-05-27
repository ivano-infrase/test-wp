<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Support;

final class Logger
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'excdis_dispatches';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            template_name VARCHAR(190) NOT NULL,
            template_language VARCHAR(10) NOT NULL,
            machine_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            recipient_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            success_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            details LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function record(array $row): int
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'created_at'        => current_time('mysql'),
            'user_id'           => get_current_user_id(),
            'template_name'     => (string) ($row['template_name'] ?? ''),
            'template_language' => (string) ($row['template_language'] ?? ''),
            'machine_count'     => (int) ($row['machine_count'] ?? 0),
            'recipient_count'   => (int) ($row['recipient_count'] ?? 0),
            'success_count'     => (int) ($row['success_count'] ?? 0),
            'error_count'       => (int) ($row['error_count'] ?? 0),
            'details'           => wp_json_encode($row['details'] ?? []),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function recent(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();
        $limit = max(1, min($limit, 500));
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
