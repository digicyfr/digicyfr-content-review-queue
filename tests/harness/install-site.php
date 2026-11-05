<?php
/** Bootstrap a disposable WordPress site for plugin ZIP smoke tests. */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'off';
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if (!is_blog_installed()) {
    wp_install('Digicyfr disposable plugin test', 'digicyfr_test_admin', 'test-admin@example.invalid', false, '', 'test-only-password');
}
echo "Disposable WordPress site installed\n";
