<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_API {
	private $settings;

	public function __construct( Cunchici_Abit_Settings $settings ) {
		$this->settings = $settings;
	}

	private function post( $path, array $body ) {
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error( 'cunchici_abit_not_configured', 'Chưa cấu hình Access Token hoặc Partner Name của Abit.' );
		}

		$payload = array_merge(
			array(
				'access_token' => $this->settings->get( 'access_token' ),
				'partner_name' => $this->settings->get( 'partner_name' ),
			),
			$body
		);

		$response = wp_remote_post(
			$this->settings->get( 'base_url' ) . $path,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'cunchici_abit_http_error',
				sprintf( 'Abit API trả HTTP %d.', $status ),
				array( 'status' => $status, 'response' => $data ?: $raw )
			);
		}

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'cunchici_abit_invalid_json', 'Abit API trả dữ liệu JSON không hợp lệ.' );
		}

		return $data;
	}

	/**
	 * Product discovery endpoint.
	 *
	 * date_time_start/date_time_end are optional. The first catalog discovery can
	 * omit them; incremental scans pass the completed discovery checkpoint.
	 */
	public function list_products( $page = 0, $limit = 100, $date_time_start = '', $date_time_end = '' ) {
		$body = array(
			'page'  => max( 0, absint( $page ) ),
			'limit' => max( 1, absint( $limit ) ),
		);

		if ( '' !== trim( (string) $date_time_start ) ) {
			$body['date_time_start'] = sanitize_text_field( $date_time_start );
		}
		if ( '' !== trim( (string) $date_time_end ) ) {
			$body['date_time_end'] = sanitize_text_field( $date_time_end );
		}

		return $this->post( '/products/listProductsforPartner', $body );
	}

	/** Abit warehouse/branch discovery. */
	public function list_stores( $page = 0, $limit = 100 ) {
		return $this->post(
			'/productstore/getStoreidByPartner',
			array(
				'page'  => max( 0, absint( $page ) ),
				'limit' => max( 1, absint( $limit ) ),
			)
		);
	}

	/** Product list with current stock for one Abit warehouse/branch. */
	public function list_products_with_stock( $page = 0, $limit = 100 ) {
		$store_id = trim( (string) $this->settings->get( 'productstoreid', '' ) );
		if ( '' === $store_id ) {
			return new WP_Error( 'cunchici_abit_missing_store', 'Chưa cấu hình Product Store ID để đồng bộ tồn kho.' );
		}

		return $this->post(
			'/products/listProductsWithStockforPartner',
			array(
				'productstoreid' => absint( $store_id ),
				'page'           => max( 0, absint( $page ) ),
				'limit'          => max( 1, absint( $limit ) ),
			)
		);
	}
}
