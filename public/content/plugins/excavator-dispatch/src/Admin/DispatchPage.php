<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Admin;

use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Support\Settings;

final class DispatchPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('Excavator Dispatch', 'excavator-dispatch'),
            __('Excavator Dispatch', 'excavator-dispatch'),
            Plugin::CAPABILITY,
            Plugin::MENU_SLUG,
            [$this, 'render'],
            'dashicons-megaphone',
            58
        );
    }

    public function enqueue(string $hook): void
    {
        if (strpos($hook, Plugin::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style(
            'excdis-admin',
            EXCDIS_URL . 'assets/css/dispatch.css',
            [],
            EXCDIS_VERSION
        );
        wp_enqueue_script(
            'excdis-admin',
            EXCDIS_URL . 'assets/js/dispatch.js',
            [],
            EXCDIS_VERSION,
            true
        );
        wp_localize_script('excdis-admin', 'EXCDIS', [
            'restUrl' => esc_url_raw(rest_url(Plugin::REST_NS . '/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'idCol'   => (string) Settings::get('machine_id_column', ''),
            'labels'  => (array) Settings::get('machine_label_columns', []),
            'i18n'    => [
                'loading'   => __('Caricamento…', 'excavator-dispatch'),
                'noResults' => __('Nessun risultato', 'excavator-dispatch'),
                'send'      => __('Invia messaggio WhatsApp', 'excavator-dispatch'),
                'confirm'   => __('Confermi l\'invio?', 'excavator-dispatch'),
            ],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            return;
        }
        $configured = Settings::is_configured();
        ?>
        <div class="wrap excdis-wrap">
            <h1><?php esc_html_e('Excavator Dispatch', 'excavator-dispatch'); ?></h1>

            <?php if (!$configured): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: settings link */
                            esc_html__('Configurazione incompleta. Apri le %s prima di usare la pagina.', 'excavator-dispatch'),
                            '<a href="' . esc_url(admin_url('admin.php?page=' . Plugin::MENU_SLUG . '-settings')) . '">'
                                . esc_html__('Impostazioni', 'excavator-dispatch')
                                . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="excdis-grid">
                <section class="excdis-panel">
                    <header class="excdis-panel__head">
                        <h2><?php esc_html_e('Macchine disponibili', 'excavator-dispatch'); ?></h2>
                        <div class="excdis-panel__actions">
                            <input type="search" id="excdis-machine-search" placeholder="<?php esc_attr_e('Cerca…', 'excavator-dispatch'); ?>" />
                            <button class="button" id="excdis-machines-refresh"><?php esc_html_e('Aggiorna', 'excavator-dispatch'); ?></button>
                        </div>
                    </header>
                    <div class="excdis-panel__body">
                        <table class="widefat striped" id="excdis-machines-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" id="excdis-machines-all" /></th>
                                    <th><?php esc_html_e('Macchina', 'excavator-dispatch'); ?></th>
                                </tr>
                            </thead>
                            <tbody><tr><td colspan="2"><?php esc_html_e('Caricamento…', 'excavator-dispatch'); ?></td></tr></tbody>
                        </table>
                    </div>
                    <footer class="excdis-panel__foot">
                        <span id="excdis-machines-count">0</span> <?php esc_html_e('selezionate', 'excavator-dispatch'); ?>
                    </footer>
                </section>

                <section class="excdis-panel">
                    <header class="excdis-panel__head">
                        <h2><?php esc_html_e('Destinatari', 'excavator-dispatch'); ?></h2>
                        <div class="excdis-panel__actions">
                            <input type="search" id="excdis-recipient-search" placeholder="<?php esc_attr_e('Cerca…', 'excavator-dispatch'); ?>" />
                            <button class="button" id="excdis-recipients-refresh"><?php esc_html_e('Aggiorna', 'excavator-dispatch'); ?></button>
                        </div>
                    </header>
                    <div class="excdis-panel__body">
                        <table class="widefat striped" id="excdis-recipients-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" id="excdis-recipients-all" /></th>
                                    <th><?php esc_html_e('Nome', 'excavator-dispatch'); ?></th>
                                    <th><?php esc_html_e('Telefono', 'excavator-dispatch'); ?></th>
                                </tr>
                            </thead>
                            <tbody><tr><td colspan="3"><?php esc_html_e('Caricamento…', 'excavator-dispatch'); ?></td></tr></tbody>
                        </table>
                    </div>
                    <footer class="excdis-panel__foot">
                        <span id="excdis-recipients-count">0</span> <?php esc_html_e('selezionati', 'excavator-dispatch'); ?>
                    </footer>
                </section>
            </div>

            <section class="excdis-send">
                <label for="excdis-template"><?php esc_html_e('Template WhatsApp', 'excavator-dispatch'); ?></label>
                <select id="excdis-template"><option><?php esc_html_e('Caricamento…', 'excavator-dispatch'); ?></option></select>

                <details class="excdis-preview">
                    <summary><?php esc_html_e('Anteprima messaggio', 'excavator-dispatch'); ?></summary>
                    <pre id="excdis-preview"></pre>
                </details>

                <button class="button button-primary button-hero" id="excdis-send"><?php esc_html_e('Invia messaggio WhatsApp', 'excavator-dispatch'); ?></button>
                <div id="excdis-status" class="excdis-status"></div>
            </section>
        </div>
        <?php
    }
}
