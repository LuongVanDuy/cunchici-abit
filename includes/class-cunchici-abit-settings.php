<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Settings {
	const OPTION_KEY = 'cunchici_abit_settings';

	public function defaults() {
		return array(
			'base_url'      => 'https://new.abitstore.vn',
			'access_token'  => '',
			'partner_name'  => '',
			'productstoreid'=> '',
			'sync_limit'    => 100,
		);
	}

	public function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	public function get( $key, $default = null ) {
		$settings = $this->all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		$base_url = isset( $input['base_url'] ) ? esc_url_raw( trim( $input['base_url'] ) ) : '';
		$base_url = $base_url ? untrailingslashit( $base_url ) : 'https://new.abitstore.vn';

		$limit = isset( $input['sync_limit'] ) ? absint( $input['sync_limit'] ) : 100;
		$limit = max( 1, min( 500, $limit ) );

		return array(
			'base_url'       => $base_url,
			'access_token'   => isset( $input['access_token'] ) ? sanitize_text_field( $input['access_token'] ) : '',
			'partner_name'   => isset( $input['partner_name'] ) ? sanitize_text_field( $input['partner_name'] ) : '',
			'productstoreid' => isset( $input['productstoreid'] ) ? sanitize_text_field( $input['productstoreid'] ) : '',
			'sync_limit'     => $limit,
		);
	}

	public function register() {
		register_setting(
			'cunchici_abit_settings_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	public function is_configured() {
		return '' !== trim( (string) $this->get( 'access_token', '' ) )
			&& '' !== trim( (string) $this->get( 'partner_name', '' ) );
	}
}
