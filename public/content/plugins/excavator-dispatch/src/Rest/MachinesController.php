<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Rest;

use InfraSe\ExcavatorDispatch\Integrations\GraphExcelClient;
use InfraSe\ExcavatorDispatch\Plugin;
use InfraSe\ExcavatorDispatch\Services\MachineFilter;
use InfraSe\ExcavatorDispatch\Support\Cache;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class MachinesController
{
    public function register_routes(): void
    {
        register_rest_route(Plugin::REST_NS, '/machines', [
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
            Cache::forget('machines_rows');
        }
        try {
            $rows = Cache::remember('machines_rows', Cache::TTL_DEFAULT, static function () {
                return (new GraphExcelClient())->fetch_rows();
            });
            $filtered = (new MachineFilter())->apply($rows);
            return new WP_REST_Response([
                'count'    => count($filtered),
                'total'    => count($rows),
                'machines' => $filtered,
            ]);
        } catch (Throwable $e) {
            return new WP_Error('excdis_machines_error', $e->getMessage(), ['status' => 502]);
        }
    }
}
