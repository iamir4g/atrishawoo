<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Admin {
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
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		add_action('wp_ajax_atrishawoo_sku_start', [$this, 'ajax_sku_start']);
		add_action('wp_ajax_atrishawoo_sku_process', [$this, 'ajax_sku_process']);
		add_action('wp_ajax_atrishawoo_sku_clear', [$this, 'ajax_sku_clear']);
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'AtrishaWoo',
			'AtrishaWoo',
			'manage_woocommerce',
			'atrishawoo',
			[$this, 'render_page']
		);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'woocommerce_page_atrishawoo') {
			return;
		}

		wp_register_script('atrishawoo-admin', '', ['jquery'], ATRISHAWOO_VERSION, true);
		wp_enqueue_script('atrishawoo-admin');

		$job = AtrishaWoo_Sku_Generator::get_job();
		$payload = [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('atrishawoo_sku'),
			'job' => $job,
		];

		wp_add_inline_script('atrishawoo-admin', 'window.AtrishaWooSku=' . wp_json_encode($payload) . ';', 'before');
		wp_add_inline_script('atrishawoo-admin', $this->admin_js(), 'after');
	}

	public function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$job = AtrishaWoo_Sku_Generator::get_job();
		$prefix = isset($job['prefix']) ? (string) $job['prefix'] : 'SKU_';
		$padding = isset($job['padding']) ? (int) $job['padding'] : 5;
		$start_number = isset($job['next_number']) ? (int) $job['next_number'] : 1;
		$mode = isset($job['mode']) ? (string) $job['mode'] : 'missing';

		echo '<div class="wrap">';
		echo '<h1>AtrishaWoo</h1>';
		echo '<h2>تولید SKU یکتا و افزایشی</h2>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="atrishawoo-prefix">پیشوند SKU</label></th><td><input type="text" id="atrishawoo-prefix" class="regular-text" value="' . esc_attr($prefix) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-padding">تعداد صفرهای چپ</label></th><td><input type="number" id="atrishawoo-padding" min="0" max="20" value="' . esc_attr((string) $padding) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-start">شروع از شماره</label></th><td><input type="number" id="atrishawoo-start" min="1" value="' . esc_attr((string) $start_number) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-mode">حالت</label></th><td>';
		echo '<select id="atrishawoo-mode">';
		echo '<option value="missing"' . selected($mode, 'missing', false) . '>فقط محصولاتی که SKU ندارند</option>';
		echo '<option value="all"' . selected($mode, 'all', false) . '>بازنویسی SKU همه محصولات (ریسک‌دار)</option>';
		echo '</select>';
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<p>';
		echo '<button class="button button-primary" id="atrishawoo-start-btn">شروع</button> ';
		echo '<button class="button" id="atrishawoo-process-btn" disabled>ادامه</button> ';
		echo '<button class="button" id="atrishawoo-clear-btn">ریست وضعیت</button>';
		echo '</p>';

		echo '<div id="atrishawoo-status" style="margin-top:12px;"></div>';

		echo '</div>';
	}

	public function ajax_sku_start(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$args = [
			'prefix' => isset($_POST['prefix']) ? sanitize_text_field(wp_unslash($_POST['prefix'])) : 'SKU_',
			'padding' => isset($_POST['padding']) ? (int) $_POST['padding'] : 5,
			'start_number' => isset($_POST['start_number']) ? (int) $_POST['start_number'] : 1,
			'mode' => isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'missing',
		];

		$job = AtrishaWoo_Sku_Generator::start_job($args);
		wp_send_json_success(['job' => $job]);
	}

	public function ajax_sku_process(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$job = AtrishaWoo_Sku_Generator::process_batch();
		wp_send_json_success(['job' => $job]);
	}

	public function ajax_sku_clear(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		AtrishaWoo_Sku_Generator::clear_job();
		wp_send_json_success(['cleared' => true]);
	}

	private function admin_js(): string {
		return <<<'JS'
(function($){
	function jobText(job){
		if (!job || !job.status) {
			return 'آماده';
		}
		var parts = [];
		parts.push('وضعیت: ' + job.status);
		parts.push('پردازش‌شده: ' + (job.processed || 0));
		parts.push('به‌روزرسانی‌شده: ' + (job.updated || 0));
		parts.push('ردشده/بدون تغییر: ' + (job.skipped || 0));
		parts.push('شماره بعدی: ' + (job.next_number || 1));
		return parts.join(' | ');
	}

	function setStatus(text){
		$('#atrishawoo-status').text(text);
	}

	function setButtons(job){
		var canProcess = job && job.status === 'running';
		$('#atrishawoo-process-btn').prop('disabled', !canProcess);
	}

	function post(action, data){
		return $.post(window.AtrishaWooSku.ajaxUrl, $.extend({
			action: action,
			nonce: window.AtrishaWooSku.nonce
		}, data || {}));
	}

	function startJob(){
		var payload = {
			prefix: $('#atrishawoo-prefix').val(),
			padding: $('#atrishawoo-padding').val(),
			start_number: $('#atrishawoo-start').val(),
			mode: $('#atrishawoo-mode').val()
		};

		setStatus('در حال شروع...');
		return post('atrishawoo_sku_start', payload).then(function(resp){
			if (!resp || !resp.success) {
				setStatus('خطا در شروع');
				return;
			}
			window.AtrishaWooSku.job = resp.data.job;
			setStatus(jobText(window.AtrishaWooSku.job));
			setButtons(window.AtrishaWooSku.job);
		});
	}

	function processBatchLoop(){
		function step(){
			setStatus('در حال پردازش...');
			return post('atrishawoo_sku_process').then(function(resp){
				if (!resp || !resp.success) {
					setStatus('خطا در پردازش');
					return;
				}
				window.AtrishaWooSku.job = resp.data.job;
				setStatus(jobText(window.AtrishaWooSku.job));
				setButtons(window.AtrishaWooSku.job);

				if (window.AtrishaWooSku.job && window.AtrishaWooSku.job.status === 'running') {
					return new Promise(function(resolve){
						setTimeout(function(){
							resolve(step());
						}, 100);
					});
				}
			});
		}

		return step();
	}

	function clearJob(){
		setStatus('در حال ریست...');
		return post('atrishawoo_sku_clear').then(function(resp){
			if (!resp || !resp.success) {
				setStatus('خطا در ریست');
				return;
			}
			window.AtrishaWooSku.job = null;
			setStatus('ریست شد');
			setButtons(null);
		});
	}

	$(function(){
		setStatus(jobText(window.AtrishaWooSku.job));
		setButtons(window.AtrishaWooSku.job);

		$('#atrishawoo-start-btn').on('click', function(e){
			e.preventDefault();
			startJob().then(function(){
				return processBatchLoop();
			});
		});

		$('#atrishawoo-process-btn').on('click', function(e){
			e.preventDefault();
			processBatchLoop();
		});

		$('#atrishawoo-clear-btn').on('click', function(e){
			e.preventDefault();
			clearJob();
		});
	});
})(jQuery);
JS;
	}
}
