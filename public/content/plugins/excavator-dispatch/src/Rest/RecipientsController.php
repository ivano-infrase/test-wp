<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Rest;

use InfraSe\ExcavatorDispatch\Integrations\GooglePeopleClient;
use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Services\RecipientFilter;
use InfraSe\ExcavatorDispatch\Support\Cache;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RecipientsController
{
    public function register_routes(): void
    {
        register_rest_route(Plugin::REST_NS, '/recipients', [
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
            Cache::forget('recipients_contacts');
        }
        try {
            $contacts = Cache::remember('recipients_contacts', Cache::TTL_DEFAULT, static function () {
                return (new GooglePeopleClient())->fetch_contacts();
            });
            $filtered = (new RecipientFilter())->apply($contacts);
            return new WP_REST_Response([
                'count'      => count($filtered),
                'total'      => count($contacts),
                'recipients' => $filtered,
            ]);
        } catch (Throwable $e) {
            return new WP_Error('excdis_recipients_error', $e->getMessage(), ['status' => 502]);
        }
    }
}
