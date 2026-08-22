<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Product_Mapper {
	/**
	 * Normalize one verified Abit listProductsforPartner row.
	 *
	 * The live payload verified on 2026-08-22 does not expose separate
	 * color/size fields. Abit variants remain independent simple products, but
	 * color/size are intentionally not guessed from name/SKU.
	 */
	public static function map( array $row ) {
		$category = isset( $row['productcategory'] ) ? $row['productcategory'] : null;

		return array(
			'abit_product_id'   => isset( $row['productid'] ) ? (string) $row['productid'] : '',
			'sku'               => isset( $row['productcode'] ) ? (string) $row['productcode'] : '',
			'name'              => isset( $row['productname'] ) ? (string) $row['productname'] : '',
			'price'             => self::number( isset( $row['unit_price'] ) ? $row['unit_price'] : 0 ),
			'daily_price'       => self::number( isset( $row['gia_daily'] ) ? $row['gia_daily'] : 0 ),
			'color'             => '',
			'size'              => '',
			'description'       => self::rich_text( isset( $row['description'] ) ? $row['description'] : '' ),
			'short_description' => self::rich_text( isset( $row['short_description'] ) ? $row['short_description'] : '' ),
			'brand'             => isset( $row['brandname'] ) ? (string) $row['brandname'] : '',
			'brand_id'          => isset( $row['brandid'] ) ? (string) $row['brandid'] : '',
			'barcode'           => isset( $row['barcode'] ) ? (string) $row['barcode'] : '',
			'original_code'     => isset( $row['ma_goc'] ) ? (string) $row['ma_goc'] : '',
			'accounting_code'   => isset( $row['ma_ke_toan'] ) ? (string) $row['ma_ke_toan'] : '',
			'category'          => $category,
			'category_label'    => self::category_label( $category ),
			'modified_time'     => isset( $row['modifiedtime'] ) ? (string) $row['modifiedtime'] : '',
			'status'            => isset( $row['status'] ) ? $row['status'] : null,
			'images'            => self::images( $row ),
			'raw'               => $row,
		);
	}

	/**
	 * Current-stock field is intentionally not guessed until a real
	 * listProductsWithStockforPartner row is captured from the shop.
	 */
	public static function stock_quantity( array $row ) {
		return null;
	}

	public static function category_names( $value ) {
		$names = array();

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' === $trimmed ) {
				return array();
			}
			$decoded = json_decode( $trimmed, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return self::category_names( $decoded );
			}
			return array( $trimmed );
		}

		if ( is_numeric( $value ) ) {
			return array();
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'categoryname', 'productcategoryname', 'name', 'title', 'label' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && '' !== trim( (string) $value[ $key ] ) ) {
					$names[] = trim( (string) $value[ $key ] );
				}
			}

			if ( ! $names ) {
				foreach ( $value as $child ) {
					if ( is_array( $child ) || is_string( $child ) ) {
						$names = array_merge( $names, self::category_names( $child ) );
					}
				}
			}
		}

		$names = array_map( 'sanitize_text_field', $names );
		$names = array_values( array_unique( array_filter( $names ) ) );
		return $names;
	}

	private static function category_label( $value ) {
		$names = self::category_names( $value );
		return $names ? implode( ' / ', $names ) : '';
	}

	private static function number( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		$value = preg_replace( '/[^0-9.\-]/', '', (string) $value );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Abit mixes three content styles in the same fields:
	 * - proper HTML;
	 * - plain text with CR/LF;
	 * - plain one-line marketplace text where headings/bullets lost line breaks.
	 *
	 * Preserve structural HTML as-is. For plain text, normalize existing CR/LF
	 * and recover only conservative marketplace structure (known section
	 * headings, repeated " - " bullets, first hashtag block), then let wpautop()
	 * produce safe paragraphs/line breaks.
	 */
	private static function rich_text( $value ) {
		$text = is_scalar( $value ) ? (string) $value : '';
		if ( '' === trim( $text ) ) {
			return '';
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$has_structural_html = (bool) preg_match( '/<(?:p|br|ul|ol|li|div|h[1-6]|table|blockquote)\b/i', $text );
		if ( ! $has_structural_html ) {
			$text = self::structure_plain_text( $text );
		}

		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = wp_kses_post( $text );

		return wpautop( $text );
	}

	/**
	 * Recover line structure from one-line marketplace descriptions without
	 * trying to rewrite normal prose. This is intentionally conservative.
	 */
	private static function structure_plain_text( $text ) {
		$headings = array(
			'THÔNG TIN SẢN PHẨM',
			'THÔNG TIN CHI TIẾT',
			'MÔ TẢ SẢN PHẨM',
			'HƯỚNG DẪN SỬ DỤNG',
			'HƯỚNG DẪN BẢO QUẢN',
			'CAM KẾT',
			'CHÚ Ý',
			'LƯU Ý',
			'CHÍNH SÁCH ĐỔI TRẢ',
			'CHÍNH SÁCH BẢO HÀNH',
		);

		$heading_pattern = '/(^|[.!?]\s+|\n+)(' . implode( '|', array_map( function ( $heading ) {
			return preg_quote( $heading, '/' );
		}, $headings ) ) . ')\s*:?\s*(?=-|\p{L}|\p{N}|\[)/iu';

		$has_heading = (bool) preg_match( $heading_pattern, $text );
		$text = preg_replace_callback(
			$heading_pattern,
			function ( $matches ) {
				$prefix = isset( $matches[1] ) ? rtrim( $matches[1] ) : '';
				$prefix = '' !== $prefix ? $prefix . "\n\n" : '';
				return $prefix . trim( $matches[2] ) . ":\n\n";
			},
			$text
		);

		// A repeated spaced dash is a strong list signal. If a known section
		// heading exists, even one dash immediately after it is treated as a bullet.
		$spaced_dash_count = substr_count( $text, ' - ' );
		if ( $has_heading || $spaced_dash_count >= 2 ) {
			$text = preg_replace( '/[ \t]+-\s+(?=[\p{L}\p{N}\[])/u', "\n- ", $text );
		}

		// Marketplace hashtags are metadata-like content; keep them together but
		// visually separate the block from the preceding description.
		$text = preg_replace( '/\s+(?=#\S)/u', "\n\n", $text, 1 );

		return trim( $text );
	}

	/**
	 * Verified live format:
	 *
	 * imagename = JSON string such as
	 * [{"id":1,"isDefault":true,"imgSrc":"https://cf.shopee.vn/file/..."}]
	 *
	 * All active products tested had imagename. default_image is optional.
	 */
	private static function images( array $row ) {
		$default_url = '';
		if ( isset( $row['default_image'] ) && is_scalar( $row['default_image'] ) ) {
			$default_url = self::image_url( $row['default_image'] );
		}

		$raw = isset( $row['imagename'] ) ? $row['imagename'] : array();
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( '' === $trimmed ) {
				$raw = array();
			} else {
				$decoded = json_decode( $trimmed, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					$raw = $decoded;
				} else {
					// Be tolerant if Abit ever returns one direct URL instead of JSON.
					$raw = array( $trimmed );
				}
			}
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = array();
		$others   = array();
		foreach ( $raw as $image ) {
			$url        = '';
			$is_default = false;

			if ( is_string( $image ) ) {
				$url = self::image_url( $image );
			} elseif ( is_array( $image ) ) {
				foreach ( array( 'imgSrc', 'url', 'src', 'imageUrl', 'image' ) as $key ) {
					if ( isset( $image[ $key ] ) && is_scalar( $image[ $key ] ) ) {
						$url = self::image_url( $image[ $key ] );
						if ( $url ) {
							break;
						}
					}
				}
				$is_default = ! empty( $image['isDefault'] ) || ! empty( $image['is_default'] );
			}

			if ( ! $url ) {
				continue;
			}
			if ( $is_default ) {
				$defaults[] = $url;
			} else {
				$others[] = $url;
			}
		}

		$images = array_merge( $default_url ? array( $default_url ) : array(), $defaults, $others );
		return array_values( array_unique( array_filter( $images ) ) );
	}

	private static function image_url( $value ) {
		$url = trim( (string) $value );
		if ( '' === $url ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}
