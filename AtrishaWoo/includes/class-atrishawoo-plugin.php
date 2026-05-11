<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Plugin {
	private static $instance;

	public static function instance(): self {
		if (!(self::$instance instanceof self)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	public function init(): void {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', static function () {
				if (!current_user_can('activate_plugins')) {
					return;
				}

				echo '<div class="notice notice-warning"><p>AtrishaWoo برای اجرا به WooCommerce نیاز دارد.</p></div>';
			});

			return;
		}

		require_once ATRISHAWOO_PATH . 'includes/class-atrishawoo-sku-generator.php';
		require_once ATRISHAWOO_PATH . 'admin/class-atrishawoo-admin.php';

		AtrishaWoo_Admin::instance()->init();
	}
}

