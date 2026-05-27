<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch;

use InfraSe\ExcavatorDispatch\Admin\DispatchPage;
use InfraSe\ExcavatorDispatch\Admin\LogPage;
use InfraSe\ExcavatorDispatch\Admin\SettingsPage;
use InfraSe\ExcavatorDispatch\Rest\DispatchController;
use InfraSe\ExcavatorDispatch\Rest\MachinesController;
use InfraSe\ExcavatorDispatch\Rest\RecipientsController;
use InfraSe\ExcavatorDispatch\Rest\TemplatesController;
use InfraSe\ExcavatorDispatch\Support\Logger;

final class Plugin
{
    public const CAPABILITY = 'manage_options';
    public const MENU_SLUG  = 'excavator-dispatch';
    public const REST_NS    = 'excavator-dispatch/v1';

    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('excavator-dispatch', false, dirname(plugin_basename(EXCDIS_FILE)) . '/languages');

        (new SettingsPage())->register();
        (new DispatchPage())->register();
        (new LogPage())->register();

        add_action('rest_api_init', function (): void {
            (new MachinesController())->register_routes();
            (new RecipientsController())->register_routes();
            (new TemplatesController())->register_routes();
            (new DispatchController())->register_routes();
        });
    }

    public static function activate(): void
    {
        Logger::install_table();
        if (!get_option('excdis_settings')) {
            add_option('excdis_settings', []);
        }
    }

    public static function deactivate(): void
    {
        // Keep settings + log table on deactivation; uninstall.php would handle removal.
    }
}
