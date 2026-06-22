<?php
/**
 * Logger.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Writes plugin log messages to a protected plugin uploads directory. */
class Logger {
	/** Log a message. */
	public function log( $message ) {
		$file = $this->get_log_file();
		if ( ! $file ) {
			return;
		}

		$line = sprintf( "[%s] %s\n", gmdate( 'Y-m-d H:i:s' ), sanitize_textarea_field( (string) $message ) );
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** Memory-efficient tail implementation for very large log files. */
	public function tail( $lines = 100 ) {
		$file  = $this->get_log_file();
		$lines = max( 1, min( 1000, absint( $lines ) ) );
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return array();
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}

		$buffer      = '';
		$chunk_size  = 4096;
		$position    = -1;
		$line_breaks = 0;
		fseek( $handle, 0, SEEK_END );
		$file_size = ftell( $handle );

		while ( $file_size + $position >= 0 && $line_breaks <= $lines ) {
			$read_size = min( $chunk_size, $file_size + $position + 1 );
			$position -= $read_size;
			fseek( $handle, $position + 1, SEEK_END );
			$chunk       = fread( $handle, $read_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buffer      = $chunk . $buffer;
			$line_breaks = substr_count( $buffer, "\n" );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$rows = array_filter( explode( "\n", trim( $buffer ) ), 'strlen' );
		return array_slice( $rows, -$lines );
	}

	/** Get protected log file path and migrate the legacy uploads log if present. */
	public function get_log_file() {
		$dir = $this->get_log_dir();
		if ( '' === $dir ) {
			return '';
		}

		$file   = trailingslashit( $dir ) . 'perfume-price-log.txt';
		$legacy = $this->get_legacy_log_file();
		if ( $legacy && file_exists( $legacy ) && ! file_exists( $file ) ) {
			rename( $legacy, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}

		return $file;
	}

	private function get_log_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . 'mgmp-logs';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$this->protect_directory( $dir );
		return $dir;
	}

	private function protect_directory( $dir ) {
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return;
		}

		file_put_contents( trailingslashit( $real_dir ) . '.htaccess', "Deny from all\nOptions -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( trailingslashit( $real_dir ) . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private function get_legacy_log_file() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload_dir['basedir'] ) . 'perfume-price-log.txt';
	}
}
