<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Admin {
	private $settings;
	private $api;
	private $sync;
	private $discovery;
	private $repository;

	public function __construct( Cunchici_Abit_Settings $settings, Cunchici_Abit_API $api, Cunchici_Abit_Product_Sync $sync, Cunchici_Abit_Discovery $discovery, Cunchici_Abit_Sync_Repository $repository ) {
		$this->settings   = $settings;
		$this->api        = $api;
		$this->sync       = $sync;
		$this->discovery  = $discovery;
		$this->repository = $repository;

		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_cunchici_abit_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_cunchici_abit_discovery_start', array( $this, 'ajax_discovery_start' ) );
		add_action( 'wp_ajax_cunchici_abit_discovery_next', array( $this, 'ajax_discovery_next' ) );
		add_action( 'wp_ajax_cunchici_abit_discovery_pause', array( $this, 'ajax_discovery_pause' ) );
		add_action( 'wp_ajax_cunchici_abit_discovery_cancel', array( $this, 'ajax_discovery_cancel' ) );
		add_action( 'wp_ajax_cunchici_abit_candidates', array( $this, 'ajax_candidates' ) );
		add_action( 'wp_ajax_cunchici_abit_create_run', array( $this, 'ajax_create_run' ) );
		add_action( 'wp_ajax_cunchici_abit_run_step', array( $this, 'ajax_run_step' ) );
		add_action( 'wp_ajax_cunchici_abit_run_status', array( $this, 'ajax_run_status' ) );
	}

	public function register_menu() {
		$capability = 'manage_woocommerce';
		add_menu_page( 'Cún Chic × Abit', 'Cún Chic × Abit', $capability, 'cunchici-abit', array( $this, 'render_settings_page' ), 'dashicons-update-alt', 56 );
		add_submenu_page( 'cunchici-abit', 'Cấu hình Abit', 'Cấu hình', $capability, 'cunchici-abit', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'cunchici-abit', 'Đồng bộ sản phẩm Abit', 'Đồng bộ sản phẩm', $capability, 'cunchici-abit-sync', array( $this, 'render_sync_page' ) );
	}

	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( ! in_array( $page, array( 'cunchici-abit', 'cunchici-abit-sync' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'cunchici-abit-admin', CUNCHICI_ABIT_URL . 'assets/admin.css', array(), CUNCHICI_ABIT_VERSION );
		wp_enqueue_script( 'cunchici-abit-admin', CUNCHICI_ABIT_URL . 'assets/admin.js', array(), CUNCHICI_ABIT_VERSION, true );
		wp_localize_script(
			'cunchici-abit-admin',
			'CunchiciAbitAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cunchici_abit_admin' ),
				'page'    => $page,
			)
		);
	}

	private function guard_ajax() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Bạn không có quyền thực hiện thao tác này.' ), 403 );
		}
		check_ajax_referer( 'cunchici_abit_admin', 'nonce' );
	}

	public function ajax_test_connection() {
		$this->guard_ajax();
		$product_response = $this->api->list_products( 0, 1 );
		if ( is_wp_error( $product_response ) ) {
			wp_send_json_error( array( 'message' => $product_response->get_error_message() ) );
		}

		$diagnostics = array( 'products' => $this->describe_response( $product_response, 'first_product' ) );
		$store_response = $this->api->list_stores( 0, 100 );
		if ( is_wp_error( $store_response ) ) {
			$diagnostics['stores'] = array( 'error' => $store_response->get_error_message() );
		} else {
			$store_rows = $this->extract_rows( $store_response, false );
			$diagnostics['stores'] = array(
				'count' => count( $store_rows ),
				'rows'  => array_map( array( $this, 'sanitize_sample' ), array_slice( $store_rows, 0, 20 ) ),
			);
		}

		$store_id = trim( (string) $this->settings->get( 'productstoreid', '' ) );
		if ( $store_id ) {
			$stock_response = $this->api->list_products_with_stock( 0, 1 );
			$diagnostics['stock'] = is_wp_error( $stock_response )
				? array( 'productstoreid' => $store_id, 'error' => $stock_response->get_error_message() )
				: array_merge( array( 'productstoreid' => $store_id ), $this->describe_response( $stock_response, 'first_stock' ) );
		} else {
			$diagnostics['stock'] = array( 'message' => 'Chưa cấu hình Product Store ID.' );
		}

		wp_send_json_success( array( 'message' => 'Kết nối Abit thành công. Diagnostic chỉ đọc dữ liệu.', 'diagnostics' => $diagnostics ) );
	}

	public function ajax_discovery_start() {
		$this->guard_ajax();
		$initial = ! empty( $_POST['initial_full'] );
		$start   = isset( $_POST['date_time_start'] ) ? sanitize_text_field( wp_unslash( $_POST['date_time_start'] ) ) : '';
		$end     = isset( $_POST['date_time_end'] ) ? sanitize_text_field( wp_unslash( $_POST['date_time_end'] ) ) : '';
		$result  = $this->discovery->start( $start, $end, $initial );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'state' => $this->discovery->state() ) );
		}
		wp_send_json_success( array( 'state' => $result ) );
	}

	public function ajax_discovery_next() {
		$this->guard_ajax();
		$result = $this->discovery->process_next_page();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'state' => $this->discovery->state() ) );
		}
		wp_send_json_success( array( 'state' => $result, 'counts' => $this->repository->status_counts() ) );
	}

	public function ajax_discovery_pause() {
		$this->guard_ajax();
		wp_send_json_success( array( 'state' => $this->discovery->pause() ) );
	}

	public function ajax_discovery_cancel() {
		$this->guard_ajax();
		wp_send_json_success( array( 'state' => $this->discovery->cancel() ) );
	}

	public function ajax_candidates() {
		$this->guard_ajax();
		$filters = $this->request_filters();
		$page    = isset( $_POST['page_num'] ) ? absint( $_POST['page_num'] ) : 1;
		$result  = $this->repository->list_candidates( $filters, $page, 30 );
		$result['categories'] = $this->repository->categories();
		$result['counts'] = $this->repository->status_counts();
		$result['open_run'] = $this->repository->latest_open_run();
		wp_send_json_success( $result );
	}

	public function ajax_create_run() {
		$this->guard_ajax();
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => 'WooCommerce chưa hoạt động.' ) );
		}

		$filters = $this->request_filters();
		$ids_raw = isset( $_POST['selected_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_ids'] ) ) : '';
		$ids     = $ids_raw ? array_map( 'absint', explode( ',', $ids_raw ) ) : array();
		$mode    = isset( $_POST['category_mode'] ) ? sanitize_key( $_POST['category_mode'] ) : 'keep';
		if ( ! in_array( $mode, array( 'keep', 'abit', 'fixed' ), true ) ) {
			$mode = 'keep';
		}
		$options = array(
			'category_mode'      => $mode,
			'fixed_category_id'  => isset( $_POST['fixed_category_id'] ) ? absint( $_POST['fixed_category_id'] ) : 0,
			'new_product_status' => isset( $_POST['new_product_status'] ) && 'draft' === $_POST['new_product_status'] ? 'draft' : 'publish',
		);

		$run = $this->repository->create_sync_run( $filters, $ids, $options );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}
		wp_send_json_success( array( 'run' => $run ) );
	}

	public function ajax_run_step() {
		$this->guard_ajax();
		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$result = $this->sync->process_run_step( $run_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'run' => $this->repository->get_run( $run_id ) ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_run_status() {
		$this->guard_ajax();
		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$action = isset( $_POST['run_action'] ) ? sanitize_key( $_POST['run_action'] ) : 'pause';
		$status = 'pause' === $action ? 'paused' : ( 'cancel' === $action ? 'cancelled' : 'queued' );
		$run = $this->repository->set_run_status( $run_id, $status );
		wp_send_json_success( array( 'run' => $run ) );
	}

	public function render_settings_page() {
		$this->require_capability();
		$settings = $this->settings->all();
		?>
		<div class="wrap cunchici-abit-wrap">
			<div class="ccabit-page-head"><div><h1>Cún Chic × Abit</h1><p>Kết nối Abit Open API với WooCommerce.</p></div><span class="ccabit-version">v<?php echo esc_html( CUNCHICI_ABIT_VERSION ); ?></span></div>
			<div class="ccabit-card">
				<h2>Cấu hình kết nối</h2>
				<?php settings_errors(); ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'cunchici_abit_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="ccabit-base-url">Abit Base URL</label></th><td><input id="ccabit-base-url" class="regular-text code" type="url" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[base_url]" value="<?php echo esc_attr( $settings['base_url'] ); ?>"></td></tr>
						<tr><th><label for="ccabit-token">Access Token</label></th><td><input id="ccabit-token" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[access_token]" value="<?php echo esc_attr( $settings['access_token'] ); ?>"><p class="description">Token chỉ lưu trong WordPress options, không đưa lên GitHub.</p></td></tr>
						<tr><th><label for="ccabit-partner">Partner Name / Mã shop</label></th><td><input id="ccabit-partner" class="regular-text" type="text" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[partner_name]" value="<?php echo esc_attr( $settings['partner_name'] ); ?>"></td></tr>
						<tr><th><label for="ccabit-store">Product Store ID / Kho Abit</label></th><td><input id="ccabit-store" class="regular-text" type="text" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[productstoreid]" value="<?php echo esc_attr( $settings['productstoreid'] ); ?>"><p class="description">Dùng cho tồn kho. Test kết nối sẽ hiển thị danh sách kho.</p></td></tr>
						<tr><th><label for="ccabit-limit">Sản phẩm mỗi trang API</label></th><td><input id="ccabit-limit" type="number" min="1" max="500" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[sync_limit]" value="<?php echo esc_attr( $settings['sync_limit'] ); ?>"></td></tr>
					</table>
					<?php submit_button( 'Lưu cấu hình' ); ?>
				</form>
			</div>
			<div class="ccabit-card">
				<h2>Kiểm tra kết nối & payload</h2>
				<p>Chỉ đọc dữ liệu từ Abit, không ghi WooCommerce.</p>
				<button type="button" class="button button-secondary" id="ccabit-test-connection">Test kết nối Abit</button>
				<pre id="ccabit-test-result" class="ccabit-code" hidden></pre>
			</div>
		</div>
		<?php
	}

	public function render_sync_page() {
		$this->require_capability();
		$counts     = $this->repository->status_counts();
		$suggested  = $this->discovery->suggested_range();
		$state      = $this->discovery->state();
		$checkpoint = $this->discovery->checkpoint();
		$open_run   = $this->repository->latest_open_run();
		$wc_cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		?>
		<div class="wrap cunchici-abit-wrap" id="ccabit-sync-app">
			<div class="ccabit-page-head"><div><h1>Đồng bộ sản phẩm</h1><p>Quét trước, chọn sản phẩm, sau đó mới ghi vào WooCommerce.</p></div><span class="ccabit-version">v<?php echo esc_html( CUNCHICI_ABIT_VERSION ); ?></span></div>

			<?php if ( ! $this->settings->is_configured() ) : ?><div class="notice notice-warning inline"><p>Hãy cấu hình Access Token và Partner Name trước.</p></div><?php endif; ?>
			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?><div class="notice notice-error inline"><p>WooCommerce chưa hoạt động.</p></div><?php endif; ?>

			<div class="ccabit-stats">
				<div class="ccabit-stat"><span>Chờ đồng bộ</span><strong id="ccabit-count-pending"><?php echo esc_html( $counts['pending'] ); ?></strong></div>
				<div class="ccabit-stat"><span>Đã đồng bộ</span><strong id="ccabit-count-synced"><?php echo esc_html( $counts['synced'] ); ?></strong></div>
				<div class="ccabit-stat"><span>Lỗi</span><strong id="ccabit-count-error"><?php echo esc_html( $counts['error'] ); ?></strong></div>
				<div class="ccabit-stat"><span>Checkpoint quét</span><strong class="ccabit-small-value"><?php echo esc_html( $checkpoint ?: 'Chưa có' ); ?></strong></div>
			</div>

			<div class="ccabit-card">
				<div class="ccabit-card-head"><div><h2>1. Quét sản phẩm từ Abit</h2><p>Quét chỉ cập nhật hàng chờ cục bộ, chưa tạo sản phẩm WooCommerce.</p></div><span class="ccabit-badge" id="ccabit-discovery-status"><?php echo esc_html( isset( $state['status'] ) ? $state['status'] : 'idle' ); ?></span></div>
				<div class="ccabit-grid ccabit-grid-4">
					<label><span>Từ thời gian</span><input id="ccabit-date-start" type="datetime-local" value="<?php echo esc_attr( $this->datetime_local_value( $suggested['start'] ) ); ?>"></label>
					<label><span>Đến thời gian</span><input id="ccabit-date-end" type="datetime-local" value="<?php echo esc_attr( $this->datetime_local_value( $suggested['end'] ) ); ?>"></label>
					<div class="ccabit-field-action"><button type="button" class="button button-primary" id="ccabit-scan-incremental">Quét mới / cập nhật</button></div>
					<div class="ccabit-field-action"><button type="button" class="button" id="ccabit-scan-full">Quét toàn bộ lần đầu</button></div>
				</div>
				<p class="description">Sau lần quét toàn bộ thành công, plugin dùng checkpoint + overlap 5 phút cho các lần incremental. Sản phẩm đã quét nhưng chưa sync vẫn nằm trong danh sách Pending.</p>
				<div id="ccabit-discovery-progress" class="ccabit-progress-block" data-state='<?php echo esc_attr( wp_json_encode( $state ) ); ?>'>
					<div class="ccabit-progress-track"><div class="ccabit-progress-bar" style="width:0%"></div></div>
					<div class="ccabit-progress-text"></div>
					<div class="ccabit-actions"><button type="button" class="button" id="ccabit-discovery-continue">Tiếp tục quét</button><button type="button" class="button" id="ccabit-discovery-pause">Tạm dừng</button><button type="button" class="button button-link-delete" id="ccabit-discovery-cancel">Hủy lần quét</button></div>
				</div>
			</div>

			<div class="ccabit-card">
				<div class="ccabit-card-head"><div><h2>2. Chọn sản phẩm cần đồng bộ</h2><p>Lọc theo trạng thái, danh mục Abit hoặc tìm SKU/tên.</p></div><span id="ccabit-result-count" class="ccabit-muted"></span></div>
				<div class="ccabit-filters">
					<input type="search" id="ccabit-filter-search" placeholder="Tìm SKU, tên, Abit ID...">
					<select id="ccabit-filter-status"><option value="pending">Chờ đồng bộ</option><option value="error">Lỗi</option><option value="synced">Đã đồng bộ</option><option value="">Tất cả trạng thái</option></select>
					<select id="ccabit-filter-category"><option value="">Tất cả danh mục Abit</option></select>
					<button type="button" class="button" id="ccabit-apply-filter">Lọc</button>
				</div>
				<div class="ccabit-table-wrap"><table class="widefat fixed striped" id="ccabit-products-table"><thead><tr><td class="check-column"><input type="checkbox" id="ccabit-select-all-page"></td><th>SKU</th><th>Sản phẩm</th><th>Danh mục Abit</th><th>Giá</th><th>Ngày sửa</th><th>Trạng thái</th><th>Lỗi gần nhất</th></tr></thead><tbody><tr><td colspan="8">Đang tải...</td></tr></tbody></table></div>
				<div class="ccabit-pagination"><button type="button" class="button" id="ccabit-prev-page">← Trước</button><span id="ccabit-page-info"></span><button type="button" class="button" id="ccabit-next-page">Sau →</button></div>
			</div>

			<div class="ccabit-card">
				<h2>3. Tùy chọn & chạy đồng bộ</h2>
				<div class="ccabit-grid ccabit-grid-3">
					<label><span>Xử lý danh mục</span><select id="ccabit-category-mode"><option value="keep">Không thay đổi danh mục</option><option value="abit">Tạo/gán theo danh mục Abit</option><option value="fixed">Đưa vào một danh mục WooCommerce</option></select></label>
					<label><span>Danh mục WooCommerce cố định</span><select id="ccabit-fixed-category"><option value="0">— Chọn danh mục —</option><?php if ( ! is_wp_error( $wc_cats ) ) : foreach ( $wc_cats as $cat ) : ?><option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option><?php endforeach; endif; ?></select></label>
					<label><span>Sản phẩm mới</span><select id="ccabit-new-status"><option value="publish">Đăng ngay</option><option value="draft">Tạo bản nháp</option></select></label>
				</div>
				<div class="ccabit-sync-actions"><button type="button" class="button button-primary button-hero" id="ccabit-sync-selected">Đồng bộ sản phẩm đã chọn</button><button type="button" class="button button-secondary" id="ccabit-sync-filtered">Đồng bộ tất cả theo bộ lọc</button></div>
				<p class="description">Màu/Size hiện chưa được API danh sách trả thành field riêng nên plugin không tự đoán và không xóa attributes WooCommerce đang có. Tồn kho cũng chưa ghi cho tới khi diagnostic xác minh field số lượng hiện tại.</p>
			</div>

			<div class="ccabit-card ccabit-run-card" id="ccabit-run-panel" data-open-run='<?php echo esc_attr( wp_json_encode( $open_run ) ); ?>'>
				<div class="ccabit-card-head"><div><h2>Tiến độ đồng bộ</h2><p id="ccabit-run-current">Chưa có phiên đang chạy.</p></div><strong id="ccabit-run-percent">0%</strong></div>
				<div class="ccabit-progress-track ccabit-progress-large"><div class="ccabit-progress-bar" id="ccabit-run-bar" style="width:0%"></div></div>
				<div class="ccabit-run-metrics"><span>Đã xử lý <strong id="ccabit-run-processed">0</strong>/<strong id="ccabit-run-total">0</strong></span><span>Thành công <strong id="ccabit-run-success">0</strong></span><span>Lỗi <strong id="ccabit-run-failed">0</strong></span></div>
				<div class="ccabit-actions"><button type="button" class="button" id="ccabit-run-pause">Tạm dừng</button><button type="button" class="button button-primary" id="ccabit-run-resume">Tiếp tục</button><button type="button" class="button button-link-delete" id="ccabit-run-cancel">Hủy phiên</button></div>
				<div id="ccabit-run-log" class="ccabit-log"></div>
			</div>
		</div>
		<?php
	}

	private function request_filters() {
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		return array(
			'search'   => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'status'   => in_array( $status, array( 'pending', 'synced', 'error' ), true ) ? $status : '',
			'category' => isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '',
		);
	}

	private function describe_response( $response, $sample_key ) {
		$result = array( 'top_level_keys' => is_array( $response ) ? array_keys( $response ) : array() );
		$rows = $this->extract_rows( $response, true );
		$result['row_count_in_response'] = count( $rows );
		if ( $rows ) {
			$result[ $sample_key . '_keys' ] = array_keys( $rows[0] );
			$result[ $sample_key ] = $this->sanitize_sample( $rows[0] );
		}
		return $result;
	}

	private function extract_rows( $response, $product_rows = true ) {
		if ( ! is_array( $response ) || empty( $response ) ) return array();
		$first = reset( $response );
		if ( is_array( $first ) ) return array_values( $response );
		foreach ( array( 'data', 'products', 'items', 'result', 'list' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				$nested_first = reset( $response[ $key ] );
				if ( is_array( $nested_first ) ) return array_values( $response[ $key ] );
			}
		}
		return array();
	}

	public function sanitize_sample( $row ) {
		if ( ! is_array( $row ) ) return $row;
		$clean = array();
		foreach ( $row as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$text = (string) $value;
				$clean[ $key ] = strlen( $text ) > 500 ? substr( $text, 0, 500 ) . '…' : $value;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = array_slice( $value, 0, 10 );
			}
		}
		return $clean;
	}

	private function datetime_local_value( $value ) {
		if ( ! $value ) return '';
		$time = strtotime( $value );
		return $time ? date( 'Y-m-d\TH:i', $time ) : '';
	}

	private function require_capability() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'cunchici-abit' ) );
		}
	}
}
