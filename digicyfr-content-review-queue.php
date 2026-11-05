<?php
/**
 * Plugin Name: Digicyfr Content Review Queue
 * Description: A private, date-based review queue for published posts and pages.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Digicyfr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-content-review-queue
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-content-review-queue/
 *
 * @package Digicyfr_Content_Review_Queue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGICYFR_CONTENT_REVIEW_QUEUE_VERSION', '0.1.0' );
define( 'DIGICYFR_CONTENT_REVIEW_QUEUE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGICYFR_CONTENT_REVIEW_QUEUE_URL', plugin_dir_url( __FILE__ ) );

require_once DIGICYFR_CONTENT_REVIEW_QUEUE_PATH . 'includes/class-digicyfr-content-review-queue.php';
Digicyfr_Content_Review_Queue::boot();
