<?php
/**
 * Queue manager.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages queue table access.
 */
class Queue_Manager {
	/** @var array<int,string> */
	private $active_statuses = array( 'pending', 'processing' );

	/** Insert one job unless an active job already exists for the SKU. */
	public function insert_job( array $data ) {
		global $wpdb;

		$base_sku = $this->normalize_sku( $data['base_sku'] ?? '' );
		if ( '' === $base_sku ) {
			return false;
		}

		// Serialize duplicate checks per SKU so concurrent imports cannot enqueue the same active job.
		$lock_name = 'mgmp_queue_' . md5( $base_sku );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $locked ) {
			return false;
		}

		if ( $this->active_job_exists( $base_sku ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return false;
		}

		$inserted = $wpdb->insert(
			Installer::table_name(),
			array(
				'base_sku'        => $base_sku,
				'gram_price_100'  => (float) $data['gram_price_100'],
				'gram_price_50'   => (float) $data['gram_price_50'],
				'gram_price_30'   => (float) $data['gram_price_30'],
				'gram_price_10'   => (float) $data['gram_price_10'],
				'fixative_price'  => (float) $data['fixative_price'],
				'bottle_price'    => (float) $data['bottle_price'],
				'packaging_price' => (float) $data['packaging_price'],
				'shipping_cost'   => (float) $data['shipping_cost'],
				'tax_percent'     => (float) $data['tax_percent'],
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' )
		);

		$insert_id = $inserted ? (int) $wpdb->insert_id : false;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

		return $insert_id;
	}

	/** Check whether pending or processing work already exists for a SKU. */
	public function active_job_exists( $base_sku ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $this->active_statuses ), '%s' ) );
		$params       = array_merge( array( $this->normalize_sku( $base_sku ) ), $this->active_statuses );
		$sql          = 'SELECT id FROM ' . Installer::table_name() . " WHERE base_sku = %s AND status IN ({$placeholders}) LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Atomically claim pending jobs and immediately mark them processing.
	 *
	 * The UPDATE includes status='pending' in the WHERE clause, so concurrent
	 * workers racing on the same IDs cannot both transition the same row. Only
	 * the worker whose UPDATE changes a row receives it in the follow-up SELECT.
	 * This avoids the select-then-update race without requiring MySQL 8 SKIP LOCKED.
	 *
	 * @param int $limit Batch limit.
	 * @return array<int,object>
	 */
	public function claim_pending_jobs( $limit = 20 ) {
		global $wpdb;

		$limit  = min( 20, max( 1, absint( $limit ) ) );
		$now    = current_time( 'mysql', true );
		$token  = wp_generate_uuid4();
		$table  = Installer::table_name();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'pending', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids    = array_map( 'absint', (array) $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params          = array_merge( array( 'processing', $now, $token, 'pending' ), $ids );
		$updated         = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, started_at = %s, completed_at = NULL, processing_duration = NULL, last_error = %s WHERE status = %s AND id IN ({$id_placeholders})", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $updated ) {
			return array();
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND last_error = %s ORDER BY id ASC", 'processing', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Backward-compatible pending fetch. Prefer claim_pending_jobs(). */
	public function fetch_pending_jobs( $limit = 20 ) {
		global $wpdb;

		$limit = min( 20, max( 1, absint( $limit ) ) );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table_name() . ' WHERE status = %s ORDER BY id ASC LIMIT %d', 'pending', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Update status and observability fields. */
	public function update_status( $job_id, $status, $error = '' ) {
		global $wpdb;

		$allowed = array( 'pending', 'processing', 'done', 'failed' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$data   = array( 'status' => $status );
		$format = array( '%s' );
		if ( in_array( $status, array( 'done', 'failed' ), true ) ) {
			$now                         = current_time( 'mysql', true );
			$data['completed_at']        = $now;
			$data['processing_duration'] = $this->calculate_duration( absint( $job_id ), $now );
			$data['last_error']          = 'failed' === $status ? sanitize_textarea_field( (string) $error ) : '';
			$format                      = array_merge( $format, array( '%s', '%f', '%s' ) );
		}

		return (bool) $wpdb->update( Installer::table_name(), $data, array( 'id' => absint( $job_id ) ), $format, array( '%d' ) );
	}

	/** Get counts by status. */
	public function get_counts() {
		global $wpdb;

		$rows   = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . Installer::table_name() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts = array( 'total' => 0, 'pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( $row->status );
			$total  = absint( $row->total );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $total;
				$counts['total']  += $total;
			}
		}
		return $counts;
	}

	/** Operational metrics for the admin dashboard. */
	public function get_metrics() {
		global $wpdb;

		$table     = Installer::table_name();
		$counts    = $this->get_counts();
		$completed = max( 0, $counts['done'] + $counts['failed'] );
		$avg       = (float) $wpdb->get_var( "SELECT AVG(processing_duration) FROM {$table} WHERE processing_duration IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$day_done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND completed_at >= %s", 'done', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_merge(
			$counts,
			array(
				'average_processing_duration' => round( $avg, 3 ),
				'throughput_24h'               => $day_done,
				'success_rate'                  => $completed ? round( ( $counts['done'] / $completed ) * 100, 2 ) : 0,
				'failure_rate'                  => $completed ? round( ( $counts['failed'] / $completed ) * 100, 2 ) : 0,
			)
		);
	}

	/** Whether pending jobs exist. */
	public function has_pending_jobs() {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table_name() . ' WHERE status = %s', 'pending' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $count > 0;
	}

	private function normalize_sku( $sku ) {
		return wc_clean( sanitize_text_field( (string) $sku ) );
	}

	private function calculate_duration( $job_id, $completed_at ) {
		global $wpdb;

		$started_at = $wpdb->get_var( $wpdb->prepare( 'SELECT started_at FROM ' . Installer::table_name() . ' WHERE id = %d', absint( $job_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $started_at ) ) {
			return null;
		}

		return max( 0, strtotime( $completed_at ) - strtotime( $started_at ) );
	}
}
