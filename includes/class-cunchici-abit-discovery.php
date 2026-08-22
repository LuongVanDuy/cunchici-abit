<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Discovery {
	const STATE_OPTION      = 'cunchici_abit_discovery_state';
	const CHECKPOINT_OPTION = 'cunchici_abit_discovery_checkpoint_end';

	private $settings;
	private $api;
	private $repository;

	public function __construct( Cunchici_Abit_Settings $settings, Cunchici_Abit_API $api, Cunchici_Abit_Sync_Repository $repository ) {
		$this->settings   = $settings;
		$this->api        = $api;
		$this->repository = $repository;
	}

	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function checkpoint() {
		return (string) get_option( self::CHECKPOINT_OPTION, '' );
	}

	public function suggested_range() {
		$checkpoint = $this->checkpoint();
		$end        = current_time( 'mysql' );
		$start      = '';

		if ( $checkpoint ) {
			try {
				$date  = new DateTimeImmutable( $checkpoint, wp_timezone() );
				$start = $date->modify( '-5 minutes' )->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				$start = $checkpoint;
			}
		}

		return array( 'start' => $start, 'end' => $end, 'is_initial' => ! $checkpoint );
	}

	public function start( $date_time_start = '', $date_time_end = '', $initial_full = false ) {
		$current = $this->state();
		if ( $current && in_array( isset( $current['status'] ) ? $current['status'] : '', array( 'running', 'paused' ), true ) ) {
			return new WP_Error( 'cunchici_abit_discovery_active', 'Đang có một lần quét chưa hoàn tất. Hãy tiếp tục hoặc hủy lần quét đó.' );
		}

		$now = current_time( 'mysql' );
		if ( $initial_full ) {
			$api_start  = '';
			$api_end    = '';
			$target_end = $now;
		} else {
			$suggested  = $this->suggested_range();
			$api_start  = $this->normalize_datetime( $date_time_start ?: $suggested['start'] );
			$api_end    = $this->normalize_datetime( $date_time_end ?: $suggested['end'] );
			if ( '' === $api_end ) {
				$api_end = $now;
			}
			$target_end = $api_end;
		}

		$state = array(
			'status'       => 'running',
			'initial_full' => (bool) $initial_full,
			'api_start'    => $api_start,
			'api_end'      => $api_end,
			'target_end'   => $target_end,
			'page'         => 0,
			'limit'        => max( 1, absint( $this->settings->get( 'sync_limit', 100 ) ) ),
			'fetched'      => 0,
			'created'      => 0,
			'changed'      => 0,
			'unchanged'    => 0,
			'started_at'   => $now,
			'finished_at'  => '',
			'last_error'   => '',
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	public function process_next_page() {
		$state = $this->state();
		if ( ! $state || ! in_array( isset( $state['status'] ) ? $state['status'] : '', array( 'running', 'paused' ), true ) ) {
			return new WP_Error( 'cunchici_abit_no_discovery', 'Không có lần quét nào để tiếp tục.' );
		}

		// Persist running before the network request. This makes Resume from a
		// paused state explicit and allows another AJAX request to set paused or
		// cancelled while this page is in flight.
		$state['status'] = 'running';
		update_option( self::STATE_OPTION, $state, false );

		$response = $this->api->list_products(
			isset( $state['page'] ) ? (int) $state['page'] : 0,
			isset( $state['limit'] ) ? (int) $state['limit'] : 100,
			isset( $state['api_start'] ) ? $state['api_start'] : '',
			isset( $state['api_end'] ) ? $state['api_end'] : ''
		);

		if ( is_wp_error( $response ) ) {
			$state['status']     = 'paused';
			$state['last_error'] = $response->get_error_message();
			update_option( self::STATE_OPTION, $state, false );
			return $response;
		}

		$rows = $this->extract_rows( $response );
		foreach ( $rows as $row ) {
			$mapped = Cunchici_Abit_Product_Mapper::map( $row );
			$result = $this->repository->upsert_candidate( $mapped, $row );
			if ( is_string( $result ) && isset( $state[ $result ] ) ) {
				$state[ $result ]++;
			}
		}

		$state['fetched']   += count( $rows );
		$state['last_error'] = '';
		$has_more = count( $rows ) >= (int) $state['limit'];

		// Re-read only the control status. Pause/Cancel may have been requested
		// while the Abit request above was running.
		$control        = $this->state();
		$control_status = isset( $control['status'] ) ? $control['status'] : 'running';

		if ( 'cancelled' === $control_status ) {
			$state['status']      = 'cancelled';
			$state['finished_at'] = current_time( 'mysql' );
			if ( $has_more ) {
				$state['page'] = (int) $state['page'] + 1;
			}
		} elseif ( $has_more ) {
			$state['page']   = (int) $state['page'] + 1;
			$state['status'] = 'paused' === $control_status ? 'paused' : 'running';
		} else {
			$state['status']      = 'completed';
			$state['finished_at'] = current_time( 'mysql' );
			update_option( self::CHECKPOINT_OPTION, $state['target_end'], false );
		}

		update_option( self::STATE_OPTION, $state, false );
		$state['last_page_count'] = count( $rows );
		$state['has_more']        = $has_more && 'cancelled' !== $state['status'];
		return $state;
	}

	public function pause() {
		$state = $this->state();
		if ( $state && 'running' === ( isset( $state['status'] ) ? $state['status'] : '' ) ) {
			$state['status'] = 'paused';
			update_option( self::STATE_OPTION, $state, false );
		}
		return $state;
	}

	public function cancel() {
		$state = $this->state();
		if ( $state ) {
			$state['status']      = 'cancelled';
			$state['finished_at'] = current_time( 'mysql' );
			update_option( self::STATE_OPTION, $state, false );
		}
		return $state;
	}

	private function normalize_datetime( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		$value = str_replace( 'T', ' ', $value );
		if ( 16 === strlen( $value ) ) {
			$value .= ':00';
		}
		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	private function extract_rows( $response ) {
		if ( ! is_array( $response ) || empty( $response ) ) {
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
		return is_array( $first ) && ( isset( $first['productid'] ) || isset( $first['productcode'] ) || isset( $first['productname'] ) );
	}
}
