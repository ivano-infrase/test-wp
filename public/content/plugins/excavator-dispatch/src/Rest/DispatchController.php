<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Rest;

use InfraSe\ExcavatorDispatch\Integrations\GraphExcelClient;
use InfraSe\ExcavatorDispatch\Integrations\WhatsAppCloudClient;
use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Services\MachineFilter;
use InfraSe\ExcavatorDispatch\Services\TemplateRenderer;
use InfraSe\ExcavatorDispatch\Support\Cache;
use InfraSe\ExcavatorDispatch\Support\Logger;
use InfraSe\ExcavatorDispatch\Support\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class DispatchController
{
    public function register_routes(): void
    {
        register_rest_route(Plugin::REST_NS, '/dispatch', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'can_access'],
            'callback'            => [$this, 'dispatch'],
            'args'                => [
                'template'    => ['type' => 'string', 'required' => true],
                'machine_ids' => ['type' => 'array', 'required' => true],
                'recipients'  => ['type' => 'array', 'required' => true],
            ],
        ]);
    }

    public function can_access(): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    public function dispatch(WP_REST_Request $request)
    {
        $template = sanitize_text_field((string) $request->get_param('template'));
        $machine_ids = array_map('strval', (array) $request->get_param('machine_ids'));
        $recipients_raw = (array) $request->get_param('recipients');

        if ($template === '' || empty($machine_ids) || empty($recipients_raw)) {
            return new WP_Error('excdis_invalid', 'Template, macchine e destinatari sono richiesti.', ['status' => 400]);
        }

        $language = (string) Settings::get('whatsapp_default_language', 'it');

        try {
            $rows = Cache::remember('machines_rows', Cache::TTL_DEFAULT, static function () {
                return (new GraphExcelClient())->fetch_rows();
            });
            $rows = (new MachineFilter())->apply($rows);
            $selected = $this->select_rows($rows, $machine_ids);
            if (empty($selected)) {
                return new WP_Error('excdis_no_machines', 'Nessuna macchina selezionata corrisponde ai dati correnti.', ['status' => 400]);
            }

            $renderer = new TemplateRenderer();
            $client = new WhatsAppCloudClient();

            $details = [];
            $success = 0;
            $errors  = 0;
            foreach ($recipients_raw as $r) {
                $to = $this->normalize_phone((string) ($r['phone'] ?? $r));
                $name = (string) ($r['name'] ?? '');
                if ($to === '') {
                    $errors++;
                    $details[] = ['name' => $name, 'phone' => '', 'ok' => false, 'error' => 'Numero non valido'];
                    continue;
                }
                $recipient_ctx = [
                    'name'         => $name,
                    'phone'        => $to,
                    'organization' => (string) ($r['organization'] ?? ''),
                ];
                $components = $renderer->build_components($template, $selected, $recipient_ctx);
                try {
                    $resp = $client->send_template($to, $template, $language, $components);
                    $msg_id = $resp['messages'][0]['id'] ?? '';
                    $success++;
                    $details[] = ['name' => $name, 'phone' => $to, 'ok' => true, 'message_id' => $msg_id];
                } catch (Throwable $e) {
                    $errors++;
                    $details[] = ['name' => $name, 'phone' => $to, 'ok' => false, 'error' => $e->getMessage()];
                }
                usleep(50000); // ~20 msg/s soft throttle.
            }

            Logger::record([
                'template_name'     => $template,
                'template_language' => $language,
                'machine_count'     => count($selected),
                'recipient_count'   => count($recipients_raw),
                'success_count'     => $success,
                'error_count'       => $errors,
                'details'           => [
                    'machines' => $selected,
                    'results'  => $details,
                ],
            ]);

            return new WP_REST_Response([
                'success'   => $success,
                'errors'    => $errors,
                'total'     => count($recipients_raw),
                'machines'  => count($selected),
                'results'   => $details,
            ]);
        } catch (Throwable $e) {
            return new WP_Error('excdis_dispatch_error', $e->getMessage(), ['status' => 502]);
        }
    }

    private function select_rows(array $rows, array $ids): array
    {
        $id_col = (string) Settings::get('machine_id_column', '');
        $out = [];
        $ids_map = array_flip($ids);
        foreach ($rows as $row) {
            $row_id = $id_col !== ''
                ? (string) ($row[$id_col] ?? '')
                : (string) ($row['__row'] ?? '');
            if ($row_id !== '' && isset($ids_map[$row_id])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function normalize_phone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        // WhatsApp Cloud API wants E.164 without the leading '+'.
        $digits = preg_replace('/\D+/', '', $phone);
        return $digits ?: '';
    }
}
