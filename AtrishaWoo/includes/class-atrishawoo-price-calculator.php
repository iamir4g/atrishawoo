<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Price_Calculator {
	/** @var \AtrishaWoo\PriceCalculator\Admin_Page|null */
	private static $admin_page;

	public static function init(): void {
		if (class_exists('MohasebeGheymatMahsulat\\Installer')) {
			add_action('admin_notices', static function () {
				if (!current_user_can('activate_plugins')) {
					return;
				}
				echo '<div class="notice notice-error"><p>AtrishaWoo: افزونه جداگانه «محاسبه قیمت محصولات» فعال است. لطفاً یکی از این دو را غیرفعال کنید.</p></div>';
			});
			return;
		}

		if (!defined('MGMP_VERSION')) {
			define('MGMP_VERSION', ATRISHAWOO_VERSION);
			define('MGMP_TABLE_NAME', 'perfume_price_jobs');
			define('MGMP_BATCH_HOOK', 'atrishawoo_perfume_price_batch_worker');
			define('MGMP_ASSETS_DIR', ATRISHAWOO_PATH . 'assets/price-calculator/');
			define('MGMP_ASSETS_URL', ATRISHAWOO_URL . 'assets/price-calculator/');
		}

		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-installer.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-logger.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-price-calculator.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-queue-manager.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-csv-importer.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-batch-processor.php';
		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-admin-page.php';

		\AtrishaWoo\PriceCalculator\Installer::install_or_upgrade();

		$queue_manager = new \AtrishaWoo\PriceCalculator\Queue_Manager();
		$logger = new \AtrishaWoo\PriceCalculator\Logger();
		$csv_importer = new \AtrishaWoo\PriceCalculator\CSV_Importer($queue_manager, $logger);
		self::$admin_page = new \AtrishaWoo\PriceCalculator\Admin_Page($queue_manager, $csv_importer, $logger);
		self::$admin_page->register_hooks();

		$calculator = new \AtrishaWoo\PriceCalculator\Price_Calculator();
		$batch_worker = new \AtrishaWoo\PriceCalculator\Batch_Processor($queue_manager, $calculator, $logger);
		$batch_worker->register_hooks();
	}

	public static function admin_page(): ?\AtrishaWoo\PriceCalculator\Admin_Page {
		return self::$admin_page;
	}

	public static function deactivate(): void {
		if (!defined('MGMP_BATCH_HOOK')) {
			define('MGMP_BATCH_HOOK', 'atrishawoo_perfume_price_batch_worker');
		}

		require_once ATRISHAWOO_PATH . 'includes/price-calculator/class-installer.php';
		\AtrishaWoo\PriceCalculator\Installer::deactivate();
	}

	public static function uninstall_cleanup(): void {
		global $wpdb;

		if (class_exists('AtrishaWoo\\PriceCalculator\\Installer')) {
			$table_name = \AtrishaWoo\PriceCalculator\Installer::table_name();
			$wpdb->query('DROP TABLE IF EXISTS ' . $table_name); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			\AtrishaWoo\PriceCalculator\Installer::unschedule_actions();
		}

		delete_option('mgmp_db_version');
		delete_option('mgmp_pricing_defaults');
	}

	public static function enqueue_mui_assets(): void {
		$vendor_url = ATRISHAWOO_URL . 'assets/vendor/';
		$vendor_dir = ATRISHAWOO_PATH . 'assets/vendor/';

		$scripts = [
			'atrishawoo-react' => ['file' => 'react.min.js', 'ver' => '18.3.1', 'deps' => []],
			'atrishawoo-react-dom' => ['file' => 'react-dom.min.js', 'ver' => '18.3.1', 'deps' => ['atrishawoo-react']],
			'atrishawoo-emotion-react' => ['file' => 'emotion-react.min.js', 'ver' => '11.11.4', 'deps' => ['atrishawoo-react']],
			'atrishawoo-emotion-styled' => ['file' => 'emotion-styled.min.js', 'ver' => '11.11.5', 'deps' => ['atrishawoo-react', 'atrishawoo-emotion-react']],
			'atrishawoo-mui' => ['file' => 'mui.min.js', 'ver' => '5.15.21', 'deps' => ['atrishawoo-react', 'atrishawoo-react-dom', 'atrishawoo-emotion-react', 'atrishawoo-emotion-styled']],
		];

		foreach ($scripts as $handle => $script) {
			$path = $vendor_dir . $script['file'];
			if (!file_exists($path)) {
				continue;
			}

			wp_enqueue_script(
				$handle,
				$vendor_url . $script['file'],
				$script['deps'],
				$script['ver'],
				true
			);
		}
	}

	public static function render_tab(): void {
		$admin = self::$admin_page;
		if (!$admin) {
			echo '<div class="notice notice-error"><p>ماژول محاسبه قیمت بارگذاری نشد.</p></div>';
			return;
		}

		$admin->render_embedded();
	}
}
