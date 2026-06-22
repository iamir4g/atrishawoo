<?php
/**
 * Plugin Name: AtrishaWoo
 * Description: ابزارهای مدیریتی برای ووکامرس (SKU، لیبل سفارش، محاسبه قیمت عطر و پیشنهاد محصول).
 * Version: 0.4.3
 * Author: Atrisha
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ATRISHAWOO_VERSION', '0.4.3');
define('ATRISHAWOO_PATH', plugin_dir_path(__FILE__));
define('ATRISHAWOO_URL', plugin_dir_url(__FILE__));

require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-plugin.php';

register_activation_hook(__FILE__, static function () {
	require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-recommendation-engine.php';
	AtrishaWoo_Recommendation_Engine::install();

	if (!defined('MGMP_VERSION')) {
		define('MGMP_VERSION', ATRISHAWOO_VERSION);
		define('MGMP_TABLE_NAME', 'perfume_price_jobs');
		define('MGMP_BATCH_HOOK', 'atrishawoo_perfume_price_batch_worker');
		define('MGMP_BATCH_SIZE', 50);
		define('MGMP_PRICE_ROUND_STEP', 1000);
		define('MGMP_ASSETS_DIR', ATRISHAWOO_PATH . 'assets/price-calculator/');
		define('MGMP_ASSETS_URL', ATRISHAWOO_URL . 'assets/price-calculator/');
	}
	require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-installer.php';
	\AtrishaWoo\PriceCalculator\Installer::activate();
});

register_deactivation_hook(__FILE__, static function () {
	$timestamp = wp_next_scheduled('atrishawoo_reco_backfill');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'atrishawoo_reco_backfill');
	}

	require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-price-calculator.php';
	AtrishaWoo_Price_Calculator::deactivate();
});

add_action('plugins_loaded', static function () {
	AtrishaWoo_Plugin::instance()->init();
}, 20);
