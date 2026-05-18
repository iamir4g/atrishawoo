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

		add_action('wp_ajax_atrishawoo_label_preview', [$this, 'ajax_label_preview']);
		add_action('wp_ajax_atrishawoo_label_save_settings', [$this, 'ajax_label_save_settings']);
		add_action('wp_ajax_atrishawoo_label_prepare_print', [$this, 'ajax_label_prepare_print']);
		add_action('wp_ajax_atrishawoo_label_orders', [$this, 'ajax_label_orders']);
		add_action('wp_ajax_atrishawoo_label_save_preset', [$this, 'ajax_label_save_preset']);
		add_action('wp_ajax_atrishawoo_label_delete_preset', [$this, 'ajax_label_delete_preset']);
		add_action('wp_ajax_atrishawoo_label_set_default_preset', [$this, 'ajax_label_set_default_preset']);

		add_action('admin_post_atrishawoo_label_print', [$this, 'admin_post_label_print']);
		add_action('admin_post_atrishawoo_label_print_post', [$this, 'admin_post_label_print_post']);

		add_filter('woocommerce_admin_order_actions', [$this, 'add_order_row_action'], 10, 2);
	}

	public function register_menu(): void {
		add_menu_page(
			'AtrishaWoo',
			'AtrishaWoo',
			'manage_woocommerce',
			'atrishawoo',
			[$this, 'render_page'],
			'dashicons-tag',
			56
		);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'toplevel_page_atrishawoo') {
			return;
		}

		wp_register_script('atrishawoo-admin', '', ['jquery'], ATRISHAWOO_VERSION, true);
		wp_enqueue_script('atrishawoo-admin');

		$job = AtrishaWoo_Sku_Generator::get_job();
		$label_settings = AtrishaWoo_Order_Label::get_settings();
		$label_presets = AtrishaWoo_Order_Label::get_presets();
		$label_default_preset = AtrishaWoo_Order_Label::get_default_preset_id();
		$payload = [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('atrishawoo_sku'),
			'printPostUrl' => admin_url('admin-post.php'),
			'printPostNonce' => wp_create_nonce('atrishawoo_label_print_post'),
			'job' => $job,
			'labelSettings' => $label_settings,
			'labelPresets' => $label_presets,
			'labelDefaultPreset' => $label_default_preset,
		];

		wp_add_inline_script('atrishawoo-admin', 'window.AtrishaWooSku=' . wp_json_encode($payload) . ';', 'before');
		wp_add_inline_script('atrishawoo-admin', $this->admin_js(), 'after');
	}

	public function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'sku';
		if (!in_array($active_tab, ['sku', 'label'], true)) {
			$active_tab = 'sku';
		}

		echo '<div class="wrap">';
		echo '<h1>AtrishaWoo</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ($active_tab === 'sku' ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=atrishawoo&tab=sku')) . '">SKU</a>';
		echo '<a class="nav-tab' . ($active_tab === 'label' ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=atrishawoo&tab=label')) . '">لیبل سفارش</a>';
		echo '</h2>';

		echo '<style>
			.atrishawoo-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
			@media (max-width: 960px){.atrishawoo-grid{grid-template-columns:1fr;}}
			.atrishawoo-card{background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:16px;}
			.atrishawoo-preview-wrap{background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;padding:12px;overflow:auto;}
		</style>';

		if ($active_tab === 'sku') {
			$job = AtrishaWoo_Sku_Generator::get_job();
			$prefix = isset($job['prefix']) ? (string) $job['prefix'] : 'SKU_';
			$padding = isset($job['padding']) ? (int) $job['padding'] : 5;
			$start_number = isset($job['next_number']) ? (int) $job['next_number'] : 1;
			$mode = isset($job['mode']) ? (string) $job['mode'] : 'missing';

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
			return;
		}

		$settings = AtrishaWoo_Order_Label::get_settings();
		$order_id_prefill = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
		$preset_id_prefill = isset($_GET['preset']) ? sanitize_key(wp_unslash($_GET['preset'])) : '';
		if ($preset_id_prefill !== '') {
			$preset_settings = AtrishaWoo_Order_Label::get_preset_settings($preset_id_prefill);
			if (is_array($preset_settings)) {
				$settings = $preset_settings;
			}
		}
		$placeholders = AtrishaWoo_Order_Label::available_placeholders();
		$presets = AtrishaWoo_Order_Label::get_presets();
		$default_preset_id = AtrishaWoo_Order_Label::get_default_preset_id();

		echo '<h2>پرینت لیبل سفارش</h2>';
		echo '<div class="atrishawoo-grid">';

		echo '<div class="atrishawoo-card">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-preset">پریست</label></th><td>';
		echo '<select id="atrishawoo-label-preset" class="regular-text">';
		echo '<option value="">تنظیمات فعلی</option>';
		foreach ($presets as $pid => $preset) {
			$name = isset($preset['name']) ? (string) $preset['name'] : $pid;
			$selected = ($preset_id_prefill !== '' && $pid === $preset_id_prefill) ? ' selected' : '';
			echo '<option value="' . esc_attr($pid) . '"' . $selected . '>' . esc_html($name) . ($pid === $default_preset_id ? ' (پیش‌فرض)' : '') . '</option>';
		}
		echo '</select> ';
		echo '<button class="button" id="atrishawoo-label-set-default-btn">پیش‌فرض کن</button> ';
		echo '<button class="button" id="atrishawoo-label-delete-preset-btn">حذف</button>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-new-preset">نام پریست جدید</label></th><td>';
		echo '<input type="text" id="atrishawoo-label-new-preset" class="regular-text" placeholder="مثلاً پست پیشتاز ۸۰×۵۰" /> ';
		echo '<button class="button" id="atrishawoo-label-save-preset-btn">ذخیره به‌عنوان پریست</button>';
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">سفارش</th><td><input type="hidden" id="atrishawoo-label-order-id" value="' . esc_attr((string) ($order_id_prefill > 0 ? $order_id_prefill : '')) . '" /><span id="atrishawoo-label-order-selected" style="color:#646970;">از جدول «آخرین سفارش‌ها» انتخاب کنید</span></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-width">عرض (میلی‌متر)</label></th><td><input type="number" id="atrishawoo-label-width" min="20" max="200" value="' . esc_attr((string) $settings['width_mm']) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-height">ارتفاع (میلی‌متر)</label></th><td><input type="number" id="atrishawoo-label-height" min="10" max="200" value="' . esc_attr((string) $settings['height_mm']) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-padding">حاشیه داخلی (میلی‌متر)</label></th><td><input type="number" id="atrishawoo-label-padding" min="0" max="20" value="' . esc_attr((string) $settings['padding_mm']) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-font">سایز فونت (pt)</label></th><td><input type="number" id="atrishawoo-label-font" min="6" max="24" value="' . esc_attr((string) $settings['font_size_pt']) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-line-height">ارتفاع خط (Line height)</label></th><td><input type="number" id="atrishawoo-label-line-height" step="0.05" min="0.8" max="3" value="' . esc_attr((string) ($settings['line_height'] ?? 1.25)) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-font-weight">ضخامت</label></th><td><select id="atrishawoo-label-font-weight"><option value="normal"' . selected((string) ($settings['font_weight'] ?? 'normal'), 'normal', false) . '>Normal</option><option value="bold"' . selected((string) ($settings['font_weight'] ?? 'normal'), 'bold', false) . '>Bold</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-font-style">استایل</label></th><td><select id="atrishawoo-label-font-style"><option value="normal"' . selected((string) ($settings['font_style'] ?? 'normal'), 'normal', false) . '>Normal</option><option value="italic"' . selected((string) ($settings['font_style'] ?? 'normal'), 'italic', false) . '>Italic</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-word-spacing">فاصله بین کلمات (px)</label></th><td><input type="number" id="atrishawoo-label-word-spacing" step="0.5" min="0" max="20" value="' . esc_attr((string) ($settings['word_spacing_px'] ?? 0)) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-letter-spacing">فاصله بین حروف (px)</label></th><td><input type="number" id="atrishawoo-label-letter-spacing" step="0.5" min="-2" max="10" value="' . esc_attr((string) ($settings['letter_spacing_px'] ?? 0)) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-template">متن لیبل</label></th><td><textarea id="atrishawoo-label-template" rows="8" class="large-text code" placeholder="مثلاً:&#10;گیرنده&#10;{name} محترم&#10;{phonenumber}&#10;آدرس: {address}">' . esc_textarea((string) ($settings['template_text'] ?? '')) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="atrishawoo-label-items-override">اقلام سفارش (قابل ویرایش)</label></th><td><textarea id="atrishawoo-label-items-override" rows="4" class="large-text code" placeholder="اختیاری: اگر اینجا چیزی بنویسید، {items} از همین متن استفاده می‌کند.">' . esc_textarea((string) ($settings['items_override_text'] ?? '')) . '</textarea></td></tr>';
		echo '</tbody></table>';

		echo '<h3>Placeholderها</h3>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
		foreach ($placeholders as $token => $label) {
			echo '<button type="button" class="button atrishawoo-insert-token" data-token="' . esc_attr($token) . '">' . esc_html($token) . ' - ' . esc_html($label) . '</button>';
		}
		echo '</div>';

		echo '<p style="margin-top:12px;">';
		echo '<button class="button" id="atrishawoo-label-preview-btn">پیش‌نمایش</button> ';
		echo '<button class="button button-primary" id="atrishawoo-label-print-btn">چاپ</button> ';
		echo '<button class="button" id="atrishawoo-label-save-btn">ذخیره تنظیمات</button>';
		echo '</p>';
		echo '<div id="atrishawoo-label-msg" style="margin-top:10px;"></div>';
		echo '</div>';

		echo '<div class="atrishawoo-card">';
		echo '<h3>پیش‌نمایش</h3>';
		echo '<div class="atrishawoo-preview-wrap"><div id="atrishawoo-label-preview"></div></div>';
		echo '</div>';

		echo '</div>';

		echo '<div class="atrishawoo-card" style="margin-top:16px;">';
		echo '<h3>سفارش‌ها</h3>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px 0;">';
		echo '<input type="text" id="atrishawoo-orders-query" class="regular-text" placeholder="جستجو: تلفن / ایمیل / نام / شماره سفارش" />';
		echo '<button class="button" id="atrishawoo-orders-search-btn">جستجو</button>';
		echo '<button class="button" id="atrishawoo-orders-latest-btn">آخرین سفارش‌ها</button>';
		echo '<span id="atrishawoo-orders-msg" style="color:#646970;"></span>';
		echo '</div>';
		echo '<div id="atrishawoo-orders-table"></div>';
		echo '</div>';

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

	public function ajax_label_preview(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		try {
			$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
			$settings = isset($_POST['settings']) && is_array($_POST['settings']) ? (array) $_POST['settings'] : [];
			$settings = AtrishaWoo_Order_Label::sanitize_settings($this->unslash_deep($settings));

			$order = null;
			if ($order_id > 0) {
				$maybe_order = wc_get_order($order_id);
				$order = ($maybe_order instanceof WC_Order) ? $maybe_order : null;
			}

			$html = AtrishaWoo_Order_Label::render_label_preview_html($order, $settings);
			wp_send_json_success(['html' => $html]);
		} catch (Throwable $e) {
			$message = defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : 'preview_failed';
			wp_send_json_error(['message' => $message], 500);
		}
	}

	public function ajax_label_save_settings(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$settings = isset($_POST['settings']) && is_array($_POST['settings']) ? (array) $_POST['settings'] : [];
		$settings = AtrishaWoo_Order_Label::save_settings($this->unslash_deep($settings));
		wp_send_json_success(['settings' => $settings]);
	}

	public function ajax_label_prepare_print(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		try {
			$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
			$settings = isset($_POST['settings']) && is_array($_POST['settings']) ? (array) $_POST['settings'] : [];
			$settings = AtrishaWoo_Order_Label::sanitize_settings($this->unslash_deep($settings));

			$token = wp_generate_password(20, false, false);
			$user_id = (int) get_current_user_id();
			$key = 'atrishawoo_label_draft_' . $user_id . '_' . $token;

			set_transient($key, ['order_id' => $order_id, 'settings' => $settings], 5 * MINUTE_IN_SECONDS);

			$url = add_query_arg(
				[
					'action' => 'atrishawoo_label_print',
					'token' => $token,
					'nonce' => wp_create_nonce('atrishawoo_label_print'),
				],
				admin_url('admin-post.php')
			);

			wp_send_json_success(['printUrl' => $url]);
		} catch (Throwable $e) {
			$message = defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : 'print_prepare_failed';
			wp_send_json_error(['message' => $message], 500);
		}
	}

	public function ajax_label_orders(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
		$q = trim($q);
		$q = ltrim($q, "# \t\n\r\0\x0B");

		$args = [
			'limit' => 20,
			'orderby' => 'date',
			'order' => 'DESC',
			'return' => 'objects',
		];

		if ($q !== '') {
			$args['limit'] = 50;
			if (ctype_digit($q)) {
				$args['include'] = [(int) $q];
				$args['limit'] = 1;
			} else {
				$args['meta_query'] = [
					'relation' => 'OR',
					[
						'key' => '_billing_phone',
						'value' => $q,
						'compare' => 'LIKE',
					],
					[
						'key' => '_billing_email',
						'value' => $q,
						'compare' => 'LIKE',
					],
					[
						'key' => '_billing_first_name',
						'value' => $q,
						'compare' => 'LIKE',
					],
					[
						'key' => '_billing_last_name',
						'value' => $q,
						'compare' => 'LIKE',
					],
					[
						'key' => '_shipping_first_name',
						'value' => $q,
						'compare' => 'LIKE',
					],
					[
						'key' => '_shipping_last_name',
						'value' => $q,
						'compare' => 'LIKE',
					],
				];
			}
		}

		try {
			$orders = wc_get_orders($args);
		} catch (Throwable $e) {
			$message = defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : 'orders_failed';
			wp_send_json_error(['message' => $message], 500);
		}

		$out = [];
		if (is_array($orders)) {
			foreach ($orders as $order) {
				if (!(is_object($order) && is_a($order, 'WC_Order'))) {
					continue;
				}

				$oid = (int) $order->get_id();
				$date = $order->get_date_created();
				$date_text = $date ? $date->date_i18n('Y-m-d H:i') : '';
				$name = trim((string) $order->get_formatted_shipping_full_name());
				if ($name === '') {
					$name = trim((string) $order->get_formatted_billing_full_name());
				}
				$status = wc_get_order_status_name($order->get_status());
				$total = $order->get_formatted_order_total();

				$out[] = [
					'id' => $oid,
					'date' => $date_text,
					'name' => $name,
					'status' => $status,
					'total' => wp_strip_all_tags((string) $total),
				];
			}
		}

		wp_send_json_success(['orders' => $out]);
	}

	public function admin_post_label_print(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'atrishawoo_label_print')) {
			wp_die('Invalid nonce');
		}

		$token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
		if ($token === '') {
			wp_die('Missing token');
		}

		$user_id = (int) get_current_user_id();
		$key = 'atrishawoo_label_draft_' . $user_id . '_' . $token;
		$draft = get_transient($key);
		if (!is_array($draft)) {
			wp_die('Draft expired');
		}

		delete_transient($key);

		$order_id = isset($draft['order_id']) ? (int) $draft['order_id'] : 0;
		$settings = isset($draft['settings']) && is_array($draft['settings']) ? $draft['settings'] : [];

		$order = null;
		if ($order_id > 0) {
			$maybe_order = wc_get_order($order_id);
			$order = ($maybe_order instanceof WC_Order) ? $maybe_order : null;
		}

		$html = AtrishaWoo_Order_Label::render_print_document_html($order, $settings);

		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		echo $html;
		exit;
	}

	public function admin_post_label_print_post(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'atrishawoo_label_print_post')) {
			wp_die('Invalid nonce');
		}

		$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
		$settings = isset($_POST['settings']) && is_array($_POST['settings']) ? (array) $_POST['settings'] : [];
		$settings = AtrishaWoo_Order_Label::sanitize_settings($this->unslash_deep($settings));

		$order = null;
		if ($order_id > 0) {
			$maybe_order = wc_get_order($order_id);
			$order = ($maybe_order instanceof WC_Order) ? $maybe_order : null;
		}

		$html = AtrishaWoo_Order_Label::render_print_document_html($order, $settings);

		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		echo $html;
		exit;
	}

	private function unslash_deep(array $value): array {
		return wp_unslash($value);
	}

	public function ajax_label_save_preset(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
		$settings = isset($_POST['settings']) && is_array($_POST['settings']) ? (array) $_POST['settings'] : [];
		$presets = AtrishaWoo_Order_Label::save_preset($name, $this->unslash_deep($settings));
		wp_send_json_success([
			'presets' => $presets,
			'defaultPreset' => AtrishaWoo_Order_Label::get_default_preset_id(),
		]);
	}

	public function ajax_label_delete_preset(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$preset_id = isset($_POST['preset_id']) ? sanitize_key(wp_unslash($_POST['preset_id'])) : '';
		$presets = AtrishaWoo_Order_Label::delete_preset($preset_id);
		wp_send_json_success([
			'presets' => $presets,
			'defaultPreset' => AtrishaWoo_Order_Label::get_default_preset_id(),
		]);
	}

	public function ajax_label_set_default_preset(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		check_ajax_referer('atrishawoo_sku', 'nonce');

		$preset_id = isset($_POST['preset_id']) ? sanitize_key(wp_unslash($_POST['preset_id'])) : '';
		AtrishaWoo_Order_Label::set_default_preset_id($preset_id);
		wp_send_json_success([
			'defaultPreset' => AtrishaWoo_Order_Label::get_default_preset_id(),
		]);
	}

	public function add_order_row_action(array $actions, WC_Order $order): array {
		if (!current_user_can('manage_woocommerce')) {
			return $actions;
		}

		$preset = AtrishaWoo_Order_Label::get_default_preset_id();
		$url = add_query_arg(
			[
				'page' => 'atrishawoo',
				'tab' => 'label',
				'order_id' => (int) $order->get_id(),
				'preset' => $preset,
			],
			admin_url('admin.php')
		);

		$actions['atrishawoo_label'] = [
			'url' => $url,
			'name' => 'AtrishaWoo Label',
			'action' => 'atrishawoo-label',
		];

		return $actions;
	}

	private function admin_js(): string {
		return <<<'JS'
(function($){
	function getQueryParam(name){
		try {
			var params = new URLSearchParams(window.location.search);
			return params.get(name);
		} catch (e) {
			return null;
		}
	}

	function getLabelSettingsFromForm(){
		return {
			width_mm: parseInt($('#atrishawoo-label-width').val(), 10),
			height_mm: parseInt($('#atrishawoo-label-height').val(), 10),
			padding_mm: parseInt($('#atrishawoo-label-padding').val(), 10),
			font_size_pt: parseInt($('#atrishawoo-label-font').val(), 10),
			line_height: parseFloat($('#atrishawoo-label-line-height').val()),
			font_weight: $('#atrishawoo-label-font-weight').val(),
			font_style: $('#atrishawoo-label-font-style').val(),
			word_spacing_px: parseFloat($('#atrishawoo-label-word-spacing').val()),
			letter_spacing_px: parseFloat($('#atrishawoo-label-letter-spacing').val()),
			template_text: $('#atrishawoo-label-template').val(),
			items_override_text: $('#atrishawoo-label-items-override').val()
		};
	}

	function setLabelMsg(text, type){
		var el = $('#atrishawoo-label-msg');
		if (!el.length) return;
		var color = type === 'error' ? '#b32d2e' : '#1d2327';
		el.css({color: color}).text(text || '');
	}

	function setOrdersMsg(text, type){
		var el = $('#atrishawoo-orders-msg');
		if (!el.length) return;
		var color = type === 'error' ? '#b32d2e' : '#646970';
		el.css({color: color}).text(text || '');
	}

	function escHtml(s){
		return $('<div/>').text(s || '').html();
	}

	function renderOrdersTable(orders){
		var container = $('#atrishawoo-orders-table');
		if (!container.length) return;
		if (!orders || !orders.length) {
			container.html('<div style="color:#646970;">سفارشی یافت نشد.</div>');
			return;
		}

		var html = '';
		html += '<table class="widefat striped" style="width:100%;">';
		html += '<thead><tr><th>سفارش</th><th>تاریخ</th><th>مشتری</th><th>وضعیت</th><th>مبلغ</th><th>عملیات</th></tr></thead><tbody>';
		for (var i=0;i<orders.length;i++){
			var o = orders[i] || {};
			var id = parseInt(o.id, 10) || 0;
			html += '<tr>';
			html += '<td>#' + escHtml(String(id)) + '</td>';
			html += '<td>' + escHtml(o.date || '') + '</td>';
			html += '<td>' + escHtml(o.name || '-') + '</td>';
			html += '<td>' + escHtml(o.status || '') + '</td>';
			html += '<td>' + escHtml(o.total || '') + '</td>';
			html += '<td><button class="button atrishawoo-load-order" data-order-id="' + escHtml(String(id)) + '">بارگذاری</button> <button class="button button-primary atrishawoo-print-order" data-order-id="' + escHtml(String(id)) + '">چاپ</button></td>';
			html += '</tr>';
		}
		html += '</tbody></table>';
		container.html(html);
	}

	function loadOrders(query){
		setOrdersMsg('در حال دریافت سفارش‌ها...');
		return post('atrishawoo_label_orders', {q: query || ''}).then(function(resp){
			if (!resp || !resp.success || !resp.data) {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در دریافت سفارش‌ها';
				setOrdersMsg(msg, 'error');
				renderOrdersTable([]);
				return;
			}
			setOrdersMsg('');
			renderOrdersTable(resp.data.orders || []);
		});
	}

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

	function labelPreview(){
		var settings = getLabelSettingsFromForm();
		var orderId = parseInt($('#atrishawoo-label-order-id').val(), 10) || 0;

		setLabelMsg('در حال ساخت پیش‌نمایش...');
		return post('atrishawoo_label_preview', {order_id: orderId, settings: settings}).then(function(resp){
			if (!resp || !resp.success) {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در پیش‌نمایش';
				setLabelMsg(msg, 'error');
				return;
			}
			$('#atrishawoo-label-preview').html(resp.data.html || '');
			setLabelMsg('آماده');
		});
	}

	function setLabelFormSettings(settings){
		if (!settings) return;
		$('#atrishawoo-label-width').val(settings.width_mm);
		$('#atrishawoo-label-height').val(settings.height_mm);
		$('#atrishawoo-label-padding').val(settings.padding_mm);
		$('#atrishawoo-label-font').val(settings.font_size_pt);
		$('#atrishawoo-label-line-height').val(settings.line_height);
		$('#atrishawoo-label-font-weight').val(settings.font_weight || 'normal');
		$('#atrishawoo-label-font-style').val(settings.font_style || 'normal');
		$('#atrishawoo-label-word-spacing').val(settings.word_spacing_px);
		$('#atrishawoo-label-letter-spacing').val(settings.letter_spacing_px);
		$('#atrishawoo-label-template').val(settings.template_text || '');
		$('#atrishawoo-label-items-override').val(settings.items_override_text || '');
	}

	function rebuildPresetSelect(){
		var select = $('#atrishawoo-label-preset');
		if (!select.length) return;
		var current = select.val() || '';
		select.empty();
		select.append($('<option/>').attr('value','').text('تنظیمات فعلی'));

		var presets = window.AtrishaWooSku.labelPresets || {};
		var defaultId = window.AtrishaWooSku.labelDefaultPreset || '';
		Object.keys(presets).forEach(function(id){
			var p = presets[id] || {};
			var name = p.name || id;
			if (id === defaultId) {
				name += ' (پیش‌فرض)';
			}
			var opt = $('<option/>').attr('value', id).text(name);
			select.append(opt);
		});
		select.val(current);
	}

	function labelSave(){
		var settings = getLabelSettingsFromForm();
		setLabelMsg('در حال ذخیره...');
		return post('atrishawoo_label_save_settings', {settings: settings}).then(function(resp){
			if (!resp || !resp.success) {
				setLabelMsg('خطا در ذخیره', 'error');
				return;
			}
			setLabelMsg('ذخیره شد');
		});
	}

	function labelSavePreset(){
		var name = ($('#atrishawoo-label-new-preset').val() || '').trim();
		if (!name) {
			setLabelMsg('نام پریست را وارد کنید', 'error');
			return;
		}
		var settings = getLabelSettingsFromForm();
		setLabelMsg('در حال ذخیره پریست...');
		return post('atrishawoo_label_save_preset', {name: name, settings: settings}).then(function(resp){
			if (!resp || !resp.success) {
				setLabelMsg('خطا در ذخیره پریست', 'error');
				return;
			}
			window.AtrishaWooSku.labelPresets = resp.data.presets || {};
			window.AtrishaWooSku.labelDefaultPreset = resp.data.defaultPreset || '';
			$('#atrishawoo-label-new-preset').val('');
			rebuildPresetSelect();
			setLabelMsg('پریست ذخیره شد');
		});
	}

	function labelDeletePreset(){
		var presetId = $('#atrishawoo-label-preset').val() || '';
		if (!presetId) {
			setLabelMsg('یک پریست را انتخاب کنید', 'error');
			return;
		}
		setLabelMsg('در حال حذف...');
		return post('atrishawoo_label_delete_preset', {preset_id: presetId}).then(function(resp){
			if (!resp || !resp.success) {
				setLabelMsg('خطا در حذف پریست', 'error');
				return;
			}
			window.AtrishaWooSku.labelPresets = resp.data.presets || {};
			window.AtrishaWooSku.labelDefaultPreset = resp.data.defaultPreset || '';
			rebuildPresetSelect();
			$('#atrishawoo-label-preset').val('');
			setLabelMsg('حذف شد');
		});
	}

	function labelSetDefaultPreset(){
		var presetId = $('#atrishawoo-label-preset').val() || '';
		if (!presetId) {
			setLabelMsg('یک پریست را انتخاب کنید', 'error');
			return;
		}
		setLabelMsg('در حال ثبت پیش‌فرض...');
		return post('atrishawoo_label_set_default_preset', {preset_id: presetId}).then(function(resp){
			if (!resp || !resp.success) {
				setLabelMsg('خطا در ثبت پیش‌فرض', 'error');
				return;
			}
			window.AtrishaWooSku.labelDefaultPreset = resp.data.defaultPreset || '';
			rebuildPresetSelect();
			setLabelMsg('ثبت شد');
		});
	}

	function labelPrint(orderIdOverride){
		var settings = getLabelSettingsFromForm();
		var orderId = (typeof orderIdOverride === 'number') ? orderIdOverride : (parseInt($('#atrishawoo-label-order-id').val(), 10) || 0);

		if (!window.AtrishaWooSku || !window.AtrishaWooSku.printPostUrl || !window.AtrishaWooSku.printPostNonce) {
			setLabelMsg('تنظیمات چاپ آماده نیست', 'error');
			return;
		}

		var form = $('<form/>', {
			method: 'POST',
			action: window.AtrishaWooSku.printPostUrl,
			target: '_blank'
		});

		form.append($('<input/>', {type: 'hidden', name: 'action', value: 'atrishawoo_label_print_post'}));
		form.append($('<input/>', {type: 'hidden', name: 'nonce', value: window.AtrishaWooSku.printPostNonce}));
		form.append($('<input/>', {type: 'hidden', name: 'order_id', value: String(orderId)}));

		Object.keys(settings || {}).forEach(function(key){
			var val = settings[key];
			if (val === undefined || val === null) {
				val = '';
			}
			if (typeof val === 'number' && isNaN(val)) {
				val = '';
			}
			form.append($('<input/>', {type: 'hidden', name: 'settings[' + key + ']', value: String(val)}));
		});

		setLabelMsg('در حال باز کردن صفحه چاپ...');
		$('body').append(form);
		form.trigger('submit');
		form.remove();
	}

	$(function(){
		if ($('#atrishawoo-start-btn').length) {
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
		}

		if ($('#atrishawoo-label-preview-btn').length) {
			rebuildPresetSelect();

			var orderFromUrl = parseInt(getQueryParam('order_id'), 10) || 0;
			if (orderFromUrl) {
				$('#atrishawoo-label-order-id').val(orderFromUrl);
				$('#atrishawoo-label-order-selected').text('سفارش انتخاب‌شده: #' + orderFromUrl);
			}

			var presetFromUrl = getQueryParam('preset');
			if (presetFromUrl && window.AtrishaWooSku.labelPresets && window.AtrishaWooSku.labelPresets[presetFromUrl]) {
				$('#atrishawoo-label-preset').val(presetFromUrl);
				setLabelFormSettings(window.AtrishaWooSku.labelPresets[presetFromUrl].settings || {});
			}

			$('#atrishawoo-label-preset').on('change', function(){
				var pid = $(this).val() || '';
				if (!pid) return;
				var presets = window.AtrishaWooSku.labelPresets || {};
				if (presets[pid] && presets[pid].settings) {
					setLabelFormSettings(presets[pid].settings);
					labelPreview();
				}
			});

			$('#atrishawoo-label-save-preset-btn').on('click', function(e){
				e.preventDefault();
				labelSavePreset();
			});

			$('#atrishawoo-label-delete-preset-btn').on('click', function(e){
				e.preventDefault();
				labelDeletePreset();
			});

			$('#atrishawoo-label-set-default-btn').on('click', function(e){
				e.preventDefault();
				labelSetDefaultPreset();
			});

			$(document).on('click', '.atrishawoo-load-order', function(e){
				e.preventDefault();
				var oid = parseInt($(this).data('order-id'), 10) || 0;
				if (!oid) return;
				$('#atrishawoo-label-order-id').val(oid);
				$('#atrishawoo-label-order-selected').text('سفارش انتخاب‌شده: #' + oid);
				labelPreview();
			});

			$(document).on('click', '.atrishawoo-print-order', function(e){
				e.preventDefault();
				var oid = parseInt($(this).data('order-id'), 10) || 0;
				if (!oid) return;
				$('#atrishawoo-label-order-id').val(oid);
				$('#atrishawoo-label-order-selected').text('سفارش انتخاب‌شده: #' + oid);
				labelPrint(oid);
			});

			$(document).on('click', '.atrishawoo-insert-token', function(e){
				e.preventDefault();
				var token = $(this).data('token') || '';
				var ta = $('#atrishawoo-label-template');
				if (!ta.length || !token) return;
				var el = ta.get(0);
				var start = el.selectionStart || 0;
				var end = el.selectionEnd || 0;
				var val = ta.val() || '';
				ta.val(val.substring(0, start) + token + val.substring(end));
				var pos = start + token.length;
				el.setSelectionRange(pos, pos);
				ta.trigger('input');
			});

			if ($('#atrishawoo-orders-search-btn').length) {
				$('#atrishawoo-orders-search-btn').on('click', function(e){
					e.preventDefault();
					loadOrders(($('#atrishawoo-orders-query').val() || '').trim());
				});
				$('#atrishawoo-orders-latest-btn').on('click', function(e){
					e.preventDefault();
					$('#atrishawoo-orders-query').val('');
					loadOrders('');
				});
				$('#atrishawoo-orders-query').on('keydown', function(e){
					if (e.key === 'Enter') {
						e.preventDefault();
						loadOrders(($(this).val() || '').trim());
					}
				});
				loadOrders('');
			}

			$('#atrishawoo-label-preview-btn').on('click', function(e){
				e.preventDefault();
				labelPreview();
			});

			$('#atrishawoo-label-save-btn').on('click', function(e){
				e.preventDefault();
				labelSave();
			});

			$('#atrishawoo-label-print-btn').on('click', function(e){
				e.preventDefault();
				labelPrint();
			});

			var previewTimer = null;
			function schedulePreview(){
				if (previewTimer) {
					clearTimeout(previewTimer);
				}
				previewTimer = setTimeout(function(){
					labelPreview();
				}, 250);
			}

			$('#atrishawoo-label-width, #atrishawoo-label-height, #atrishawoo-label-padding, #atrishawoo-label-font, #atrishawoo-label-line-height, #atrishawoo-label-font-weight, #atrishawoo-label-font-style, #atrishawoo-label-word-spacing, #atrishawoo-label-letter-spacing, #atrishawoo-label-template').on('input change', schedulePreview);

			labelPreview();
		}
	});
})(jQuery);
JS;
	}
}
