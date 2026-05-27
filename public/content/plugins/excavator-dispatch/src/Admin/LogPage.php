<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Admin;

use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Support\Logger;

final class LogPage
{
    private const SLUG = Plugin::MENU_SLUG . '-log';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 30);
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
            <table class="widefat striped">
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
                        <td><details><summary><?php esc_html_e('Mostra', 'excavator-dispatch'); ?></summary><pre style="max-height:300px;overflow:auto"><?php echo esc_html((string) $row['details']); ?></pre></details></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
