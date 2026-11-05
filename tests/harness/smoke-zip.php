<?php
/** Disposable test-only WordPress ZIP installation smoke check. */

declare(strict_types=1);

$slug = getenv('DIGICYFR_PLUGIN_SLUG');
$port = getenv('DIGICYFR_TEST_PORT');
if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    fwrite(STDERR, "Invalid plugin slug\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = $port;
$_SERVER['HTTPS'] = 'off';

if (!defined('FS_METHOD')) {
    define('FS_METHOD', 'direct');
}
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

if (!is_blog_installed()) {
    fwrite(STDERR, "Disposable WordPress site is not installed\n");
    exit(1);
}

$skin = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader($skin);
ob_start();
$installed = $upgrader->install('/tmp/digicyfr-plugin.zip');
ob_end_clean();
if (is_wp_error($installed) || !$installed) {
    fwrite(STDERR, "ZIP installation failed\n");
    if (is_wp_error($installed)) {
        fwrite(STDERR, $installed->get_error_message() . "\n");
    }
    exit(1);
}

$plugin_file = $slug . '/' . $slug . '.php';
$activation = activate_plugin($plugin_file);
if (is_wp_error($activation) || !is_plugin_active($plugin_file)) {
    fwrite(STDERR, "Activation failed\n");
    if (is_wp_error($activation)) {
        fwrite(STDERR, $activation->get_error_message() . "\n");
    }
    exit(1);
}

deactivate_plugins($plugin_file);
if (is_plugin_active($plugin_file)) {
    fwrite(STDERR, "Deactivation failed\n");
    exit(1);
}
$reactivation = activate_plugin($plugin_file);
if (is_wp_error($reactivation) || !is_plugin_active($plugin_file)) {
    fwrite(STDERR, "Reactivation failed\n");
    exit(1);
}

$data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
echo wp_json_encode(array(
    'status' => 'installed_activated_deactivated_reactivated',
    'slug' => $slug,
    'plugin_version' => $data['Version'],
    'wordpress_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
