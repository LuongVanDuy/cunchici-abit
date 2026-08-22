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
			'abit_product_id'  => isset( $row['productid'] ) ? (string) $row['productid'] : '',
			'sku'              => isset( $row['productcode'] ) ? (string) $row['productcode'] : '',
			'name'             => isset( $row['productname'] ) ? (string) $row['productname'] : '',
			'price'            => self::number( isset( $row['unit_price'] ) ? $row['unit_price'] : 0 ),
			'daily_price'      => self::number( isset( $row['gia_daily'] ) ? $row['gia_daily'] : 0 ),
			'color'            => '',
			'size'             => '',
			'description'      => isset( $row['description'] ) ? (string) $row['description'] : '',
			'short_description'=> isset( $row['short_description'] ) ? (string) $row['short_description'] : '',
			'brand'            => isset( $row['brandname'] ) ? (string) $row['brandname'] : '',
			'brand_id'         => isset( $row['brandid'] ) ? (string) $row['brandid'] : '',
			'barcode'          => isset( $row['barcode'] ) ? (string) $row['barcode'] : '',
			'original_code'    => isset( $row['ma_goc'] ) ? (string) $row['ma_goc'] : '',
			'accounting_code'  => isset( $row['ma_ke_toan'] ) ? (string) $row['ma_ke_toan'] : '',
			'category'         => $category,
			'category_label'   => self::category_label( $category ),
			'modified_time'    => isset( $row['modifiedtime'] ) ? (string) $row['modifiedtime'] : '',
			'status'           => isset( $row['status'] ) ? $row['status'] : null,
			'images'           => self::images( $row ),
			'raw'              => $row,
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

	private static function images( array $row ) {
		$images = array();
		$raw    = isset( $row['imagename'] ) ? $row['imagename'] : array();

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $raw ) ) {
			foreach ( $raw as $image ) {
				$url = '';
				if ( is_string( $image ) ) {
					$url = $image;
				} elseif ( is_array( $image ) ) {
					foreach ( array( 'imgSrc', 'url', 'src' ) as $key ) {
						if ( isset( $image[ $key ] ) && '' !== trim( (string) $image[ $key ] ) ) {
							$url = $image[ $key ];
							break;
						}
					}
				}
				$url = esc_url_raw( $url );
				if ( $url ) {
					$images[] = $url;
				}
			}
		}

		$default = isset( $row['default_image'] ) ? esc_url_raw( (string) $row['default_image'] ) : '';
		if ( $default ) {
			array_unshift( $images, $default );
		}

		return array_values( array_unique( $images ) );
	}
}
