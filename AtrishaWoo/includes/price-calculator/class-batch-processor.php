<?php
/**
 * Batch processor.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Processes queued jobs in Action Scheduler batches. */
class Batch_Processor {
	/** @var Queue_Manager */
	private $queue_manager;
	/** @var Price_Calculator */
	private $calculator;
	/** @var Logger */
	private $logger;

	public function __construct( Queue_Manager $queue_manager, Price_Calculator $calculator, Logger $logger ) {
		$this->queue_manager = $queue_manager;
		$this->calculator    = $calculator;
		$this->logger        = $logger;
	}

	public function register_hooks() {
		add_action( \MGMP_BATCH_HOOK, array( $this, 'process_batch' ) );
	}

	public static function schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( \MGMP_BATCH_HOOK ) ) {
				as_enqueue_async_action( \MGMP_BATCH_HOOK );
			}
		} elseif ( ! wp_next_scheduled( \MGMP_BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 5, \MGMP_BATCH_HOOK );
		}
	}

	public function process_batch() {
		$jobs = $this->queue_manager->claim_pending_jobs( 20 );
		if ( empty( $jobs ) ) {
			return;
		}

		foreach ( $jobs as $job ) {
			$this->process_job( $job );
		}

		if ( $this->queue_manager->has_pending_jobs() ) {
			self::schedule();
		}
	}

	private function process_job( $job ) {
		try {
			$parent_ids = array();
			$prices     = $this->calculator->calculate_job_prices( $job );

			foreach ( $prices as $variation_id => $price_data ) {
				$product = $price_data['product'];
				$price   = $price_data['price'];
				$sku     = $price_data['sku'];

				// Update the actual WooCommerce variation matched under the input SKU parent.
				$product->set_regular_price( wc_format_decimal( $price ) );
				$product->set_price( wc_format_decimal( $price ) );
				$product->save();

				$parent_id = $price_data['parent_id'];
				if ( $parent_id ) {
					$parent_ids[ $parent_id ] = $parent_id;
				}

				$this->logger->log( sprintf( 'Price updated. queue_id=%d input_sku=%s sku=%s product_id=%d concentration=%s volume=%d price=%d', absint( $job->id ), $job->base_sku, $sku, absint( $variation_id ), $price_data['concentration'], absint( $price_data['volume'] ), absint( $price ) ) );
			}

			if ( empty( $prices ) ) {
				$this->logger->log( sprintf( 'No variation prices calculated. queue_id=%d input_sku=%s', absint( $job->id ), $job->base_sku ) );
			}

			foreach ( $parent_ids as $parent_id ) {
				// WooCommerce API sync refreshes lookup tables, variation price indexes, transients, REST responses, and cart/catalog reads.
				do_action( 'woocommerce_variable_product_sync_data', $parent_id );
				\WC_Product_Variable::sync( $parent_id );
				wc_delete_product_transients( $parent_id );
				wc_update_product_lookup_tables( $parent_id );
			}

			$this->queue_manager->update_status( $job->id, 'done' );
		} catch ( \Throwable $throwable ) {
			$message = sprintf( 'Job failed. queue_id=%d base_sku=%s error=%s', absint( $job->id ), $job->base_sku, $throwable->getMessage() );
			$this->queue_manager->update_status( $job->id, 'failed', $message );
			$this->logger->log( $message );
		}
	}
}
