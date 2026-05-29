<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Admin;

use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Support\Logger;

final class LogPage
{
    private const SLUG = Plugin::MENU_SLUG . '-log';

    private const NONCE_PRUNE = 'excdis_log_prune';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 30);
        add_action('admin_post_excdis_prune_log', [$this, 'handle_prune']);
    }

    public function handle_prune(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE_PRUNE);
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'older'));
        if ($mode === 'all') {
            $count = Logger::delete_all();
        } else {
            $days = max(0, (int) ($_POST['days'] ?? 30));
            $count = Logger::delete_older_than($days);
        }
        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'pruned' => $count],
            admin_url('admin.php')
        ));
        exit;
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            Plugin::MENU_SLUG,
            __('Storico invii', 'excavator-dispatch'),
            __('Storico invii', 'excavator-dispatch'),
            Plugin::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            return;
        }
        $rows = Logger::recent(100);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Storico invii', 'excavator-dispatch'); ?></h1>
            <?php if (isset($_GET['pruned'])): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf(
                        /* translators: %d is the number of deleted rows. */
                        esc_html__('Eliminate %d righe dallo storico.', 'excavator-dispatch'),
                        (int) $_GET['pruned']
                    ); ?>
                </p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 12px 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="excdis_prune_log" />
                <?php wp_nonce_field(self::NONCE_PRUNE); ?>
                <label>
                    <?php esc_html_e('Elimina righe più vecchie di', 'excavator-dispatch'); ?>
                    <input type="number" name="days" value="30" min="0" step="1" style="width: 70px;" />
                    <?php esc_html_e('giorni', 'excavator-dispatch'); ?>
                </label>
                <button class="button" name="mode" value="older"><?php esc_html_e('Pulisci', 'excavator-dispatch'); ?></button>
                <button class="button button-link-delete" name="mode" value="all"
                    onclick="return confirm('<?php echo esc_js(__('Eliminare TUTTO lo storico?', 'excavator-dispatch')); ?>');"
                ><?php esc_html_e('Cancella tutto', 'excavator-dispatch'); ?></button>
            </form>
            <style>
                .excdis-log { table-layout: fixed; width: 100%; }
                .excdis-log th, .excdis-log td { word-wrap: break-word; overflow-wrap: anywhere; vertical-align: top; }
                .excdis-log col.c-date { width: 140px; }
                .excdis-log col.c-user { width: 110px; }
                .excdis-log col.c-tpl { width: 200px; }
                .excdis-log col.c-num { width: 70px; }
                .excdis-log col.c-det { width: auto; }
                .excdis-log .excdis-det { white-space: pre-wrap; word-break: break-word; max-height: 320px; overflow: auto; background: #f6f7f7; padding: 10px; border: 1px solid #dcdcde; border-radius: 4px; margin: 6px 0 0; font-size: 12px; }
            </style>
            <table class="widefat striped excdis-log">
                <colgroup>
                    <col class="c-date" />
                    <col class="c-user" />
                    <col class="c-tpl" />
                    <col class="c-num" />
                    <col class="c-num" />
                    <col class="c-num" />
                    <col class="c-num" />
                    <col class="c-det" />
                </colgroup>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Data', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Utente', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Template', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Macchine', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Destinatari', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('OK', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Errori', 'excavator-dispatch'); ?></th>
                        <th><?php esc_html_e('Dettagli', 'excavator-dispatch'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8"><?php esc_html_e('Nessun invio.', 'excavator-dispatch'); ?></td></tr>
                <?php else: foreach ($rows as $row): $user = get_userdata((int) $row['user_id']); ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['created_at']); ?></td>
                        <td><?php echo esc_html($user ? $user->user_login : '#' . (int) $row['user_id']); ?></td>
                        <td><?php echo esc_html((string) $row['template_name']); ?> <small>(<?php echo esc_html((string) $row['template_language']); ?>)</small></td>
                        <td><?php echo (int) $row['machine_count']; ?></td>
                        <td><?php echo (int) $row['recipient_count']; ?></td>
                        <td><?php echo (int) $row['success_count']; ?></td>
                        <td><?php echo (int) $row['error_count']; ?></td>
                        <td><details><summary><?php esc_html_e('Mostra', 'excavator-dispatch'); ?></summary><pre class="excdis-det"><?php echo esc_html((string) $row['details']); ?></pre></details></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
