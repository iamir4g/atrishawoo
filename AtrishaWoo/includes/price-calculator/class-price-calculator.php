<?php
/**
 * Price calculator.
 *
 * @package MohasebeGheymatMahsulat
 */

namespace AtrishaWoo\PriceCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates variation prices.
 */
class Price_Calculator {
	/**
	 * Central concentration pricing map. Keys are technical tiers; labels include
	 * the Persian WooCommerce values observed on real variations plus legacy codes.
	 *
	 * @var array<string,array{percent:float,labels:array<int,string>}>
	 */
	private $concentration_tiers = array(
		'EX'  => array(
			'percent' => 0.55,
			'labels'  => array( 'EX', 'اکسترا پرفیوم' ),
		),
		'EDP' => array(
			'percent' => 0.40,
			'labels'  => array( 'EDP', 'پرفیوم' ),
		),
		'EDT' => array(
			'percent' => 0.25,
			'labels'  => array( 'EDT', 'ادو تویلت' ),
		),
		'EDC' => array(
			'percent' => 0.15,
			'labels'  => array( 'EDC', 'ادکلن' ),
		),
	);

	/**
	 * Calculate all existing WooCommerce variation prices for a job input SKU.
	 *
	 * @param object $job Queue job.
	 * @return array<int,array{product:\WC_Product_Variation,sku:string,price:int,parent_id:int,concentration:string,volume:int}>
	 */
	public function calculate_job_prices( $job ) {
		$variation = $this->resolve_variation_from_sku( $job->base_sku );
		if ( ! $variation ) {
			throw new \RuntimeException( sprintf( 'Input SKU not found or is not a variation: %s', (string) $job->base_sku ) );
		}

		$parent_id = $variation->get_parent_id();
		$parent    = $parent_id ? wc_get_product( $parent_id ) : false;
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			throw new \RuntimeException( sprintf( 'Parent variable product not found for SKU: %s', (string) $job->base_sku ) );
		}

		$prices = array();
		foreach ( $parent->get_children() as $child_id ) {
			$product = wc_get_product( $child_id );
			if ( ! $product || ! $product->is_type( 'variation' ) ) {
				continue;
			}

			$attributes      = $this->read_variation_attributes( $product );
			$concentration   = $this->extract_concentration( $attributes );
			$essence_percent = $this->map_concentration_to_percent( $concentration );
			$volume_label    = $this->extract_volume_label( $attributes );
			$volume          = $this->parse_volume( $volume_label );

			if ( null === $essence_percent || null === $volume ) {
				continue;
			}

			$gram_price_key       = 'gram_price_' . $volume;
			$prices[ $child_id ] = array(
				'product'       => $product,
				'sku'           => $product->get_sku(),
				'price'         => $this->calculate_price(
					(float) $volume,
					(float) $essence_percent,
					(float) ( $job->{$gram_price_key} ?? 0 ),
					(float) $job->fixative_price,
					(float) $job->bottle_price,
					(float) $job->packaging_price,
					(float) $job->shipping_cost,
					(float) $job->tax_percent
				),
				'parent_id'     => $parent_id,
				'concentration' => $concentration,
				'volume'        => $volume,
			);
		}

		return $prices;
	}

	/** Resolve the input SKU through WooCommerce CRUD helpers. */
	public function resolve_variation_from_sku( $sku ) {
		$product_id = wc_get_product_id_by_sku( wc_clean( (string) $sku ) );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( $product && $product->is_type( 'variation' ) ) {
			return $product;
		}

		return null;
	}

	/** Read variation attributes, preserving Persian labels and handling encoded meta keys. */
	private function read_variation_attributes( \WC_Product_Variation $variation ) {
		$attributes = array();
		foreach ( $variation->get_attributes() as $key => $value ) {
			$attributes[ rawurldecode( (string) $key ) ] = rawurldecode( (string) $value );
		}

		$meta_data = $variation->get_meta_data();
		foreach ( $meta_data as $meta ) {
			$data = $meta->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			if ( 0 !== strpos( $key, 'attribute_' ) ) {
				continue;
			}
			$attributes[ rawurldecode( substr( $key, 10 ) ) ] = rawurldecode( (string) ( $data['value'] ?? '' ) );
		}

		return $attributes;
	}

	private function extract_concentration( array $attributes ) {
		foreach ( $attributes as $key => $value ) {
			if ( false !== strpos( $key, 'غلظت' ) || $this->map_concentration_to_percent( $value ) !== null ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private function extract_volume_label( array $attributes ) {
		foreach ( $attributes as $key => $value ) {
			if ( false !== strpos( $key, 'حجم' ) || null !== $this->parse_volume( $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private function map_concentration_to_percent( $label ) {
		$label = $this->normalize_persian_text( $label );
		foreach ( $this->concentration_tiers as $tier ) {
			foreach ( $tier['labels'] as $candidate ) {
				if ( $label === $this->normalize_persian_text( $candidate ) ) {
					return (float) $tier['percent'];
				}
			}
		}
		return null;
	}

	private function parse_volume( $label ) {
		$label = $this->normalize_persian_text( $label );
		$label = strtr( $label, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
		return preg_match( '/(\d+(?:\.\d+)?)/u', $label, $matches ) ? (int) $matches[1] : null;
	}

	private function normalize_persian_text( $text ) {
		$text = rawurldecode( trim( (string) $text ) );
		$text = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $text );
		return preg_replace( '/\s+/u', ' ', $text );
	}

	/**
	 * Calculate rounded integer price.
	 */
	public function calculate_price( $volume, $essence_percent, $gram_price, $fixative_price, $bottle_price, $packaging_price, $shipping_cost, $tax_percent ) {
		$essence_cost = $volume * $essence_percent * $gram_price;
		$fix_cost     = $volume * ( 1 - $essence_percent ) * $fixative_price;
		$raw_cost     = $essence_cost + $fix_cost;
		$production   = $raw_cost * 3;
		$base_cost    = $production + $bottle_price + $packaging_price;
		$final_price  = ( $base_cost + $shipping_cost ) * ( 1 + ( $tax_percent / 100 ) );

		return (int) round( $final_price );
	}
}
