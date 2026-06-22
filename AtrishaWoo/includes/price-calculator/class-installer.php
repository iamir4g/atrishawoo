<?php
/**
 * Installer.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation/deactivation tasks.
 */
class Installer {
	/**
	 * Create required database objects.
	 */
	public static function activate() {
		self::install_or_upgrade();
	}

	/**
	 * Install or safely migrate database objects.
	 *
	 * Index notes:
	 * - status_claim optimizes atomic pending-job claims by status/id.
	 * - sku_status optimizes duplicate detection for active jobs by SKU/status.
	 * - status_completed optimizes operational reporting by status/completed_at.
	 */
	public static function install_or_upgrade() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			base_sku varchar(191) NOT NULL,
			gram_price_100 decimal(18,6) NOT NULL DEFAULT 0,
			gram_price_50 decimal(18,6) NOT NULL DEFAULT 0,
			gram_price_30 decimal(18,6) NOT NULL DEFAULT 0,
			gram_price_10 decimal(18,6) NOT NULL DEFAULT 0,
			fixative_price decimal(18,6) NOT NULL DEFAULT 0,
			bottle_price decimal(18,6) NOT NULL DEFAULT 0,
			packaging_price decimal(18,6) NOT NULL DEFAULT 0,
			shipping_cost decimal(18,6) NOT NULL DEFAULT 0,
			tax_percent decimal(8,4) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			started_at datetime NULL DEFAULT NULL,
			completed_at datetime NULL DEFAULT NULL,
			processing_duration decimal(12,3) NULL DEFAULT NULL,
			last_error text NULL,
			retry_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status_id (status, id),
			KEY base_sku (base_sku),
			KEY status_claim (status, id, created_at),
			KEY sku_status (base_sku, status),
			KEY status_completed (status, completed_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'mgmp_db_version', \MGMP_VERSION, false );
	}

	/** Clear scheduled actions on deactivation. */
	public static function deactivate() {
		self::unschedule_actions();
	}

	/** Remove Action Scheduler jobs. */
	public static function unschedule_actions() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \MGMP_BATCH_HOOK );
		} elseif ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( \MGMP_BATCH_HOOK );
		}
	}

	/** Get queue table name. */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . \MGMP_TABLE_NAME;
	}
}
