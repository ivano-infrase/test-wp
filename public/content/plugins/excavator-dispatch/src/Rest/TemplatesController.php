<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Rest;

use InfraSe\ExcavatorDispatch\Integrations\WhatsAppCloudClient;
use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Support\Cache;
use InfraSe\ExcavatorDispatch\Support\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class TemplatesController
{
    public function register_routes(): void
    {
        register_rest_route(Plugin::REST_NS, '/templates', [
            'methods'             => 'GET',
            'permission_callback' => [$this, 'can_access'],
            'callback'            => [$this, 'index'],
            'args'                => [
                'refresh' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function can_access(): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    public function index(WP_REST_Request $request)
    {
        if ($request->get_param('refresh')) {
            Cache::forget('whatsapp_templates');
        }
        $language = (string) Settings::get('whatsapp_default_language', 'it');
        try {
            $templates = Cache::remember('whatsapp_templates', 600, static function () {
                return (new WhatsAppCloudClient())->list_templates();
            });
            $filtered = array_values(array_filter($templates, static function (array $t) use ($language): bool {
                return $language === '' || ($t['language'] ?? '') === $language;
            }));
            return new WP_REST_Response([
                'language'  => $language,
                'count'     => count($filtered),
                'templates' => $filtered,
            ]);
        } catch (Throwable $e) {
            return new WP_Error('excdis_templates_error', $e->getMessage(), ['status' => 502]);
        }
    }
}
