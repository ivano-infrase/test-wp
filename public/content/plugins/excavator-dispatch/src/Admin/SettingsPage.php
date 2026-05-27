<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Admin;

use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Support\Cache;
use InfraSe\ExcavatorDispatch\Support\Settings;

final class SettingsPage
{
    private const SLUG = Plugin::MENU_SLUG . '-settings';
    private const NONCE = 'excdis_settings_save';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 20);
        add_action('admin_post_excdis_save_settings', [$this, 'handle_save']);
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            Plugin::MENU_SLUG,
            __('Impostazioni', 'excavator-dispatch'),
            __('Impostazioni', 'excavator-dispatch'),
            Plugin::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function handle_save(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            wp_die('forbidden');
        }
        check_admin_referer(self::NONCE);

        $input = wp_unslash($_POST['excdis'] ?? []);
        if (!is_array($input)) {
            $input = [];
        }

        $current = Settings::all();
        $secrets = ['m365_client_secret', 'google_service_account_json', 'whatsapp_access_token'];
        foreach ($secrets as $key) {
            if (!isset($input[$key]) || $input[$key] === '') {
                $input[$key] = $current[$key] ?? '';
            }
        }

        $clean = [
            'm365_tenant_id'             => sanitize_text_field((string) ($input['m365_tenant_id'] ?? '')),
            'm365_client_id'             => sanitize_text_field((string) ($input['m365_client_id'] ?? '')),
            'm365_client_secret'         => (string) ($input['m365_client_secret'] ?? ''),
            'm365_drive_id'              => sanitize_text_field((string) ($input['m365_drive_id'] ?? '')),
            'm365_item_id'               => sanitize_text_field((string) ($input['m365_item_id'] ?? '')),
            'm365_file_path'             => sanitize_text_field((string) ($input['m365_file_path'] ?? '')),
            'm365_worksheet'             => sanitize_text_field((string) ($input['m365_worksheet'] ?? '')),
            'm365_table_name'            => sanitize_text_field((string) ($input['m365_table_name'] ?? '')),
            'google_service_account_json'=> (string) ($input['google_service_account_json'] ?? ''),
            'google_impersonate_email'   => sanitize_email((string) ($input['google_impersonate_email'] ?? '')),
            'google_group_ids'           => array_filter(array_map('sanitize_text_field', explode("\n", (string) ($input['google_group_ids'] ?? '')))),
            'default_country_code'       => sanitize_text_field((string) ($input['default_country_code'] ?? '')),
            'whatsapp_phone_number_id'   => sanitize_text_field((string) ($input['whatsapp_phone_number_id'] ?? '')),
            'whatsapp_waba_id'           => sanitize_text_field((string) ($input['whatsapp_waba_id'] ?? '')),
            'whatsapp_access_token'      => (string) ($input['whatsapp_access_token'] ?? ''),
            'whatsapp_default_language'  => sanitize_text_field((string) ($input['whatsapp_default_language'] ?? 'it')),
            'machine_id_column'          => sanitize_text_field((string) ($input['machine_id_column'] ?? '')),
            'machine_label_columns'      => array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', (string) ($input['machine_label_columns'] ?? ''))))),
            'machine_filters'            => $this->parse_filters((string) ($input['machine_filters'] ?? '')),
            'recipients_require_phone'   => !empty($input['recipients_require_phone']),
            'recipients_name_regex'      => (string) ($input['recipients_name_regex'] ?? ''),
            'template_mappings'          => $this->parse_mappings((string) ($input['template_mappings'] ?? '')),
        ];

        Settings::save($clean);
        Cache::flush();

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    private function parse_filters(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^(.+?)\s*(eq|neq|contains|starts|empty|not_empty)\s*(.*)$/i', $line, $m)) {
                continue;
            }
            $out[] = ['column' => trim($m[1]), 'op' => strtolower($m[2]), 'value' => trim($m[3])];
        }
        return $out;
    }

    private function parse_mappings(string $raw): array
    {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            return;
        }
        $s = Settings::all();
        $updated = !empty($_GET['updated']);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Excavator Dispatch — Impostazioni', 'excavator-dispatch'); ?></h1>
            <?php if ($updated): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Impostazioni salvate.', 'excavator-dispatch'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="excdis_save_settings" />
                <?php wp_nonce_field(self::NONCE); ?>

                <h2><?php esc_html_e('Microsoft 365 (Excel via Graph)', 'excavator-dispatch'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->row('Tenant ID', 'm365_tenant_id', $s); ?>
                    <?php $this->row('Client ID', 'm365_client_id', $s); ?>
                    <?php $this->secret_row('Client Secret', 'm365_client_secret', $s); ?>
                    <?php $this->row('Drive ID (SharePoint/OneDrive)', 'm365_drive_id', $s); ?>
                    <?php $this->row('Item ID (opzionale se path)', 'm365_item_id', $s); ?>
                    <?php $this->row('Path file (alternativa a item id)', 'm365_file_path', $s, 'es: Documenti/Macchine.xlsx'); ?>
                    <?php $this->row('Worksheet (se non si usa una tabella)', 'm365_worksheet', $s, 'es: Sheet1'); ?>
                    <?php $this->row('Nome tabella (Excel table, consigliato)', 'm365_table_name', $s); ?>
                </table>

                <h2><?php esc_html_e('Google Contacts (People API)', 'excavator-dispatch'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->secret_textarea('Service account JSON', 'google_service_account_json', $s); ?>
                    <?php $this->row('Email utente impersonato (domain-wide delegation)', 'google_impersonate_email', $s); ?>
                    <?php $this->textarea('Contact group resource names (uno per riga)', 'google_group_ids', $s, "es: contactGroups/xxxxxxxxxxxxxxxx"); ?>
                    <?php $this->row('Prefisso paese default (numeri senza +)', 'default_country_code', $s, 'es: 39'); ?>
                </table>

                <h2><?php esc_html_e('WhatsApp Cloud API', 'excavator-dispatch'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->row('Phone Number ID', 'whatsapp_phone_number_id', $s); ?>
                    <?php $this->row('WhatsApp Business Account (WABA) ID', 'whatsapp_waba_id', $s); ?>
                    <?php $this->secret_row('Access Token (long-lived)', 'whatsapp_access_token', $s); ?>
                    <?php $this->row('Lingua template di default', 'whatsapp_default_language', $s, 'es: it, en_US'); ?>
                </table>

                <h2><?php esc_html_e('Macchine — colonne e filtri', 'excavator-dispatch'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->row('Colonna ID macchina (per selezione stabile)', 'machine_id_column', $s, 'es: Matricola'); ?>
                    <?php $this->row('Colonne da mostrare in lista (csv)', 'machine_label_columns', $s, 'es: Modello,Anno,Stato', static function ($v): string {
                        return is_array($v) ? implode(',', $v) : (string) $v;
                    }); ?>
                    <?php $this->textarea('Regole filtro (una per riga: "Colonna op valore")', 'machine_filters', $s, "es: Stato eq Disponibile\nFamiglia contains Escavatore", static function ($v): string {
                        if (!is_array($v)) return '';
                        $lines = [];
                        foreach ($v as $r) {
                            $lines[] = trim(sprintf('%s %s %s', $r['column'] ?? '', $r['op'] ?? 'eq', $r['value'] ?? ''));
                        }
                        return implode("\n", $lines);
                    }); ?>
                </table>

                <h2><?php esc_html_e('Destinatari — filtri', 'excavator-dispatch'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="excdis_recipients_require_phone"><?php esc_html_e('Solo contatti con numero', 'excavator-dispatch'); ?></label></th>
                        <td><input type="checkbox" id="excdis_recipients_require_phone" name="excdis[recipients_require_phone]" value="1" <?php checked(!empty($s['recipients_require_phone']) || !isset($s['recipients_require_phone'])); ?> /></td>
                    </tr>
                    <?php $this->row('Regex nome (opzionale)', 'recipients_name_regex', $s); ?>
                </table>

                <h2><?php esc_html_e('Mapping template (JSON)', 'excavator-dispatch'); ?></h2>
                <p class="description"><?php esc_html_e('Mappa per ogni template Meta le variabili di header/body/footer a espressioni. Espressioni: {col:Colonna}, {list:Col1,Col2}, {count}, oppure testo libero.', 'excavator-dispatch'); ?></p>
                <?php $this->textarea('JSON', 'template_mappings', $s, '{"avviso_macchine":{"body":["{count}","{list:Modello,Anno}"]}}', static function ($v): string {
                    return is_array($v) ? (string) wp_json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $v;
                }, 10); ?>

                <p class="submit"><button class="button button-primary"><?php esc_html_e('Salva impostazioni', 'excavator-dispatch'); ?></button></p>
            </form>
        </div>
        <?php
    }

    private function row(string $label, string $key, array $s, string $placeholder = '', $serializer = null): void
    {
        $value = $serializer ? $serializer($s[$key] ?? '') : ($s[$key] ?? '');
        ?>
        <tr>
            <th scope="row"><label for="excdis_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input type="text" class="regular-text" id="excdis_<?php echo esc_attr($key); ?>" name="excdis[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" /></td>
        </tr>
        <?php
    }

    private function secret_row(string $label, string $key, array $s): void
    {
        $has = !empty($s[$key]);
        ?>
        <tr>
            <th scope="row"><label for="excdis_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="password" class="regular-text" id="excdis_<?php echo esc_attr($key); ?>" name="excdis[<?php echo esc_attr($key); ?>]" value="" autocomplete="new-password" placeholder="<?php echo $has ? esc_attr__('(impostato — lascia vuoto per non modificare)', 'excavator-dispatch') : ''; ?>" />
            </td>
        </tr>
        <?php
    }

    private function textarea(string $label, string $key, array $s, string $placeholder = '', $serializer = null, int $rows = 5): void
    {
        $value = $serializer ? $serializer($s[$key] ?? '') : ($s[$key] ?? '');
        if (is_array($value)) {
            $value = implode("\n", $value);
        }
        ?>
        <tr>
            <th scope="row"><label for="excdis_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><textarea class="large-text code" rows="<?php echo (int) $rows; ?>" id="excdis_<?php echo esc_attr($key); ?>" name="excdis[<?php echo esc_attr($key); ?>]" placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea((string) $value); ?></textarea></td>
        </tr>
        <?php
    }

    private function secret_textarea(string $label, string $key, array $s): void
    {
        $has = !empty($s[$key]);
        ?>
        <tr>
            <th scope="row"><label for="excdis_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <textarea class="large-text code" rows="6" id="excdis_<?php echo esc_attr($key); ?>" name="excdis[<?php echo esc_attr($key); ?>]" placeholder="<?php echo $has ? esc_attr__('(impostato — lascia vuoto per non modificare)', 'excavator-dispatch') : ''; ?>"></textarea>
            </td>
        </tr>
        <?php
    }
}
