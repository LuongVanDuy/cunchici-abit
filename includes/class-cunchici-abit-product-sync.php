<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Product_Sync {
	private $settings;
	private $api;

	public function __construct( Cunchici_Abit_Settings $settings, Cunchici_Abit_API $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	public function sync_page( $page = 0 ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'cunchici_abit_woocommerce_missing', 'WooCommerce chưa được kích hoạt.' );
		}

		$limit = max( 1, absint( $this->settings->get( 'sync_limit', 100 ) ) );
		$page  = max( 0, absint( $page ) );

		$product_response = $this->api->list_products( $page, $limit );
		if ( is_wp_error( $product_response ) ) {
			return $product_response;
		}

		$product_rows  = $this->extract_rows( $product_response );
		$stock_map     = array();
		$stock_warning = '';

		if ( '' !== trim( (string) $this->settings->get( 'productstoreid', '' ) ) ) {
			$stock_response = $this->api->list_products_with_stock( $page, $limit );
			if ( is_wp_error( $stock_response ) ) {
				$stock_warning = $stock_response->get_error_message();
			} else {
				foreach ( $this->extract_rows( $stock_response ) as $stock_row ) {
					$id = $this->row_product_id( $stock_row );
					if ( '' !== $id ) {
						$stock_map[ $id ] = $stock_row;
					}
				}
			}
		} else {
			$stock_warning = 'Chưa cấu hình Product Store ID nên tồn kho chưa được cập nhật.';
		}

		$result = array(
			'page'          => $page,
			'fetched'       => count( $product_rows ),
			'created'       => 0,
			'updated'       => 0,
			'failed'        => 0,
			'errors'        => array(),
			'has_more'      => count( $product_rows ) >= $limit,
			'next_page'     => $page + 1,
			'stock_warning' => $stock_warning,
		);

		foreach ( $product_rows as $row ) {
			$mapped = Cunchici_Abit_Product_Mapper::map( $row );
			$id     = (string) $mapped['abit_product_id'];

			if ( '' === $id || '' === trim( $mapped['name'] ) ) {
				$result['failed']++;
				$result['errors'][] = array(
					'abit_product_id' => $id,
					'sku'             => $mapped['sku'],
					'message'         => 'Thiếu productid hoặc productname.',
				);
				continue;
			}

			try {
				$stock_row = isset( $stock_map[ $id ] ) ? $stock_map[ $id ] : null;
				$action    = $this->upsert( $mapped, $stock_row );
				$result[ $action ]++;
			} catch ( Exception $e ) {
				$result['failed']++;
				$result['errors'][] = array(
					'abit_product_id' => $id,
					'sku'             => $mapped['sku'],
					'message'         => $e->getMessage(),
				);
			}
		}

		return $result;
	}

	private function upsert( array $mapped, $stock_row = null ) {
		$product_id = $this->find_product_id( $mapped['abit_product_id'], $mapped['sku'] );
		$is_new     = ! $product_id;

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				throw new Exception( 'Không thể load WooCommerce product hiện có.' );
			}
			if ( ! $product->is_type( 'simple' ) ) {
				throw new Exception( 'SKU/Abit ID đang trỏ tới sản phẩm không phải simple; không tự chuyển type để tránh mất dữ liệu.' );
			}
		} else {
			$product = new WC_Product_Simple();
			$product->set_status( 'publish' );
		}

		$product->set_name( wp_strip_all_tags( $mapped['name'] ) );
		$product->set_description( wp_kses_post( $mapped['description'] ) );
		$product->set_short_description( wp_kses_post( $mapped['short_description'] ) );
		$product->set_regular_price( wc_format_decimal( $mapped['price'] ) );
		$product->set_price( wc_format_decimal( $mapped['price'] ) );

		if ( '' !== trim( $mapped['sku'] ) && $mapped['sku'] !== $product->get_sku() ) {
			$owner = wc_get_product_id_by_sku( $mapped['sku'] );
			if ( ! $owner || (int) $owner === (int) $product->get_id() ) {
				$product->set_sku( $mapped['sku'] );
			}
		}

		// Do not call set_attributes([]). The verified list API currently has no
		// separate color/size keys; overwriting with an empty array would erase
		// attributes already maintained in WooCommerce.
		if ( $this->has_mapped_attributes( $mapped ) ) {
			$product->set_attributes( $this->build_attributes( $mapped ) );
		}

		if ( is_array( $stock_row ) ) {
			$quantity = Cunchici_Abit_Product_Mapper::stock_quantity( $stock_row );
			if ( null !== $quantity ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $quantity );
				$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			}
		}

		$product_id = $product->save();

		update_post_meta( $product_id, '_cunchici_abit_product_id', sanitize_text_field( $mapped['abit_product_id'] ) );
		update_post_meta( $product_id, '_cunchici_abit_last_synced_at', current_time( 'mysql', true ) );
		update_post_meta( $product_id, '_cunchici_abit_modified_time', sanitize_text_field( $mapped['modified_time'] ) );

		if ( '' !== trim( (string) $mapped['barcode'] ) ) {
			update_post_meta( $product_id, '_cunchici_abit_barcode', sanitize_text_field( $mapped['barcode'] ) );
		}
		if ( '' !== trim( (string) $mapped['original_code'] ) ) {
			update_post_meta( $product_id, '_cunchici_abit_ma_goc', sanitize_text_field( $mapped['original_code'] ) );
		}

		return $is_new ? 'created' : 'updated';
	}

	private function has_mapped_attributes( array $mapped ) {
		return '' !== trim( (string) $mapped['color'] ) || '' !== trim( (string) $mapped['size'] );
	}

	private function build_attributes( array $mapped ) {
		$attributes = array();
		$position   = 0;

		foreach ( array( 'Màu sắc' => $mapped['color'], 'Size' => $mapped['size'] ) as $name => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[] = $attribute;
		}

		return $attributes;
	}

	private function find_product_id( $abit_product_id, $sku ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_cunchici_abit_product_id',
				'meta_value'     => sanitize_text_field( $abit_product_id ),
			)
		);

		if ( ! empty( $ids ) ) {
			return (int) $ids[0];
		}

		return '' !== trim( (string) $sku ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
	}

	private function row_product_id( array $row ) {
		foreach ( array( 'productid', 'product_id', 'id' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
				return (string) $row[ $key ];
			}
		}

		return '';
	}

	/**
	 * Accept common API wrappers without coupling the rest of the plugin to one
	 * response envelope. The live product list is currently a top-level array.
	 */
	private function extract_rows( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}

		if ( $this->is_list_of_rows( $response ) ) {
			return $response;
		}

		foreach ( array( 'data', 'products', 'items', 'result', 'list' ) as $key ) {
			if ( ! isset( $response[ $key ] ) || ! is_array( $response[ $key ] ) ) {
				continue;
			}

			if ( $this->is_list_of_rows( $response[ $key ] ) ) {
				return $response[ $key ];
			}

			foreach ( array( 'data', 'products', 'items', 'list' ) as $nested ) {
				if ( isset( $response[ $key ][ $nested ] ) && $this->is_list_of_rows( $response[ $key ][ $nested ] ) ) {
					return $response[ $key ][ $nested ];
				}
			}
		}

		return array();
	}

	private function is_list_of_rows( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}

		$first = reset( $value );
		return is_array( $first );
	}
}
