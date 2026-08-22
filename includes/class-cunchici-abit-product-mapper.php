<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Product_Mapper {
	/**
	 * Normalize one Abit product row into a stable internal structure.
	 *
	 * Abit stores variants as independent rows. This mapper therefore returns
	 * one simple WooCommerce product per Abit row and never groups variants.
	 */
	public static function map( array $row ) {
		return array(
			'abit_product_id' => self::first( $row, array( 'productid', 'product_id', 'id' ) ),
			'sku'             => (string) self::first( $row, array( 'productcode', 'sku', 'code' ), '' ),
			'name'            => (string) self::first( $row, array( 'productname', 'name' ), '' ),
			'price'           => self::number( self::first( $row, array( 'unit_price', 'price', 'gia_daily' ), 0 ) ),
			'color'           => (string) self::first( $row, array( 'color', 'colorname', 'mausac', 'mau_sac', 'productcolor' ), '' ),
			'size'            => (string) self::first( $row, array( 'size', 'sizename', 'kichthuoc', 'kich_thuoc', 'productsize' ), '' ),
			'description'     => (string) self::first( $row, array( 'description' ), '' ),
			'short_description'=> (string) self::first( $row, array( 'short_description', 'shortdescription' ), '' ),
			'brand'           => (string) self::first( $row, array( 'brandname', 'brand' ), '' ),
			'images'          => self::images( $row ),
			'raw'             => $row,
		);
	}

	public static function stock_quantity( array $row ) {
		$value = self::first(
			$row,
			array( 'stock', 'quantity', 'qty', 'qtyinstock', 'quantityinstock', 'tonkho', 'so_luong_ton', 'inventory' ),
			null
		);

		return null === $value || '' === $value ? null : max( 0, (int) round( self::number( $value ) ) );
	}

	private static function first( array $row, array $keys, $default = null ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] && '' !== $row[ $key ] ) {
				return $row[ $key ];
			}
		}
		return $default;
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
		$raw = self::first( $row, array( 'imagename', 'images' ), array() );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $raw ) ) {
			foreach ( $raw as $image ) {
				if ( is_string( $image ) ) {
					$url = $image;
				} elseif ( is_array( $image ) ) {
					$url = self::first( $image, array( 'imgSrc', 'url', 'src' ), '' );
				} else {
					$url = '';
				}
				$url = esc_url_raw( $url );
				if ( $url ) {
					$images[] = $url;
				}
			}
		}

		$default = esc_url_raw( (string) self::first( $row, array( 'default_image', 'defaultimage' ), '' ) );
		if ( $default ) {
			array_unshift( $images, $default );
		}

		return array_values( array_unique( $images ) );
	}
}
