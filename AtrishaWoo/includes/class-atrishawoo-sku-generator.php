<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Sku_Generator {
	private const OPTION_JOB = 'atrishawoo_sku_job';

	public static function get_job(): array {
		$job = get_option(self::OPTION_JOB, []);
		return is_array($job) ? $job : [];
	}

	public static function clear_job(): void {
		delete_option(self::OPTION_JOB);
	}

	public static function start_job(array $args): array {
		$prefix = isset($args['prefix']) ? (string) $args['prefix'] : 'SKU_';
		$prefix = trim($prefix);
		if ($prefix === '') {
			$prefix = 'SKU_';
		}

		$padding = isset($args['padding']) ? (int) $args['padding'] : 5;
		if ($padding < 0) {
			$padding = 0;
		}
		if ($padding > 20) {
			$padding = 20;
		}

		$start_number = isset($args['start_number']) ? (int) $args['start_number'] : 1;
		if ($start_number < 1) {
			$start_number = 1;
		}

		$mode = isset($args['mode']) ? (string) $args['mode'] : 'missing';
		if (!in_array($mode, ['missing', 'all'], true)) {
			$mode = 'missing';
		}

		$batch_size = isset($args['batch_size']) ? (int) $args['batch_size'] : 200;
		if ($batch_size < 20) {
			$batch_size = 20;
		}
		if ($batch_size > 500) {
			$batch_size = 500;
		}

		$job = [
			'status' => 'running',
			'prefix' => $prefix,
			'padding' => $padding,
			'next_number' => $start_number,
			'mode' => $mode,
			'batch_size' => $batch_size,
			'last_processed_id' => 0,
			'processed' => 0,
			'updated' => 0,
			'skipped' => 0,
			'started_at' => time(),
			'finished_at' => null,
		];

		update_option(self::OPTION_JOB, $job, false);

		return $job;
	}

	public static function process_batch(): array {
		$job = self::get_job();
		if (($job['status'] ?? '') !== 'running') {
			return $job;
		}

		global $wpdb;

		$batch_size = isset($job['batch_size']) ? (int) $job['batch_size'] : 200;
		$last_id = isset($job['last_processed_id']) ? (int) $job['last_processed_id'] : 0;

		$posts_table = $wpdb->posts;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$posts_table}
				WHERE post_type IN ('product','product_variation')
					AND post_status NOT IN ('trash','auto-draft')
					AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$last_id,
				$batch_size
			)
		);

		if (!is_array($ids)) {
			$ids = [];
		}

		$prefix = (string) ($job['prefix'] ?? 'SKU_');
		$padding = (int) ($job['padding'] ?? 5);
		$mode = (string) ($job['mode'] ?? 'missing');

		$processed_in_batch = 0;
		$updated_in_batch = 0;
		$skipped_in_batch = 0;

		$max_id_in_batch = $last_id;

		foreach ($ids as $product_id) {
			$product_id = (int) $product_id;
			if ($product_id <= 0) {
				continue;
			}

			$max_id_in_batch = max($max_id_in_batch, $product_id);
			$processed_in_batch++;

			$product = wc_get_product($product_id);
			if (!$product) {
				$skipped_in_batch++;
				continue;
			}

			$current_sku = (string) $product->get_sku();
			if ($mode === 'missing' && $current_sku !== '') {
				$skipped_in_batch++;
				continue;
			}

			$number = (int) ($job['next_number'] ?? 1);
			if ($number < 1) {
				$number = 1;
			}

			$attempts = 0;
			while (true) {
				$attempts++;
				if ($attempts > 5000) {
					$skipped_in_batch++;
					break;
				}

				$sku = $prefix . self::pad_number($number, $padding);
				$existing_id = wc_get_product_id_by_sku($sku);
				if (!$existing_id || (int) $existing_id === $product_id) {
					try {
						$product->set_sku($sku);
						$product->save();
						$job['next_number'] = $number + 1;
						$updated_in_batch++;
					} catch (Throwable $e) {
						$skipped_in_batch++;
					}
					break;
				}

				$number++;
			}
		}

		$job['last_processed_id'] = $max_id_in_batch;
		$job['processed'] = (int) ($job['processed'] ?? 0) + $processed_in_batch;
		$job['updated'] = (int) ($job['updated'] ?? 0) + $updated_in_batch;
		$job['skipped'] = (int) ($job['skipped'] ?? 0) + $skipped_in_batch;

		if (count($ids) < $batch_size) {
			$job['status'] = 'done';
			$job['finished_at'] = time();
		}

		update_option(self::OPTION_JOB, $job, false);

		return $job;
	}

	private static function pad_number(int $number, int $padding): string {
		if ($padding <= 0) {
			return (string) $number;
		}

		return str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
	}
}

