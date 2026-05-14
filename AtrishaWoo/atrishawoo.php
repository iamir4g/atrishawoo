<?php
/**
 * Plugin Name: AtrishaWoo
 * Description: ابزارهای مدیریتی برای ووکامرس (شامل تولید یک‌باره SKU با شماره‌گذاری یکتا و افزایشی).
 * Version: 0.3.0
 * Author: Atrisha
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ATRISHAWOO_VERSION', '0.3.0');
define('ATRISHAWOO_PATH', plugin_dir_path(__FILE__));
define('ATRISHAWOO_URL', plugin_dir_url(__FILE__));

require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-plugin.php';

register_activation_hook(__FILE__, static function () {
	if (!class_exists('WooCommerce')) {
		return;
	}

	require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-recommendation-engine.php';
	AtrishaWoo_Recommendation_Engine::install();
});

register_deactivation_hook(__FILE__, static function () {
	$timestamp = wp_next_scheduled('atrishawoo_reco_backfill');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'atrishawoo_reco_backfill');
	}
});

add_action('plugins_loaded', static function () {
	AtrishaWoo_Plugin::instance()->init();
});
