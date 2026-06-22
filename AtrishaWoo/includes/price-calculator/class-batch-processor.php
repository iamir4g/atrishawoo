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
		$batch_size  = $this->get_batch_size();
		$jobs        = $this->queue_manager->claim_pending_jobs( $batch_size );
		if ( empty( $jobs ) ) {
			return;
		}

		$parent_ids = array();
		$this->begin_fast_updates();

		foreach ( $jobs as $job ) {
			$parent_ids = array_replace( $parent_ids, $this->process_job( $job, false ) );
		}

		$this->end_fast_updates( $parent_ids );

		if ( $this->queue_manager->has_pending_jobs() ) {
			self::schedule();
		}
	}

	/**
	 * @return array<int,int> Parent product IDs keyed by themselves.
	 */
	private function process_job( $job, $sync_parent = true ) {
		$parent_ids = array();

		try {
			$prices = $this->calculator->calculate_job_prices( $job );

			foreach ( $prices as $variation_id => $price_data ) {
				$price   = $price_data['price'];
				$sku     = $price_data['sku'];
				$parent_id = $price_data['parent_id'];

				$this->apply_variation_price( (int) $variation_id, $price );

				if ( $parent_id ) {
					$parent_ids[ $parent_id ] = $parent_id;
				}

				$this->logger->log( sprintf( 'Price updated. queue_id=%d input_sku=%s sku=%s product_id=%d concentration=%s volume=%d price=%d', absint( $job->id ), $job->base_sku, $sku, absint( $variation_id ), $price_data['concentration'], absint( $price_data['volume'] ), absint( $price ) ) );
			}

			if ( empty( $prices ) ) {
				$this->logger->log( sprintf( 'No variation prices calculated. queue_id=%d input_sku=%s', absint( $job->id ), $job->base_sku ) );
			}

			if ( $sync_parent ) {
				$this->sync_parent_products( $parent_ids );
			}

			$this->queue_manager->update_status( $job->id, 'done' );
		} catch ( \Throwable $throwable ) {
			$message = sprintf( 'Job failed. queue_id=%d base_sku=%s error=%s', absint( $job->id ), $job->base_sku, $throwable->getMessage() );
			$this->queue_manager->update_status( $job->id, 'failed', $message );
			$this->logger->log( $message );
		}

		return $parent_ids;
	}

	private function get_batch_size() {
		$size = defined( 'MGMP_BATCH_SIZE' ) ? (int) \MGMP_BATCH_SIZE : 50;
		return min( 100, max( 1, (int) apply_filters( 'mgmp_batch_size', $size ) ) );
	}

	private function begin_fast_updates() {
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( true );
		}
	}

	/**
	 * @param array<int,int> $parent_ids Parent product IDs keyed by themselves.
	 */
	private function end_fast_updates( array $parent_ids ) {
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( false );
		}
		wp_defer_term_counting( false );
		wp_suspend_cache_invalidation( false );
		$this->sync_parent_products( $parent_ids );
	}

	/**
	 * @param array<int,int> $parent_ids Parent product IDs keyed by themselves.
	 */
	private function sync_parent_products( array $parent_ids ) {
		foreach ( $parent_ids as $parent_id ) {
			\WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		}
	}

	private function apply_variation_price( $variation_id, $price ) {
		$formatted = wc_format_decimal( $price );
		update_post_meta( $variation_id, '_regular_price', $formatted );
		update_post_meta( $variation_id, '_price', $formatted );
		clean_post_cache( $variation_id );
		wc_delete_product_transients( $variation_id );
	}
}
