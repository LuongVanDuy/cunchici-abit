<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Product_Sync {
	private $settings;
	private $api;
	private $repository;

	public function __construct( Cunchici_Abit_Settings $settings, Cunchici_Abit_API $api, Cunchici_Abit_Sync_Repository $repository ) {
		$this->settings   = $settings;
		$this->api        = $api;
		$this->repository = $repository;
	}

	public function process_run_step( $run_id ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'cunchici_abit_woocommerce_missing', 'WooCommerce chưa được kích hoạt.' );
		}

		$run = $this->repository->get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'cunchici_abit_run_missing', 'Không tìm thấy phiên đồng bộ.' );
		}
		if ( 'paused' === $run['status'] ) {
			return new WP_Error( 'cunchici_abit_run_paused', 'Phiên đồng bộ đang tạm dừng.' );
		}
		if ( in_array( $run['status'], array( 'completed', 'cancelled' ), true ) ) {
			return $this->progress_payload( $run, null, '' );
		}

		$item = $this->repository->next_queued_item( $run_id );
		if ( ! $item ) {
			$run = $this->repository->mark_completed_if_done( $run_id );
			return $this->progress_payload( $run, null, '' );
		}

		$this->repository->start_run( $run_id, $item );
		$raw     = $this->repository->get_item_payload( $item );
		$mapped  = Cunchici_Abit_Product_Mapper::map( $raw );
		$options = isset( $run['options'] ) && is_array( $run['options'] ) ? $run['options'] : array();
		$action  = '';

		try {
			if ( '' === trim( (string) $mapped['abit_product_id'] ) || '' === trim( (string) $mapped['name'] ) ) {
				throw new Exception( 'Thiếu productid hoặc productname.' );
			}
			$result = $this->upsert( $mapped, $options );
			$action = $result['action'];
			$this->repository->finish_item( $run_id, $item['id'], true, $result['product_id'], '' );
		} catch ( Throwable $e ) {
			$this->repository->finish_item( $run_id, $item['id'], false, 0, $e->getMessage() );
			$run = $this->repository->mark_completed_if_done( $run_id );
			return $this->progress_payload( $run, $item, '', $e->getMessage() );
		}

		$run = $this->repository->mark_completed_if_done( $run_id );
		return $this->progress_payload( $run, $item, $action );
	}

	public function next_product_summary( $run_id ) {
		$item = $this->repository->next_queued_item( $run_id );
		return $this->item_summary( $item );
	}

	private function upsert( array $mapped, array $options ) {
		$product_id = $this->find_product_id( $mapped['abit_product_id'], $mapped['sku'] );
		$is_new     = ! $product_id;

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				throw new Exception( 'Không thể load WooCommerce product hiện có.' );
			}
			if ( ! $product->is_type( 'simple' ) ) {
				throw new Exception( 'Abit ID/SKU đang trỏ tới sản phẩm không phải simple; plugin không tự đổi type.' );
			}
		} else {
			$product = new WC_Product_Simple();
			$product->set_status( isset( $options['new_product_status'] ) && 'draft' === $options['new_product_status'] ? 'draft' : 'publish' );
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
			} else {
				throw new Exception( sprintf( 'SKU %s đã thuộc WooCommerce product #%d.', $mapped['sku'], $owner ) );
			}
		}

		if ( $this->has_mapped_attributes( $mapped ) ) {
			$product->set_attributes( $this->build_attributes( $mapped ) );
		}

		$this->apply_categories( $product, $mapped, $options );

		$product_id = $product->save();
		update_post_meta( $product_id, '_cunchici_abit_product_id', sanitize_text_field( $mapped['abit_product_id'] ) );
		update_post_meta( $product_id, '_cunchici_abit_last_synced_at', current_time( 'mysql' ) );
		update_post_meta( $product_id, '_cunchici_abit_modified_time', sanitize_text_field( $mapped['modified_time'] ) );

		if ( '' !== trim( (string) $mapped['barcode'] ) ) {
			update_post_meta( $product_id, '_cunchici_abit_barcode', sanitize_text_field( $mapped['barcode'] ) );
		}
		if ( '' !== trim( (string) $mapped['original_code'] ) ) {
			update_post_meta( $product_id, '_cunchici_abit_ma_goc', sanitize_text_field( $mapped['original_code'] ) );
		}

		return array( 'product_id' => $product_id, 'action' => $is_new ? 'created' : 'updated' );
	}

	private function apply_categories( WC_Product $product, array $mapped, array $options ) {
		$mode = isset( $options['category_mode'] ) ? $options['category_mode'] : 'keep';
		if ( 'keep' === $mode ) {
			return;
		}

		$existing_ids = array_map( 'absint', $product->get_category_ids() );

		if ( 'fixed' === $mode ) {
			$term_id = isset( $options['fixed_category_id'] ) ? absint( $options['fixed_category_id'] ) : 0;
			if ( $term_id && term_exists( $term_id, 'product_cat' ) ) {
				$product->set_category_ids( array_values( array_unique( array_merge( $existing_ids, array( $term_id ) ) ) ) );
			}
			return;
		}

		if ( 'abit' !== $mode ) {
			return;
		}

		$names = Cunchici_Abit_Product_Mapper::category_names( $mapped['category'] );
		if ( ! $names ) {
			return;
		}

		$ids = array();
		foreach ( $names as $name ) {
			$existing = term_exists( $name, 'product_cat' );
			if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
				$ids[] = (int) $existing['term_id'];
				continue;
			}
			if ( is_int( $existing ) ) {
				$ids[] = $existing;
				continue;
			}
			$created = wp_insert_term( $name, 'product_cat' );
			if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}

		if ( $ids ) {
			$product->set_category_ids( array_values( array_unique( array_merge( $existing_ids, $ids ) ) ) );
		}
	}

	private function progress_payload( array $run, $item = null, $action = '', $error = '' ) {
		$total        = max( 0, (int) $run['total'] );
		$processed    = max( 0, (int) $run['processed'] );
		$percent      = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 1 ) ) : 100;
		$next_product = in_array( $run['status'], array( 'completed', 'cancelled' ), true ) ? null : $this->next_product_summary( $run['id'] );

		return array(
			'run_id'       => (int) $run['id'],
			'status'       => $run['status'],
			'total'        => $total,
			'processed'    => $processed,
			'succeeded'    => (int) $run['succeeded'],
			'failed'       => (int) $run['failed'],
			'percent'      => $percent,
			'product'      => $this->item_summary( $item ),
			'next_product' => $next_product,
			'action'       => $action,
			'error'        => $error,
		);
	}

	private function item_summary( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}
		return array(
			'id'   => (int) $item['id'],
			'sku'  => $item['sku'],
			'name' => $item['product_name'],
		);
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
}
