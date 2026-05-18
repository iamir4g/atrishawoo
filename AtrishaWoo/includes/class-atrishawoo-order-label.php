<?php

if (!defined('ABSPATH')) {
	exit;
}

final class AtrishaWoo_Order_Label {
	private const OPTION_SETTINGS = 'atrishawoo_label_settings';
	private const OPTION_PRESETS = 'atrishawoo_label_presets';
	private const OPTION_DEFAULT_PRESET = 'atrishawoo_label_default_preset';

	public static function get_settings(): array {
		$settings = get_option(self::OPTION_SETTINGS, []);
		if (!is_array($settings)) {
			$settings = [];
		}

		return self::merge_defaults($settings);
	}

	public static function save_settings(array $settings): array {
		$settings = self::sanitize_settings($settings);
		update_option(self::OPTION_SETTINGS, $settings, false);
		return $settings;
	}

	public static function get_presets(): array {
		$presets = get_option(self::OPTION_PRESETS, []);
		if (!is_array($presets)) {
			return [];
		}

		$out = [];
		foreach ($presets as $id => $preset) {
			if (!is_string($id) || $id === '' || !is_array($preset)) {
				continue;
			}
			$name = isset($preset['name']) ? trim((string) $preset['name']) : '';
			$settings = isset($preset['settings']) && is_array($preset['settings']) ? $preset['settings'] : [];
			if ($name === '') {
				continue;
			}
			$out[$id] = [
				'name' => $name,
				'settings' => self::sanitize_settings($settings),
			];
		}

		return $out;
	}

	public static function get_default_preset_id(): string {
		$id = get_option(self::OPTION_DEFAULT_PRESET, '');
		return is_string($id) ? $id : '';
	}

	public static function set_default_preset_id(string $preset_id): void {
		$preset_id = sanitize_key($preset_id);
		if ($preset_id === '') {
			delete_option(self::OPTION_DEFAULT_PRESET);
			return;
		}
		update_option(self::OPTION_DEFAULT_PRESET, $preset_id, false);
	}

	public static function get_preset_settings(string $preset_id): ?array {
		$preset_id = sanitize_key($preset_id);
		if ($preset_id === '') {
			return null;
		}

		$presets = self::get_presets();
		if (!isset($presets[$preset_id])) {
			return null;
		}

		$preset = $presets[$preset_id];
		$settings = isset($preset['settings']) && is_array($preset['settings']) ? $preset['settings'] : null;
		return $settings ? self::sanitize_settings($settings) : null;
	}

	public static function save_preset(string $name, array $settings): array {
		$name = trim($name);
		if ($name === '') {
			return self::get_presets();
		}

		$presets = self::get_presets();
		$id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('preset_', true);
		$id = sanitize_key($id);
		if ($id === '') {
			$id = sanitize_key(uniqid('preset_', true));
		}

		$presets[$id] = [
			'name' => $name,
			'settings' => self::sanitize_settings($settings),
		];

		update_option(self::OPTION_PRESETS, $presets, false);

		if (self::get_default_preset_id() === '') {
			self::set_default_preset_id($id);
		}

		return $presets;
	}

	public static function delete_preset(string $preset_id): array {
		$preset_id = sanitize_key($preset_id);
		$presets = self::get_presets();
		if ($preset_id === '' || !isset($presets[$preset_id])) {
			return $presets;
		}

		unset($presets[$preset_id]);
		update_option(self::OPTION_PRESETS, $presets, false);

		if (self::get_default_preset_id() === $preset_id) {
			$first = array_key_first($presets);
			self::set_default_preset_id(is_string($first) ? $first : '');
		}

		return $presets;
	}

	public static function sanitize_settings(array $settings): array {
		$settings = self::merge_defaults($settings);

		$settings['width_mm'] = self::clamp_int((int) $settings['width_mm'], 20, 200);
		$settings['height_mm'] = self::clamp_int((int) $settings['height_mm'], 10, 200);
		$settings['padding_mm'] = self::clamp_int((int) $settings['padding_mm'], 0, 20);
		$settings['font_size_pt'] = self::clamp_int((int) $settings['font_size_pt'], 6, 24);
		$settings['line_height'] = self::clamp_float((float) $settings['line_height'], 0.8, 3.0);
		$settings['font_weight'] = isset($settings['font_weight']) ? (string) $settings['font_weight'] : 'normal';
		$settings['font_weight'] = $settings['font_weight'] === 'bold' ? 'bold' : 'normal';
		$settings['font_style'] = isset($settings['font_style']) ? (string) $settings['font_style'] : 'normal';
		$settings['font_style'] = $settings['font_style'] === 'italic' ? 'italic' : 'normal';
		$settings['word_spacing_px'] = self::clamp_float((float) ($settings['word_spacing_px'] ?? 0.0), 0.0, 20.0);
		$settings['letter_spacing_px'] = self::clamp_float((float) ($settings['letter_spacing_px'] ?? 0.0), -2.0, 10.0);

		$settings['items_override_text'] = isset($settings['items_override_text']) ? (string) $settings['items_override_text'] : '';
		$settings['items_override_text'] = trim(str_replace(["\r\n", "\r"], "\n", $settings['items_override_text']));

		$settings['template_text'] = isset($settings['template_text']) ? (string) $settings['template_text'] : '';
		$settings['template_text'] = trim(str_replace(["\r\n", "\r"], "\n", $settings['template_text']));

		$settings['header_text'] = isset($settings['header_text']) ? trim((string) $settings['header_text']) : '';
		$settings['manual_text'] = isset($settings['manual_text']) ? trim((string) $settings['manual_text']) : '';

		$fields = isset($settings['fields']) && is_array($settings['fields']) ? $settings['fields'] : [];
		$allowed_fields = array_keys(self::available_fields());
		$fields = array_values(array_unique(array_filter(array_map('strval', $fields), static function ($f) use ($allowed_fields) {
			return in_array($f, $allowed_fields, true);
		})));
		$settings['fields'] = $fields;

		return $settings;
	}

	public static function available_fields(): array {
		return [
			'recipient_name' => 'نام گیرنده',
			'phone' => 'تلفن',
			'address' => 'آدرس',
			'postcode' => 'کدپستی',
			'city_state' => 'شهر/استان',
			'order_date' => 'تاریخ سفارش',
			'items' => 'اقلام سفارش',
			'customer_note' => 'یادداشت مشتری',
		];
	}

	public static function available_placeholders(): array {
		return [
			'{name}' => 'نام گیرنده',
			'{phonenumber}' => 'تلفن',
			'{address}' => 'آدرس',
			'{postcode}' => 'کدپستی',
			'{city}' => 'شهر',
			'{state}' => 'استان',
			'{city_state}' => 'شهر/استان',
			'{orderid}' => 'شماره سفارش',
			'{orderdate}' => 'تاریخ سفارش',
			'{items}' => 'اقلام سفارش',
			'{items_sku}' => 'اقلام + SKU',
			'{productnames}' => 'نام محصولات (فهرست)',
			'{firstproduct}' => 'نام اولین محصول',
			'{skus}' => 'SKUها (فهرست)',
			'{firstsku}' => 'SKU اولین محصول',
			'{itemcount}' => 'تعداد ردیف کالا',
			'{totalqty}' => 'جمع تعداد اقلام',
			'{customernote}' => 'یادداشت مشتری',
			'{total}' => 'مبلغ کل',
		];
	}

	public static function render_label_preview_html($order, array $settings): string {
		$settings = self::sanitize_settings($settings);

		$order = (is_object($order) && is_a($order, 'WC_Order')) ? $order : null;
		$lines = self::build_lines($order, $settings);
		$label = self::label_box_html($lines, $settings, true);

		return $label;
	}

	public static function render_print_document_html($order, array $settings): string {
		$settings = self::sanitize_settings($settings);
		$order = (is_object($order) && is_a($order, 'WC_Order')) ? $order : null;
		$lines = self::build_lines($order, $settings);
		$label = self::label_box_html($lines, $settings, false);

		$width = (int) $settings['width_mm'];
		$height = (int) $settings['height_mm'];
		$padding = (int) $settings['padding_mm'];
		$font_size = (int) $settings['font_size_pt'];
		$line_height = (float) $settings['line_height'];
		$font_weight = (string) ($settings['font_weight'] ?? 'normal');
		$font_style = (string) ($settings['font_style'] ?? 'normal');
		$word_spacing = (float) ($settings['word_spacing_px'] ?? 0.0);
		$letter_spacing = (float) ($settings['letter_spacing_px'] ?? 0.0);

		$css = "
			@page { margin: 0; }
			html, body { margin: 0; padding: 0; }
			body { direction: rtl; font-family: Tahoma, Arial, sans-serif; }
			.atrishawoo-label { width: {$width}mm; height: {$height}mm; padding: {$padding}mm; box-sizing: border-box; overflow: hidden; }
			.atrishawoo-label * { box-sizing: border-box; }
			.atrishawoo-label .atrishawoo-line { font-size: {$font_size}pt; line-height: {$line_height}; font-weight: {$font_weight}; font-style: {$font_style}; word-spacing: {$word_spacing}px; letter-spacing: {$letter_spacing}px; margin: 0 0 1.5mm 0; white-space: pre-wrap; word-break: break-word; }
			.atrishawoo-label .atrishawoo-header { font-weight: 700; margin-bottom: 2mm; }
		";

		$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<title>AtrishaWoo Label</title>';
		$html .= '<style>' . $css . '</style>';
		$html .= '</head><body>';
		$html .= $label;
		$html .= '<script>window.addEventListener("load",function(){window.print();});</script>';
		$html .= '</body></html>';

		return $html;
	}

	private static function build_lines($order, array $settings): array {
		$order = (is_object($order) && is_a($order, 'WC_Order')) ? $order : null;
		$template_text = trim((string) ($settings['template_text'] ?? ''));
		$fields = isset($settings['fields']) && is_array($settings['fields']) ? $settings['fields'] : [];
		$manual_text = trim((string) ($settings['manual_text'] ?? ''));

		if ($template_text !== '') {
			$text = self::apply_template($template_text, $order, $settings);
			$raw_lines = preg_split("/\\r\\n|\\r|\\n/u", $text) ?: [];
			$lines = [];
			foreach ($raw_lines as $line) {
				$line = (string) $line;
				if ($line === '') {
					$lines[] = '';
					continue;
				}
				$lines[] = rtrim($line);
			}
			return $lines;
		}

		$lines = [];

		if ($manual_text !== '') {
			$manual_lines = preg_split("/\\r\\n|\\r|\\n/u", $manual_text) ?: [];
			foreach ($manual_lines as $ml) {
				$ml = trim((string) $ml);
				if ($ml !== '') {
					$lines[] = $ml;
				}
			}
		}

		if (!$order) {
			return $lines;
		}

		$shipping_name = trim((string) $order->get_formatted_shipping_full_name());
		$billing_name = trim((string) $order->get_formatted_billing_full_name());
		$recipient = $shipping_name !== '' ? $shipping_name : $billing_name;

		$phone = (string) $order->get_billing_phone();

		$address_1 = (string) $order->get_shipping_address_1();
		$address_2 = (string) $order->get_shipping_address_2();
		$city = (string) $order->get_shipping_city();
		$state = (string) $order->get_shipping_state();
		$postcode = (string) $order->get_shipping_postcode();

		if (trim($address_1) === '' && trim($address_2) === '' && trim($city) === '' && trim($state) === '' && trim($postcode) === '') {
			$address_1 = (string) $order->get_billing_address_1();
			$address_2 = (string) $order->get_billing_address_2();
			$city = (string) $order->get_billing_city();
			$state = (string) $order->get_billing_state();
			$postcode = (string) $order->get_billing_postcode();
		}

		$need_items = in_array('items', $fields, true);
		$items_text = '';
		if ($need_items) {
			$items = [];
			foreach ($order->get_items() as $item) {
				$name = (string) $item->get_name();
				$qty = (int) $item->get_quantity();
				$items[] = $name . ' × ' . $qty;
			}
			if ($items) {
				$items_text = implode(' | ', $items);
			}
		}

		foreach ($fields as $field) {
			switch ($field) {
				case 'recipient_name':
					if (trim($recipient) !== '') {
						$lines[] = $recipient;
					}
					break;
				case 'phone':
					if (trim($phone) !== '') {
						$lines[] = $phone;
					}
					break;
				case 'address':
					$addr = trim($address_1);
					if ($address_2 !== '') {
						$addr = $addr !== '' ? ($addr . ' - ' . trim($address_2)) : trim($address_2);
					}
					if ($addr !== '') {
						$lines[] = $addr;
					}
					break;
				case 'postcode':
					if (trim($postcode) !== '') {
						$lines[] = 'کدپستی: ' . trim($postcode);
					}
					break;
				case 'city_state':
					$cs = trim($city);
					$st = trim($state);
					if ($st !== '') {
						$cs = $cs !== '' ? ($cs . ' - ' . $st) : $st;
					}
					if ($cs !== '') {
						$lines[] = $cs;
					}
					break;
				case 'order_date':
					$date = $order->get_date_created();
					if ($date) {
						$lines[] = 'تاریخ: ' . $date->date_i18n('Y-m-d H:i');
					}
					break;
				case 'items':
					if ($items_text !== '') {
						$lines[] = $items_text;
					}
					break;
				case 'customer_note':
					$note = trim((string) $order->get_customer_note());
					if ($note !== '') {
						$lines[] = 'یادداشت: ' . $note;
					}
					break;
			}
		}

		return $lines;
	}

	private static function label_box_html(array $lines, array $settings, bool $for_preview): string {
		$width = (int) $settings['width_mm'];
		$height = (int) $settings['height_mm'];
		$padding = (int) $settings['padding_mm'];
		$font_size = (int) $settings['font_size_pt'];
		$line_height = (float) $settings['line_height'];
		$font_weight = (string) ($settings['font_weight'] ?? 'normal');
		$font_style = (string) ($settings['font_style'] ?? 'normal');
		$word_spacing = (float) ($settings['word_spacing_px'] ?? 0.0);
		$letter_spacing = (float) ($settings['letter_spacing_px'] ?? 0.0);
		$style = 'width:' . $width . 'mm;height:' . $height . 'mm;padding:' . $padding . 'mm;font-size:' . $font_size . 'pt;';
		$style .= 'box-sizing:border-box;overflow:hidden;direction:rtl;font-family:Tahoma,Arial,sans-serif;';
		if ($for_preview) {
			$style .= 'border:1px solid #ccd0d4;background:#fff;';
		}

		$html = '<div class="atrishawoo-label" style="' . esc_attr($style) . '">';

		foreach ($lines as $line) {
			$line = (string) $line;
			$line_style = 'line-height:' . $line_height . ';font-weight:' . $font_weight . ';font-style:' . $font_style . ';word-spacing:' . $word_spacing . 'px;letter-spacing:' . $letter_spacing . 'px;margin:0 0 1.5mm 0;white-space:pre-wrap;word-break:break-word;';
			if ($line === '') {
				$html .= '<div class="atrishawoo-line" style="' . esc_attr($line_style) . '">&nbsp;</div>';
				continue;
			}
			$html .= '<div class="atrishawoo-line" style="' . esc_attr($line_style) . '">' . esc_html($line) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	private static function merge_defaults(array $settings): array {
		$defaults = [
			'width_mm' => 80,
			'height_mm' => 50,
			'padding_mm' => 3,
			'font_size_pt' => 11,
			'line_height' => 1.25,
			'font_weight' => 'normal',
			'font_style' => 'normal',
			'word_spacing_px' => 0,
			'letter_spacing_px' => 0,
			'template_text' => "گیرنده\n{name} محترم\n{phonenumber}\nآدرس: {address}\n{city_state}\nکدپستی: {postcode}",
			'header_text' => '',
			'manual_text' => '',
			'fields' => ['recipient_name', 'phone', 'address', 'postcode', 'city_state'],
		];

		return array_merge($defaults, $settings);
	}

	private static function apply_template(string $template, $order, array $settings): string {
		$order = (is_object($order) && is_a($order, 'WC_Order')) ? $order : null;
		$map = self::placeholder_map($order, $template, $settings);
		$text = strtr($template, $map);
		$text = preg_replace("/[ \\t]+/u", ' ', $text);
		$text = preg_replace("/\\n{3,}/u", "\n\n", $text);
		return is_string($text) ? trim($text) : '';
	}

	private static function placeholder_map($order, string $template, array $settings): array {
		if (!$order) {
			$empty = [];
			foreach (array_keys(self::available_placeholders()) as $token) {
				$empty[$token] = '';
			}
			$empty['{phone}'] = '';
			$empty['{note}'] = '';
			return $empty;
		}

		$shipping_name = trim((string) $order->get_formatted_shipping_full_name());
		$billing_name = trim((string) $order->get_formatted_billing_full_name());
		$recipient = $shipping_name !== '' ? $shipping_name : $billing_name;

		$phone = trim((string) $order->get_billing_phone());

		$address_1 = (string) $order->get_shipping_address_1();
		$address_2 = (string) $order->get_shipping_address_2();
		$city = (string) $order->get_shipping_city();
		$state = (string) $order->get_shipping_state();
		$postcode = (string) $order->get_shipping_postcode();

		if (trim($address_1) === '' && trim($address_2) === '' && trim($city) === '' && trim($state) === '' && trim($postcode) === '') {
			$address_1 = (string) $order->get_billing_address_1();
			$address_2 = (string) $order->get_billing_address_2();
			$city = (string) $order->get_billing_city();
			$state = (string) $order->get_billing_state();
			$postcode = (string) $order->get_billing_postcode();
		}

		$address = trim((string) $address_1);
		$address_2 = trim((string) $address_2);
		if ($address_2 !== '') {
			$address = $address !== '' ? ($address . ' - ' . $address_2) : $address_2;
		}

		$city_state = trim((string) $city);
		$state = trim((string) $state);
		if ($state !== '') {
			$city_state = $city_state !== '' ? ($city_state . ' - ' . $state) : $state;
		}

		$order_id = (string) $order->get_id();
		$date_obj = $order->get_date_created();
		$order_date = $date_obj ? $date_obj->date_i18n('Y-m-d H:i') : '';
		$note = trim((string) $order->get_customer_note());
		$total = (string) $order->get_formatted_order_total();

		$needs_items = (strpos($template, '{items}') !== false)
			|| (strpos($template, '{items_sku}') !== false)
			|| (strpos($template, '{productnames}') !== false)
			|| (strpos($template, '{firstproduct}') !== false)
			|| (strpos($template, '{skus}') !== false)
			|| (strpos($template, '{firstsku}') !== false)
			|| (strpos($template, '{itemcount}') !== false)
			|| (strpos($template, '{totalqty}') !== false);

		$items_text = '';
		$items_sku_text = '';
		$product_names_text = '';
		$first_product = '';
		$skus_text = '';
		$first_sku = '';
		$item_count = '';
		$total_qty = '';

		if ($needs_items) {
			$items = [];
			$items_sku = [];
			$product_names = [];
			$skus = [];
			$total_qty_int = 0;

			foreach ($order->get_items() as $item) {
				$name = trim((string) $item->get_name());
				$qty = (int) $item->get_quantity();
				$total_qty_int += max(0, $qty);

				$sku = '';
				$product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
				if (is_object($product) && method_exists($product, 'get_sku')) {
					$sku = trim((string) $product->get_sku());
				}
				if ($sku === '' && is_object($item) && method_exists($item, 'get_meta')) {
					$meta_sku = $item->get_meta('_sku', true);
					if (!is_string($meta_sku) || $meta_sku === '') {
						$meta_sku = $item->get_meta('sku', true);
					}
					$sku = trim((string) $meta_sku);
				}

				if ($name !== '') {
					$product_names[] = $name;
				}
				if ($name !== '' && $first_product === '') {
					$first_product = $name;
				}
				if ($sku !== '') {
					$skus[] = $sku;
				}
				if ($sku !== '' && $first_sku === '') {
					$first_sku = $sku;
				}

				if ($name !== '') {
					$items[] = $name . ' × ' . $qty;
				}
				if ($name !== '' && $sku !== '') {
					$items_sku[] = $name . ' × ' . $qty . ' | SKU: ' . $sku;
				} elseif ($name !== '') {
					$items_sku[] = $name . ' × ' . $qty;
				}
			}

			if ($items) {
				$items_text = implode(' | ', $items);
			}
			if ($items_sku) {
				$items_sku_text = implode(' | ', $items_sku);
			}

			$product_names = array_values(array_unique($product_names));
			if ($product_names) {
				$product_names_text = implode(' | ', $product_names);
			}

			$skus = array_values(array_unique($skus));
			if ($skus) {
				$skus_text = implode(' | ', $skus);
			}

			$item_count = (string) count($order->get_items());
			$total_qty = (string) $total_qty_int;
		}

		$items_override = isset($settings['items_override_text']) ? trim((string) $settings['items_override_text']) : '';
		if ($items_override !== '') {
			$items_text = $items_override;
		}

		return [
			'{name}' => $recipient,
			'{phonenumber}' => $phone,
			'{phone}' => $phone,
			'{address}' => $address,
			'{postcode}' => trim((string) $postcode),
			'{city}' => trim((string) $city),
			'{state}' => $state,
			'{city_state}' => $city_state,
			'{orderid}' => $order_id,
			'{orderdate}' => $order_date,
			'{items}' => $items_text,
			'{items_sku}' => $items_sku_text,
			'{productnames}' => $product_names_text,
			'{firstproduct}' => $first_product,
			'{skus}' => $skus_text,
			'{firstsku}' => $first_sku,
			'{itemcount}' => $item_count,
			'{totalqty}' => $total_qty,
			'{customernote}' => $note,
			'{note}' => $note,
			'{total}' => wp_strip_all_tags($total),
		];
	}

	private static function clamp_int(int $value, int $min, int $max): int {
		if ($value < $min) {
			return $min;
		}
		if ($value > $max) {
			return $max;
		}
		return $value;
	}

	private static function clamp_float(float $value, float $min, float $max): float {
		if ($value < $min) {
			return $min;
		}
		if ($value > $max) {
			return $max;
		}
		return $value;
	}
}
