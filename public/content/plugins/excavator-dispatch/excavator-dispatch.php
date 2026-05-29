<?php
/**
 * Plugin Name: Excavator Dispatch
 * Description: Filtra macchine da un foglio Excel su M365, destinatari da Google Contacts, e invia un template WhatsApp via Cloud API.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: InfraSe
 * License: GPL-2.0+
 * Text Domain: excavator-dispatch
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('EXCDIS_VERSION', '0.1.0');
define('EXCDIS_FILE', __FILE__);
define('EXCDIS_DIR', __DIR__);
define('EXCDIS_URL', plugin_dir_url(__FILE__));

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'InfraSe\\ExcavatorDispatch\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [\InfraSe\ExcavatorDispatch\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\InfraSe\ExcavatorDispatch\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \InfraSe\ExcavatorDispatch\Plugin::instance()->boot();
});
