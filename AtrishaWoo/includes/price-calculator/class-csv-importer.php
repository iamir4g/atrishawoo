<?php
/**
 * CSV importer.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Streams CSV rows into queue jobs. */
class CSV_Importer {
	/** @var Queue_Manager */
	private $queue_manager;
	/** @var Logger */
	private $logger;
	/** @var array<int,string> */
	private $required_headers = array( 'BaseSKU', 'GramPrice_100', 'GramPrice_50', 'GramPrice_30', 'GramPrice_10', 'FixativePrice', 'BottlePrice', 'PackagingPrice', 'ShippingCost', 'TaxPercent' );

	public function __construct( Queue_Manager $queue_manager, Logger $logger ) {
		$this->queue_manager = $queue_manager;
		$this->logger        = $logger;
	}

	/** Import uploaded CSV by streaming it. */
	public function import( array $file ) {
		$stats = array( 'total_rows' => 0, 'imported_rows' => 0, 'invalid_rows' => 0, 'skipped_rows' => 0, 'duplicate_rows' => 0 );
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array_merge( $stats, array( 'imported' => 0, 'message' => __( 'Invalid uploaded file.', 'mohasebe-gheymat-mahsulat' ) ) );
		}

		$file_name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$file_type = wp_check_filetype( $file_name, array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $file_type['ext'] ) {
			return array_merge( $stats, array( 'imported' => 0, 'message' => __( 'Only CSV files are allowed.', 'mohasebe-gheymat-mahsulat' ) ) );
		}

		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array_merge( $stats, array( 'imported' => 0, 'message' => __( 'Could not open CSV file.', 'mohasebe-gheymat-mahsulat' ) ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! $this->headers_are_valid( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array_merge( $stats, array( 'imported' => 0, 'message' => __( 'CSV headers are invalid.', 'mohasebe-gheymat-mahsulat' ) ) );
		}

		$row_number = 1;
		$seen       = array();
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			++$row_number;
			if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
				++$stats['skipped_rows'];
				continue;
			}
			++$stats['total_rows'];

			$validation = $this->validate_row( $headers, $row );
			if ( is_wp_error( $validation ) ) {
				++$stats['invalid_rows'];
				$this->logger->log( sprintf( 'Invalid CSV row. row=%d reason=%s', $row_number, $validation->get_error_message() ) );
				continue;
			}

			$data = $validation;
			if ( isset( $seen[ $data['base_sku'] ] ) || $this->queue_manager->active_job_exists( $data['base_sku'] ) ) {
				++$stats['duplicate_rows'];
				$this->logger->log( sprintf( 'Skipped duplicate CSV row. row=%d base_sku=%s', $row_number, $data['base_sku'] ) );
				continue;
			}

			$seen[ $data['base_sku'] ] = true;
			if ( $this->queue_manager->insert_job( $data ) ) {
				++$stats['imported_rows'];
			} else {
				++$stats['skipped_rows'];
				$this->logger->log( sprintf( 'CSV row was not queued. row=%d base_sku=%s', $row_number, $data['base_sku'] ) );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$stats['imported'] = $stats['imported_rows'];
		$this->logger->log( sprintf( 'CSV import complete. total=%d imported=%d invalid=%d skipped=%d duplicates=%d', $stats['total_rows'], $stats['imported_rows'], $stats['invalid_rows'], $stats['skipped_rows'], $stats['duplicate_rows'] ) );

		return array_merge( $stats, array( 'message' => __( 'CSV imported successfully.', 'mohasebe-gheymat-mahsulat' ) ) );
	}

	private function headers_are_valid( $headers ) {
		return is_array( $headers ) && $this->required_headers === array_map( 'trim', $headers );
	}

	private function validate_row( array $headers, array $row ) {
		$row = array_pad( $row, count( $headers ), '' );
		$map = array_combine( $headers, $row );
		$sku = wc_clean( sanitize_text_field( trim( (string) ( $map['BaseSKU'] ?? '' ) ) ) );
		if ( '' === $sku || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,190}$/', $sku ) ) {
			return new \WP_Error( 'invalid_sku', __( 'Invalid or empty BaseSKU.', 'mohasebe-gheymat-mahsulat' ) );
		}

		$data = array( 'base_sku' => $sku );
		foreach ( array( 'GramPrice_100', 'GramPrice_50', 'GramPrice_30', 'GramPrice_10', 'FixativePrice', 'BottlePrice', 'PackagingPrice', 'ShippingCost', 'TaxPercent' ) as $header ) {
			$value = $this->parse_decimal( $map[ $header ] ?? '' );
			if ( null === $value ) {
				return new \WP_Error( 'invalid_numeric', sprintf( 'Invalid numeric value for %s.', $header ) );
			}
			if ( $value < 0 ) {
				return new \WP_Error( 'negative_value', sprintf( 'Negative value is not allowed for %s.', $header ) );
			}
			if ( 'TaxPercent' === $header && $value > 100 ) {
				return new \WP_Error( 'invalid_tax', __( 'TaxPercent must be between 0 and 100.', 'mohasebe-gheymat-mahsulat' ) );
			}
			$data[ $this->header_to_key( $header ) ] = $value;
		}

		return $data;
	}

	private function parse_decimal( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return null;
		}
		return (float) $value;
	}

	private function header_to_key( $header ) {
		return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', str_replace( 'GramPrice_', 'gram_price_', $header ) ) );
	}
}
