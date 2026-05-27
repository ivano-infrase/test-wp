<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Integrations;

use InfraSe\ExcavatorDispatch\Support\Settings;
use RuntimeException;

final class WhatsAppCloudClient
{
    private const API_VERSION = 'v20.0';
    private const API_BASE = 'https://graph.facebook.com';

    public function list_templates(): array
    {
        $waba_id = (string) Settings::get('whatsapp_waba_id', '');
        if ($waba_id === '') {
            throw new RuntimeException('WhatsApp: WABA ID non configurato.');
        }
        $token = (string) Settings::get('whatsapp_access_token', '');
        $url = self::API_BASE . '/' . self::API_VERSION . '/' . rawurlencode($waba_id) . '/message_templates?fields=name,status,language,category,components&limit=200';
        $out = [];
        while ($url) {
            $data = $this->graph_get($token, $url);
            foreach ((array) ($data['data'] ?? []) as $t) {
                if (($t['status'] ?? '') !== 'APPROVED') {
                    continue;
                }
                $out[] = [
                    'name'       => (string) ($t['name'] ?? ''),
                    'language'   => (string) ($t['language'] ?? ''),
                    'category'   => (string) ($t['category'] ?? ''),
                    'components' => $t['components'] ?? [],
                ];
            }
            $url = $data['paging']['next'] ?? null;
        }
        return $out;
    }

    /**
     * Send a template message.
     *
     * @param array<int, array{type: string, parameters: array}> $components
     */
    public function send_template(string $to, string $template_name, string $language, array $components = []): array
    {
        $phone_id = (string) Settings::get('whatsapp_phone_number_id', '');
        $token = (string) Settings::get('whatsapp_access_token', '');
        if ($phone_id === '' || $token === '') {
            throw new RuntimeException('WhatsApp: phone number ID o token mancanti.');
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $template_name,
                'language' => ['code' => $language],
            ],
        ];
        if (!empty($components)) {
            $payload['template']['components'] = array_values($components);
        }

        $resp = wp_remote_post(self::API_BASE . '/' . self::API_VERSION . '/' . rawurlencode($phone_id) . '/messages', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('WhatsApp: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? $body;
            throw new RuntimeException("WhatsApp {$code}: {$msg}");
        }
        return is_array($data) ? $data : [];
    }

    private function graph_get(string $token, string $url): array
    {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('WhatsApp GET: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("WhatsApp GET {$code}: {$body}");
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
