<?php
/**
 * Admin page.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce admin UI.
 */
class Admin_Page {
	/** @var Queue_Manager */
	private $queue_manager;

	/** @var CSV_Importer */
	private $csv_importer;

	/** @var Logger */
	private $logger;

	/**
	 * Admin screen hook returned by add_menu_page()/add_submenu_page().
	 *
	 * @var string
	 */
	private $page_hook = '';

	public function __construct( Queue_Manager $queue_manager, CSV_Importer $csv_importer, Logger $logger ) {
		$this->queue_manager = $queue_manager;
		$this->csv_importer  = $csv_importer;
		$this->logger        = $logger;
	}

	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mgmp_upload_csv', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_mgmp_start_processing', array( $this, 'handle_start_processing' ) );
		add_action( 'wp_ajax_mgmp_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_mgmp_save_pricing', array( $this, 'ajax_save_pricing' ) );
		add_action( 'wp_ajax_mgmp_enqueue_sku', array( $this, 'ajax_enqueue_sku' ) );
		add_action( 'wp_ajax_mgmp_logs', array( $this, 'ajax_logs' ) );
		add_action( 'wp_ajax_mgmp_start_batch', array( $this, 'ajax_start_batch' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( ! \AtrishaWoo_Admin::is_admin_screen( $hook ) ) {
			return;
		}

		$tab = 'sku';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'atrishawoo'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'atrishawoo-label' === $page ) {
			$tab = 'label';
		} elseif ( 'atrishawoo-price' === $page ) {
			$tab = 'price';
		} elseif ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}

		\AtrishaWoo_Price_Calculator::enqueue_mui_assets();

		if ( 'price' !== $tab ) {
			wp_enqueue_style( 'atrishawoo-admin-mui', \ATRISHAWOO_URL . 'assets/admin-mui.css', array(), \ATRISHAWOO_VERSION );
			wp_enqueue_script(
				'atrishawoo-admin-shell',
				\ATRISHAWOO_URL . 'assets/admin-shell.js',
				array( 'atrishawoo-react', 'atrishawoo-react-dom', 'atrishawoo-emotion-react', 'atrishawoo-emotion-styled', 'atrishawoo-mui' ),
				\ATRISHAWOO_VERSION,
				true
			);
			wp_add_inline_script(
				'atrishawoo-admin-shell',
				'window.AtrishaWooShell=' . wp_json_encode(
					array(
						'tabs'   => $this->get_shell_tabs(),
						'active' => $tab,
					)
				) . ';',
				'before'
			);
			return;
		}

		if ( file_exists( \MGMP_ASSETS_DIR . 'admin.css' ) ) {
			wp_enqueue_style( 'mgmp-admin', \MGMP_ASSETS_URL . 'admin.css', array(), \MGMP_VERSION );
		}

		if ( ! file_exists( \MGMP_ASSETS_DIR . 'admin.js' ) ) {
			return;
		}

		wp_enqueue_style( 'atrishawoo-admin-mui', \ATRISHAWOO_URL . 'assets/admin-mui.css', array(), \ATRISHAWOO_VERSION );
		wp_enqueue_script(
			'atrishawoo-admin-shell',
			\ATRISHAWOO_URL . 'assets/admin-shell.js',
			array( 'atrishawoo-react', 'atrishawoo-react-dom', 'atrishawoo-emotion-react', 'atrishawoo-emotion-styled', 'atrishawoo-mui' ),
			\ATRISHAWOO_VERSION,
			true
		);
		wp_add_inline_script(
			'atrishawoo-admin-shell',
			'window.AtrishaWooShell=' . wp_json_encode(
				array(
					'tabs'   => $this->get_shell_tabs(),
					'active' => 'price',
				)
			) . ';',
			'before'
		);

		wp_enqueue_script(
			'mgmp-admin',
			\MGMP_ASSETS_URL . 'admin.js',
			array( 'atrishawoo-mui' ),
			\MGMP_VERSION,
			true
		);
		$pricing = $this->get_pricing_defaults();

		wp_add_inline_script(
			'mgmp-admin',
			'window.mgmpAdmin=' . wp_json_encode(
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'adminPostUrl' => admin_url( 'admin-post.php' ),
					'nonce'        => wp_create_nonce( 'mgmp_admin_nonce' ),
					'uploadNonce'  => wp_create_nonce( 'mgmp_upload_csv' ),
					'processNonce' => wp_create_nonce( 'mgmp_start_processing' ),
					'pricing'      => $pricing,
					'config'       => array(
						'version'     => \MGMP_VERSION,
						'environment' => wp_get_environment_type(),
						'pricing'     => $pricing,
					),
				)
			) . ';',
			'before'
		);
	}

	public function handle_upload() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mohasebe-gheymat-mahsulat' ) );
		}
		if ( ! $this->is_woocommerce_active() ) {
			wp_die( esc_html__( 'WooCommerce must be active before importing CSV files.', 'mohasebe-gheymat-mahsulat' ) );
		}
		check_admin_referer( 'mgmp_upload_csv' );
		$result = $this->csv_importer->import( $_FILES['mgmp_csv'] ?? array() );
		$url    = add_query_arg(
			array(
				'page'        => 'atrishawoo-price',
				'mgmp_notice' => rawurlencode( (string) $result['message'] ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_start_processing() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mohasebe-gheymat-mahsulat' ) );
		}
		if ( ! $this->is_woocommerce_active() ) {
			wp_die( esc_html__( 'WooCommerce must be active before processing jobs.', 'mohasebe-gheymat-mahsulat' ) );
		}
		check_admin_referer( 'mgmp_start_processing' );
		Batch_Processor::schedule();
		$this->logger->log( 'Batch processing started.' );
		wp_safe_redirect( admin_url( 'admin.php?page=atrishawoo-price&mgmp_started=1' ) );
		exit;
	}

	public function ajax_status() {
		$this->verify_ajax_request();
		$counts    = $this->queue_manager->get_counts();
		$metrics   = $this->queue_manager->get_metrics();
		$total     = max( 0, (int) $counts['total'] );
		$processed = (int) $counts['done'] + (int) $counts['failed'];
		$percent   = $total ? round( ( $processed / $total ) * 100, 2 ) : 0;
		wp_send_json_success(
			array(
				'counts'       => $counts,
				'metrics'      => array_merge( $metrics, $this->get_catalog_metrics() ),
				'batch_status' => array(
					'total'       => $total,
					'processed'   => $processed,
					'failed'      => (int) $counts['failed'],
					'percent'     => $percent,
					'current_sku' => $this->get_current_sku(),
					'started_at'  => $this->get_started_at(),
				),
			)
		);
	}

	public function ajax_save_pricing() {
		$this->verify_ajax_request();
		$pricing = $this->sanitize_pricing( $_POST );
		update_option( 'mgmp_pricing_defaults', $pricing, false );
		wp_send_json_success( array( 'pricing' => $pricing, 'message' => __( 'Pricing inputs saved.', 'mohasebe-gheymat-mahsulat' ) ) );
	}

	public function ajax_enqueue_sku() {
		$this->verify_ajax_request();
		$sku     = wc_clean( sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ) );
		$pricing = $this->sanitize_pricing( $_POST );
		if ( '' === $sku ) {
			wp_send_json_error( array( 'message' => __( 'SKU is required.', 'mohasebe-gheymat-mahsulat' ) ), 400 );
		}
		$product_id = wc_get_product_id_by_sku( $sku );
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product was not found for this SKU.', 'mohasebe-gheymat-mahsulat' ) ), 404 );
		}
		$job_id = $this->queue_manager->insert_job( array_merge( $pricing, array( 'base_sku' => $sku ) ) );
		Batch_Processor::schedule();
		$this->logger->log( sprintf( 'SKU queued from React admin. sku=%s job_id=%s', $sku, (string) $job_id ) );
		wp_send_json_success(
			array(
				'job_id'             => $job_id,
				'parent_product_name'=> wp_strip_all_tags( $product->is_type( 'variation' ) && $product->get_parent_id() ? get_the_title( $product->get_parent_id() ) : $product->get_name() ),
				'variations_updated' => 0,
				'time_taken'         => 0,
				'message'            => __( 'SKU queued for batch calculation.', 'mohasebe-gheymat-mahsulat' ),
			)
		);
	}

	public function ajax_logs() {
		$this->verify_ajax_request();
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$level  = sanitize_key( wp_unslash( $_POST['level'] ?? 'all' ) );
		$page   = max( 1, absint( $_POST['page'] ?? 1 ) );
		$rows   = array_reverse( $this->logger->tail( 1000 ) );
		$rows   = array_values( array_filter( $rows, static function ( $line ) use ( $search, $level ) {
			$haystack = strtolower( $line );
			if ( $search && false === strpos( $haystack, strtolower( $search ) ) ) {
				return false;
			}
			if ( 'all' !== $level && false === strpos( $haystack, $level ) ) {
				return false;
			}
			return true;
		} ) );
		$per_page = 25;
		wp_send_json_success( array( 'rows' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ), 'total' => count( $rows ) ) );
	}

	public function ajax_start_batch() {
		$this->verify_ajax_request();
		Batch_Processor::schedule();
		$this->logger->log( 'Batch processing started from React admin.' );
		wp_send_json_success( array( 'message' => __( 'Batch worker scheduled.', 'mohasebe-gheymat-mahsulat' ) ) );
	}

	public function render_embedded() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to access this page.', 'mohasebe-gheymat-mahsulat' ) . '</p></div>';
			return;
		}

		if ( isset( $_GET['mgmp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sanitize_text_field( wp_unslash( $_GET['mgmp_notice'] ) );
			if ( '' !== $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			}
		}

		if ( ! $this->is_woocommerce_active() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'WooCommerce is inactive. Activate WooCommerce to enable price calculation, CSV import, and batch processing.', 'mohasebe-gheymat-mahsulat' ) . '</p></div>';
		}

		if ( ! file_exists( \MGMP_ASSETS_DIR . 'admin.js' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The admin app bundle is missing. Include assets/price-calculator/admin.js before deploying.', 'mohasebe-gheymat-mahsulat' ) . '</p></div>';
			return;
		}
		?>
		<div class="atrishawoo-price-tab mgmp-wrap">
			<div id="mgmp-admin-root" aria-label="Price Calculator admin dashboard">
				<div class="notice notice-info inline mgmp-js-fallback">
					<p><?php echo esc_html__( 'Loading the Price Calculator admin app. If this message remains visible, JavaScript or one of the admin app dependencies did not load.', 'mohasebe-gheymat-mahsulat' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	private function get_shell_tabs() {
		return array(
			array(
				'id'    => 'sku',
				'label' => 'تولید SKU',
				'url'   => admin_url( 'admin.php?page=atrishawoo' ),
			),
			array(
				'id'    => 'label',
				'label' => 'لیبل سفارش',
				'url'   => admin_url( 'admin.php?page=atrishawoo-label' ),
			),
			array(
				'id'    => 'price',
				'label' => 'محاسبه قیمت',
				'url'   => admin_url( 'admin.php?page=atrishawoo-price' ),
			),
		);
	}

	private function verify_ajax_request() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'mohasebe-gheymat-mahsulat' ) ), 403 );
		}
		if ( ! $this->is_woocommerce_active() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce must be active to use this tool.', 'mohasebe-gheymat-mahsulat' ) ), 400 );
		}
		check_ajax_referer( 'mgmp_admin_nonce', 'nonce' );
	}

	private function get_capability() {
		return $this->is_woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}

	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	private function get_pricing_defaults() {
		$defaults = array( 'gram_price_100' => 0, 'gram_price_50' => 0, 'gram_price_30' => 0, 'gram_price_10' => 0, 'fixative_price' => 0, 'bottle_price' => 0, 'packaging_price' => 0, 'shipping_cost' => 0, 'tax_percent' => 0 );
		$saved    = get_option( 'mgmp_pricing_defaults', array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	private function sanitize_pricing( $data ) {
		$pricing = $this->get_pricing_defaults();
		foreach ( array_keys( $pricing ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$pricing[ $key ] = (float) wc_format_decimal( wp_unslash( $data[ $key ] ) );
			}
		}
		return $pricing;
	}

	private function get_catalog_metrics() {
		return array(
			'total_products'   => wp_count_posts( 'product' )->publish ?? 0,
			'total_variations' => wp_count_posts( 'product_variation' )->publish ?? 0,
		);
	}

	private function get_current_sku() {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT base_sku FROM ' . Installer::table_name() . ' WHERE status = %s ORDER BY started_at DESC LIMIT 1', 'processing' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function get_started_at() {
		global $wpdb;
		return (string) $wpdb->get_var( 'SELECT started_at FROM ' . Installer::table_name() . ' WHERE started_at IS NOT NULL ORDER BY started_at DESC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
