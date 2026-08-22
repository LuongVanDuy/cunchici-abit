<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Sync_Repository {
	public function upsert_candidate( array $mapped, array $raw ) {
		global $wpdb;
		$table   = Cunchici_Abit_DB::items_table();
		$abit_id = sanitize_text_field( (string) $mapped['abit_product_id'] );
		if ( '' === $abit_id ) {
			return false;
		}

		$payload      = wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$payload_hash = sha1( (string) $payload );
		$existing     = $wpdb->get_row( $wpdb->prepare( "SELECT id, payload_hash, sync_status, woo_product_id FROM {$table} WHERE abit_product_id = %s", $abit_id ), ARRAY_A );
		$changed      = ! $existing || $existing['payload_hash'] !== $payload_hash;
		$target_missing = $existing
			&& 'synced' === $existing['sync_status']
			&& ! empty( $existing['woo_product_id'] )
			&& 'product' !== get_post_type( (int) $existing['woo_product_id'] );

		$data = array(
			'abit_product_id' => $abit_id,
			'sku'             => sanitize_text_field( (string) $mapped['sku'] ),
			'product_name'    => wp_strip_all_tags( (string) $mapped['name'] ),
			'category_label'  => sanitize_text_field( (string) $mapped['category_label'] ),
			'price'           => (float) $mapped['price'],
			'created_time'    => $this->mysql_datetime_or_null( isset( $raw['createdtime'] ) ? $raw['createdtime'] : ( isset( $raw['created_at'] ) ? $raw['created_at'] : null ) ),
			'modified_time'   => $this->mysql_datetime_or_null( isset( $raw['modifiedtime'] ) ? $raw['modifiedtime'] : ( isset( $raw['updated_at'] ) ? $raw['updated_at'] : null ) ),
			'payload_hash'    => $payload_hash,
			'payload'         => $payload,
			'discovered_at'   => current_time( 'mysql' ),
		);

		if ( ! $existing ) {
			$data['sync_status'] = 'pending';
			$wpdb->insert( $table, $data );
			return 'created';
		}

		if ( $changed || $target_missing ) {
			$data['sync_status'] = 'pending';
			$data['last_error']  = null;
			if ( $target_missing ) {
				$data['woo_product_id'] = null;
			}
		}
		$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
		return ( $changed || $target_missing ) ? 'changed' : 'unchanged';
	}

	public function list_candidates( array $filters = array(), $page = 1, $per_page = 30 ) {
		global $wpdb;
		$table    = Cunchici_Abit_DB::items_table();
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$params   = array();
		$where    = $this->build_where( $filters, $params );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;
		$sql = "SELECT id, abit_product_id, sku, product_name, category_label, price, created_time, modified_time, sync_status, woo_product_id, last_error, discovered_at, synced_at FROM {$table} {$where} ORDER BY COALESCE(modified_time, created_time, discovered_at) DESC, id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $list_params ), ARRAY_A );

		return array(
			'rows'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	public function categories() {
		global $wpdb;
		$table = Cunchici_Abit_DB::items_table();
		return $wpdb->get_col( "SELECT DISTINCT category_label FROM {$table} WHERE category_label <> '' ORDER BY category_label ASC LIMIT 500" );
	}

	public function status_counts() {
		global $wpdb;
		$table = Cunchici_Abit_DB::items_table();
		$rows  = $wpdb->get_results( "SELECT sync_status, COUNT(*) AS total FROM {$table} GROUP BY sync_status", ARRAY_A );
		$out   = array( 'pending' => 0, 'synced' => 0, 'error' => 0 );
		foreach ( $rows as $row ) {
			$out[ $row['sync_status'] ] = (int) $row['total'];
		}
		return $out;
	}

	public function create_sync_run( array $filters, array $selected_ids, array $options ) {
		global $wpdb;
		$open = $this->latest_open_run();
		if ( $open ) {
			return new WP_Error( 'cunchici_abit_run_already_open', sprintf( 'Đang có phiên đồng bộ #%d chưa kết thúc. Hãy Resume hoặc Cancel phiên đó trước.', (int) $open['id'] ) );
		}

		$runs  = Cunchici_Abit_DB::runs_table();
		$items = Cunchici_Abit_DB::items_table();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$runs,
			array(
				'run_type'   => 'sync',
				'status'     => 'queued',
				'options'    => wp_json_encode( $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at' => $now,
			)
		);
		$run_id = (int) $wpdb->insert_id;
		if ( ! $run_id ) {
			return new WP_Error( 'cunchici_abit_run_create_failed', 'Không thể tạo phiên đồng bộ.' );
		}

		if ( $selected_ids ) {
			$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', $selected_ids ) ) ) );
			foreach ( array_chunk( $selected_ids, 200 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$args = array_merge( array( $run_id ), $chunk );
				$wpdb->query( $wpdb->prepare( "UPDATE {$items} SET queue_run_id = %d, queue_status = 'queued' WHERE id IN ({$placeholders})", $args ) );
			}
		} else {
			$params = array();
			$where  = $this->build_where( $filters, $params );
			$sql    = "UPDATE {$items} SET queue_run_id = %d, queue_status = 'queued' {$where}";
			$args   = array_merge( array( $run_id ), $params );
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE queue_run_id = %d AND queue_status = 'queued'", $run_id ) );
		$wpdb->update( $runs, array( 'total' => $total ), array( 'id' => $run_id ) );

		if ( 0 === $total ) {
			$wpdb->update( $runs, array( 'status' => 'completed', 'finished_at' => $now ), array( 'id' => $run_id ) );
		}
		return $this->get_run( $run_id );
	}

	public function get_run( $run_id ) {
		global $wpdb;
		$table = Cunchici_Abit_DB::runs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $run_id ) ), ARRAY_A );
		if ( $row && ! empty( $row['options'] ) ) {
			$row['options'] = json_decode( $row['options'], true ) ?: array();
		}
		return $row;
	}

	public function latest_open_run() {
		global $wpdb;
		$table = Cunchici_Abit_DB::runs_table();
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE run_type = 'sync' AND status IN ('queued','running','paused') ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( $row && ! empty( $row['options'] ) ) {
			$row['options'] = json_decode( $row['options'], true ) ?: array();
		}
		return $row;
	}

	public function next_queued_item( $run_id ) {
		global $wpdb;
		$table = Cunchici_Abit_DB::items_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE queue_run_id = %d AND queue_status = 'queued' ORDER BY id ASC LIMIT 1", absint( $run_id ) ), ARRAY_A );
	}

	/**
	 * Mark a run as running only if it was not paused/cancelled by another AJAX
	 * request while the current item request was in flight.
	 */
	public function start_run( $run_id, $item = null ) {
		global $wpdb;
		$table = Cunchici_Abit_DB::runs_table();
		$run   = $this->get_run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$started_at = empty( $run['started_at'] ) ? current_time( 'mysql' ) : $run['started_at'];
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', current_item_id = %d, current_product_name = %s, started_at = %s WHERE id = %d AND status IN ('queued','running')",
				$item ? (int) $item['id'] : 0,
				$item ? $item['product_name'] : '',
				$started_at,
				absint( $run_id )
			)
		);
		return false !== $updated;
	}

	public function finish_item( $run_id, $item_id, $success, $woo_product_id = 0, $error = '' ) {
		global $wpdb;
		$items = Cunchici_Abit_DB::items_table();
		$runs  = Cunchici_Abit_DB::runs_table();
		$now   = current_time( 'mysql' );

		$wpdb->update(
			$items,
			array(
				'queue_status'   => $success ? 'done' : 'error',
				'sync_status'    => $success ? 'synced' : 'error',
				'woo_product_id' => $woo_product_id ? absint( $woo_product_id ) : null,
				'last_error'     => $success ? null : sanitize_textarea_field( $error ),
				'synced_at'      => $success ? $now : null,
			),
			array( 'id' => absint( $item_id ), 'queue_run_id' => absint( $run_id ) )
		);

		$wpdb->query( $wpdb->prepare( "UPDATE {$runs} SET processed = processed + 1, succeeded = succeeded + %d, failed = failed + %d, current_item_id = NULL, current_product_name = NULL WHERE id = %d", $success ? 1 : 0, $success ? 0 : 1, absint( $run_id ) ) );
	}

	public function set_run_status( $run_id, $status ) {
		global $wpdb;
		$allowed = array( 'queued', 'running', 'paused', 'completed', 'cancelled' );
		$status  = in_array( $status, $allowed, true ) ? $status : 'paused';
		$data    = array( 'status' => $status );
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			$data['finished_at'] = current_time( 'mysql' );
		}
		$wpdb->update( Cunchici_Abit_DB::runs_table(), $data, array( 'id' => absint( $run_id ) ) );
		return $this->get_run( $run_id );
	}

	public function mark_completed_if_done( $run_id ) {
		global $wpdb;
		$items     = Cunchici_Abit_DB::items_table();
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE queue_run_id = %d AND queue_status = 'queued'", absint( $run_id ) ) );
		if ( 0 === $remaining ) {
			$run = $this->get_run( $run_id );
			if ( $run && 'cancelled' !== $run['status'] ) {
				return $this->set_run_status( $run_id, 'completed' );
			}
		}
		return $this->get_run( $run_id );
	}

	public function get_item_payload( array $item ) {
		$decoded = json_decode( isset( $item['payload'] ) ? $item['payload'] : '', true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function build_where( array $filters, array &$params ) {
		$where = array( '1=1' );
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array( 'pending', 'synced', 'error' ), true ) ) {
			$where[]  = 'sync_status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[]  = 'category_label = %s';
			$params[] = sanitize_text_field( $filters['category'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
			$where[]  = '(sku LIKE %s OR product_name LIKE %s OR abit_product_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function mysql_datetime_or_null( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		try {
			$date = new DateTimeImmutable( (string) $value, wp_timezone() );
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
