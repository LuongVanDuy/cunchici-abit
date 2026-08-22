<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Product_Mapper {
	/**
	 * Normalize one verified Abit listProductsforPartner row.
	 *
	 * IMPORTANT:
	 * The live Cún Chic payload verified on 2026-08-22 does NOT expose separate
	 * color/size fields in listProductsforPartner. Abit variants are still kept
	 * as independent rows/simple WooCommerce products, but color/size mapping is
	 * intentionally left blank until the actual source fields are confirmed.
	 */
	public static function map( array $row ) {
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
			'category'         => isset( $row['productcategory'] ) ? $row['productcategory'] : null,
			'modified_time'    => isset( $row['modifiedtime'] ) ? (string) $row['modifiedtime'] : '',
			'status'           => isset( $row['status'] ) ? $row['status'] : null,
			'images'           => self::images( $row ),
			'raw'              => $row,
		);
	}

	/**
	 * Stock field is intentionally NOT guessed.
	 *
	 * The listProductsforPartner payload only contains tonkho_toithieu and
	 * tonkho_toida, which are min/max thresholds rather than current quantity.
	 * We will enable this method after capturing one real row from
	 * listProductsWithStockforPartner.
	 */
	public static function stock_quantity( array $row ) {
		return null;
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
