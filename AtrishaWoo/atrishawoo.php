<?php
/**
 * Plugin Name: AtrishaWoo
 * Description: ابزارهای مدیریتی برای ووکامرس (شامل تولید یک‌باره SKU با شماره‌گذاری یکتا و افزایشی).
 * Version: 0.2.2
 * Author: Atrisha
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ATRISHAWOO_VERSION', '0.2.2');
define('ATRISHAWOO_PATH', plugin_dir_path(__FILE__));
define('ATRISHAWOO_URL', plugin_dir_url(__FILE__));

require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-plugin.php';

add_action('plugins_loaded', static function () {
	AtrishaWoo_Plugin::instance()->init();
});
