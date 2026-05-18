<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Recommendation_Engine {
	private const DB_VERSION = '1.0.0';
	private const OPTION_DB_VERSION = 'atrishawoo_reco_db_version';
	private const OPTION_BACKFILL_STATE = 'atrishawoo_reco_backfill_state';
	private const COOKIE_RECENT = 'atrishawoo_reco_recent';

	public static function init(): void {
		add_action('wp', [self::class, 'track_product_view']);
		add_action('woocommerce_order_status_processing', [self::class, 'process_order'], 10, 1);
		add_action('woocommerce_order_status_completed', [self::class, 'process_order'], 10, 1);
		add_action('atrishawoo_reco_backfill', [self::class, 'cron_backfill']);

		add_shortcode('atrishawoo_recommendations', [self::class, 'shortcode']);

		add_action('wp', static function () {
			if (!apply_filters('atrishawoo_reco_auto_insert', true)) {
				return;
			}

			if (!function_exists('is_product') || !is_product()) {
				return;
			}

			$hook = (string) apply_filters('atrishawoo_reco_hook', 'woocommerce_after_single_product_summary');
			$priority = (int) apply_filters('atrishawoo_reco_priority', 15);
			add_action($hook, [self::class, 'output_default_block'], $priority);
		}, 20);

		self::maybe_install();
		self::maybe_schedule_backfill();
	}

	public static function install(): void {
		global $wpdb;

		if (!function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();
		$view_table = self::table_view();
		$together_table = self::table_together();
		$after_table = self::table_after();

		dbDelta(
			"CREATE TABLE {$view_table} (
				product_id bigint(20) unsigned NOT NULL,
				related_id bigint(20) unsigned NOT NULL,
				score bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (product_id, related_id),
				KEY related_id (related_id)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$together_table} (
				product_id bigint(20) unsigned NOT NULL,
				related_id bigint(20) unsigned NOT NULL,
				score bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (product_id, related_id),
				KEY related_id (related_id)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$after_table} (
				product_id bigint(20) unsigned NOT NULL,
				related_id bigint(20) unsigned NOT NULL,
				score bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (product_id, related_id),
				KEY related_id (related_id)
			) {$collate};"
		);

		update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
		add_option(self::OPTION_BACKFILL_STATE, ['status' => 'pending', 'page' => 1], '', false);
	}

	public static function shortcode($atts): string {
		if (!apply_filters('atrishawoo_reco_enabled', true)) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'type' => (string) apply_filters('atrishawoo_reco_default_type', 'auto'),
				'limit' => (int) apply_filters('atrishawoo_reco_limit', 8),
				'columns' => (int) apply_filters('atrishawoo_reco_columns', 4),
				'title' => '',
				'product_id' => 0,
			],
			(array) $atts,
			'atrishawoo_recommendations'
		);

		$type = sanitize_key((string) ($atts['type'] ?? 'auto'));
		if (!in_array($type, ['auto', 'view', 'after', 'together'], true)) {
			$type = 'auto';
		}

		$limit = (int) ($atts['limit'] ?? 8);
		if ($limit < 1) {
			$limit = 1;
		}
		if ($limit > 24) {
			$limit = 24;
		}

		$columns = (int) ($atts['columns'] ?? 4);
		if ($columns < 1) {
			$columns = 1;
		}
		if ($columns > 6) {
			$columns = 6;
		}

		$product_id = (int) ($atts['product_id'] ?? 0);
		if ($product_id <= 0) {
			$product_id = get_the_ID() ? (int) get_the_ID() : 0;
		}

		$title = isset($atts['title']) ? sanitize_text_field((string) $atts['title']) : '';

		return self::render_block($product_id, $type, $limit, $columns, $title);
	}

	public static function output_default_block(): void {
		if (!apply_filters('atrishawoo_reco_enabled', true)) {
			return;
		}

		$product_id = get_the_ID() ? (int) get_the_ID() : 0;
		if ($product_id <= 0) {
			return;
		}

		$type = (string) apply_filters('atrishawoo_reco_default_type', 'auto');
		$limit = (int) apply_filters('atrishawoo_reco_limit', 8);
		$columns = (int) apply_filters('atrishawoo_reco_columns', 4);

		$html = self::render_block($product_id, sanitize_key($type), $limit, $columns, '');
		if ($html === '') {
			return;
		}

		echo $html;
	}

	public static function track_product_view(): void {
		if (!apply_filters('atrishawoo_reco_enabled', true)) {
			return;
		}

		if (is_admin() || wp_doing_ajax()) {
			return;
		}

		if (!function_exists('is_product') || !is_product()) {
			return;
		}

		$product_id = get_queried_object_id() ? (int) get_queried_object_id() : 0;
		if ($product_id <= 0) {
			return;
		}

		$recent = self::get_recent_product_ids();
		$recent = array_values(array_filter(array_map('intval', $recent), static function (int $id) use ($product_id): bool {
			return $id > 0 && $id !== $product_id;
		}));

		$recent = array_slice($recent, 0, 5);
		foreach ($recent as $prev_id) {
			self::record_pair(self::table_view(), $product_id, $prev_id, 1);
			self::record_pair(self::table_view(), $prev_id, $product_id, 1);
		}

		array_unshift($recent, $product_id);
		$recent = array_values(array_unique($recent));
		$recent = array_slice($recent, 0, 8);
		self::set_recent_product_ids($recent);
	}

	public static function process_order($order_id): void {
		if (!apply_filters('atrishawoo_reco_enabled', true)) {
			return;
		}

		$order_id = (int) $order_id;
		if ($order_id <= 0) {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return;
		}

		$items = $order->get_items();
		$product_ids = self::extract_product_ids_from_order_items($items);
		if (!$product_ids) {
			return;
		}

		if ($order->get_meta('_atrishawoo_reco_together_done', true) !== 'yes') {
			self::process_bought_together($product_ids);
			$order->update_meta_data('_atrishawoo_reco_together_done', 'yes');
			$order->save();
		}

		if ($order->get_meta('_atrishawoo_reco_after_done', true) !== 'yes') {
			$prev_ids = self::find_previous_purchased_product_ids($order);
			if ($prev_ids) {
				self::process_bought_after($prev_ids, $product_ids);
			}
			$order->update_meta_data('_atrishawoo_reco_after_done', 'yes');
			$order->save();
		}
	}

	public static function cron_backfill(): void {
		if (!apply_filters('atrishawoo_reco_enable_backfill', true)) {
			self::unschedule_backfill();
			return;
		}

		if (!function_exists('wc_get_orders')) {
			return;
		}

		$state = get_option(self::OPTION_BACKFILL_STATE, []);
		if (!is_array($state)) {
			$state = [];
		}

		$status = isset($state['status']) ? (string) $state['status'] : 'pending';
		if ($status === 'done') {
			self::unschedule_backfill();
			return;
		}

		$page = isset($state['page']) ? (int) $state['page'] : 1;
		if ($page < 1) {
			$page = 1;
		}

		$limit = (int) apply_filters('atrishawoo_reco_backfill_batch_size', 20);
		if ($limit < 5) {
			$limit = 5;
		}
		if ($limit > 50) {
			$limit = 50;
		}

		$orders = wc_get_orders([
			'status' => ['processing', 'completed'],
			'limit' => $limit,
			'page' => $page,
			'orderby' => 'date',
			'order' => 'ASC',
			'return' => 'objects',
		]);

		if (!$orders) {
			update_option(self::OPTION_BACKFILL_STATE, ['status' => 'done', 'page' => $page], false);
			self::unschedule_backfill();
			return;
		}

		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				continue;
			}
			self::process_order((int) $order->get_id());
		}

		update_option(self::OPTION_BACKFILL_STATE, ['status' => 'pending', 'page' => $page + 1], false);
	}

	private static function maybe_install(): void {
		$installed = (string) get_option(self::OPTION_DB_VERSION, '');
		if ($installed === self::DB_VERSION) {
			return;
		}

		self::install();
	}

	private static function maybe_schedule_backfill(): void {
		$state = get_option(self::OPTION_BACKFILL_STATE, []);
		if (!is_array($state)) {
			$state = [];
		}

		$status = isset($state['status']) ? (string) $state['status'] : 'pending';
		if ($status === 'done') {
			self::unschedule_backfill();
			return;
		}

		if (!wp_next_scheduled('atrishawoo_reco_backfill')) {
			wp_schedule_event(time() + 60, 'hourly', 'atrishawoo_reco_backfill');
		}
	}

	private static function unschedule_backfill(): void {
		$timestamp = wp_next_scheduled('atrishawoo_reco_backfill');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'atrishawoo_reco_backfill');
		}
	}

	private static function render_block(int $product_id, string $type, int $limit, int $columns, string $title): string {
		if ($product_id <= 0) {
			return '';
		}

		if (!in_array($type, ['auto', 'view', 'after', 'together'], true)) {
			$type = 'auto';
		}

		$limit = max(1, min(24, $limit));
		$columns = max(1, min(6, $columns));

		$chosen_type = $type;
		$ids = [];

		if ($type === 'auto') {
			foreach (['together', 'after', 'view'] as $candidate) {
				$ids = self::get_recommendation_ids($product_id, $candidate, $limit);
				if ($ids) {
					$chosen_type = $candidate;
					break;
				}
			}
		} else {
			$ids = self::get_recommendation_ids($product_id, $type, $limit);
		}

		$ids = array_values(array_filter(array_map('intval', $ids), static function (int $id) use ($product_id): bool {
			return $id > 0 && $id !== $product_id;
		}));

		if (!$ids) {
			return '';
		}

		$label = self::default_title($chosen_type);
		$final_title = $title !== '' ? $title : (string) apply_filters('atrishawoo_reco_title', $label, $chosen_type, $product_id);
		$final_title = trim((string) $final_title);

		$query = new WP_Query([
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => count($ids),
			'post__in' => $ids,
			'orderby' => 'post__in',
			'no_found_rows' => true,
			'ignore_sticky_posts' => true,
		]);

		if (!$query->have_posts()) {
			wp_reset_postdata();
			return '';
		}

		$wrapper_class = 'atrishawoo-recommendations atrishawoo-recommendations--' . esc_attr($chosen_type);

		ob_start();
		echo '<section class="' . $wrapper_class . '">';
		if ($final_title !== '') {
			echo '<h2 class="atrishawoo-recommendations__title">' . esc_html($final_title) . '</h2>';
		}

		wc_set_loop_prop('columns', $columns);
		woocommerce_product_loop_start();
		while ($query->have_posts()) {
			$query->the_post();
			wc_get_template_part('content', 'product');
		}
		woocommerce_product_loop_end();
		wc_reset_loop();

		echo '</section>';
		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	private static function get_recommendation_ids(int $product_id, string $type, int $limit): array {
		$table = '';
		if ($type === 'view') {
			$table = self::table_view();
		} elseif ($type === 'after') {
			$table = self::table_after();
		} elseif ($type === 'together') {
			$table = self::table_together();
		}

		if ($table === '') {
			return [];
		}

		$min_score = (int) apply_filters('atrishawoo_reco_min_score', 1, $type, $product_id);
		if ($min_score < 1) {
			$min_score = 1;
		}

		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT related_id
				FROM {$table}
				WHERE product_id = %d AND score >= %d
				ORDER BY score DESC
				LIMIT %d",
				$product_id,
				$min_score,
				$limit
			)
		);

		if (!is_array($rows)) {
			return [];
		}

		return $rows;
	}

	private static function process_bought_together(array $product_ids): void {
		$product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids), static function (int $id): bool {
			return $id > 0;
		})));

		$count = count($product_ids);
		if ($count < 2) {
			return;
		}

		$table = self::table_together();
		for ($i = 0; $i < $count; $i++) {
			for ($j = $i + 1; $j < $count; $j++) {
				$a = (int) $product_ids[$i];
				$b = (int) $product_ids[$j];
				if ($a <= 0 || $b <= 0 || $a === $b) {
					continue;
				}
				self::record_pair($table, $a, $b, 1);
				self::record_pair($table, $b, $a, 1);
			}
		}
	}

	private static function process_bought_after(array $previous_product_ids, array $current_product_ids): void {
		$previous_product_ids = array_values(array_unique(array_filter(array_map('intval', $previous_product_ids), static function (int $id): bool {
			return $id > 0;
		})));
		$current_product_ids = array_values(array_unique(array_filter(array_map('intval', $current_product_ids), static function (int $id): bool {
			return $id > 0;
		})));

		if (!$previous_product_ids || !$current_product_ids) {
			return;
		}

		$table = self::table_after();
		foreach ($previous_product_ids as $prev_id) {
			foreach ($current_product_ids as $curr_id) {
				if ($prev_id === $curr_id) {
					continue;
				}
				self::record_pair($table, (int) $prev_id, (int) $curr_id, 1);
			}
		}
	}

	private static function record_pair(string $table, int $product_id, int $related_id, int $delta): void {
		if ($delta <= 0) {
			return;
		}

		$product_id = (int) $product_id;
		$related_id = (int) $related_id;
		if ($product_id <= 0 || $related_id <= 0 || $product_id === $related_id) {
			return;
		}

		global $wpdb;
		$now = current_time('mysql', 1);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, related_id, score, updated_at)
				VALUES (%d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = VALUES(updated_at)",
				$product_id,
				$related_id,
				$delta,
				$now
			)
		);
	}

	private static function extract_product_ids_from_order_items(array $items): array {
		$ids = [];
		foreach ($items as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			$product = $item->get_product();
			if (!$product instanceof WC_Product) {
				continue;
			}

			$id = (int) $product->get_id();
			if ($product->is_type('variation')) {
				$parent = (int) $product->get_parent_id();
				if ($parent > 0) {
					$id = $parent;
				}
			}

			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	private static function find_previous_purchased_product_ids(WC_Order $order): array {
		$customer_id = (int) $order->get_customer_id();
		$billing_email = (string) $order->get_billing_email();
		$date = $order->get_date_created();
		$before = $date ? $date->date('Y-m-d H:i:s') : '';

		$args = [
			'status' => ['processing', 'completed'],
			'limit' => (int) apply_filters('atrishawoo_reco_after_prev_orders_limit', 3, $order),
			'orderby' => 'date',
			'order' => 'DESC',
			'exclude' => [$order->get_id()],
			'return' => 'objects',
		];

		if ($before !== '') {
			$args['date_created'] = '<' . $before;
		}

		if ($customer_id > 0) {
			$args['customer_id'] = $customer_id;
		} elseif ($billing_email !== '') {
			$args['billing_email'] = $billing_email;
		} else {
			return [];
		}

		$prev_orders = wc_get_orders($args);
		if (!$prev_orders) {
			return [];
		}

		$ids = [];
		foreach ($prev_orders as $prev) {
			if (!$prev instanceof WC_Order) {
				continue;
			}
			$ids = array_merge($ids, self::extract_product_ids_from_order_items($prev->get_items()));
		}

		return array_values(array_unique($ids));
	}

	private static function get_recent_product_ids(): array {
		if (!isset($_COOKIE[self::COOKIE_RECENT])) {
			return [];
		}

		$raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_RECENT]));
		if ($raw === '') {
			return [];
		}

		$parts = array_filter(array_map('trim', explode(',', $raw)));
		$ids = [];
		foreach ($parts as $p) {
			$id = (int) $p;
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	private static function set_recent_product_ids(array $ids): void {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
			return $id > 0;
		})));
		$ids = array_slice($ids, 0, 12);

		$value = implode(',', $ids);
		$expire = time() + (int) apply_filters('atrishawoo_reco_recent_cookie_ttl', 2 * DAY_IN_SECONDS);

		if (function_exists('wc_setcookie')) {
			wc_setcookie(self::COOKIE_RECENT, $value, $expire);
			return;
		}

		setcookie(self::COOKIE_RECENT, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
	}

	private static function default_title(string $type): string {
		if ($type === 'together') {
			return 'محصولات خریداری‌شده باهم';
		}
		if ($type === 'after') {
			return 'محصولات پیشنهادی بر اساس خریدهای بعدی';
		}
		if ($type === 'view') {
			return 'محصولات پیشنهادی بر اساس بازدید';
		}
		return 'محصولات پیشنهادی';
	}

	private static function table_view(): string {
		global $wpdb;
		return $wpdb->prefix . 'atrishawoo_reco_view';
	}

	private static function table_together(): string {
		global $wpdb;
		return $wpdb->prefix . 'atrishawoo_reco_together';
	}

	private static function table_after(): string {
		global $wpdb;
		return $wpdb->prefix . 'atrishawoo_reco_after';
	}
}

