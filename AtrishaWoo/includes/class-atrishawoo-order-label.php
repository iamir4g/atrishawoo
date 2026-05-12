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

		$css = "
			@page { margin: 0; }
			html, body { margin: 0; padding: 0; }
			body { direction: rtl; font-family: Tahoma, Arial, sans-serif; }
			.atrishawoo-label { width: {$width}mm; height: {$height}mm; padding: {$padding}mm; box-sizing: border-box; overflow: hidden; }
			.atrishawoo-label * { box-sizing: border-box; }
			.atrishawoo-label .atrishawoo-line { font-size: {$font_size}pt; line-height: 1.25; margin: 0 0 1.5mm 0; white-space: pre-wrap; word-break: break-word; }
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
			$text = self::apply_template($template_text, $order);
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
		$style = 'width:' . $width . 'mm;height:' . $height . 'mm;padding:' . $padding . 'mm;font-size:' . $font_size . 'pt;';
		$style .= 'box-sizing:border-box;overflow:hidden;direction:rtl;font-family:Tahoma,Arial,sans-serif;';
		if ($for_preview) {
			$style .= 'border:1px solid #ccd0d4;background:#fff;';
		}

		$html = '<div class="atrishawoo-label" style="' . esc_attr($style) . '">';

		foreach ($lines as $line) {
			$line = (string) $line;
			if ($line === '') {
				$html .= '<div class="atrishawoo-line" style="margin:0 0 1.5mm 0;white-space:pre-wrap;word-break:break-word;">&nbsp;</div>';
				continue;
			}
			$html .= '<div class="atrishawoo-line" style="margin:0 0 1.5mm 0;white-space:pre-wrap;word-break:break-word;">' . esc_html($line) . '</div>';
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
			'template_text' => "گیرنده\n{name} محترم\n{phonenumber}\nآدرس: {address}\n{city_state}\nکدپستی: {postcode}",
			'header_text' => '',
			'manual_text' => '',
			'fields' => ['recipient_name', 'phone', 'address', 'postcode', 'city_state'],
		];

		return array_merge($defaults, $settings);
	}

	private static function apply_template(string $template, $order): string {
		$order = (is_object($order) && is_a($order, 'WC_Order')) ? $order : null;
		$map = self::placeholder_map($order, $template);
		$text = strtr($template, $map);
		$text = preg_replace("/[ \\t]+/u", ' ', $text);
		$text = preg_replace("/\\n{3,}/u", "\n\n", $text);
		return is_string($text) ? trim($text) : '';
	}

	private static function placeholder_map($order, string $template): array {
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

		$items_text = '';
		if (strpos($template, '{items}') !== false) {
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
}
