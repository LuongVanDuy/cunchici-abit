<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_Admin {
	private $settings;
	private $api;
	private $sync;

	public function __construct( Cunchici_Abit_Settings $settings, Cunchici_Abit_API $api, Cunchici_Abit_Product_Sync $sync ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->sync     = $sync;

		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'wp_ajax_cunchici_abit_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_cunchici_abit_sync_products', array( $this, 'ajax_sync_products' ) );
	}

	public function register_menu() {
		$capability = 'manage_woocommerce';

		add_menu_page(
			'Cún Chic × Abit',
			'Cún Chic × Abit',
			$capability,
			'cunchici-abit',
			array( $this, 'render_settings_page' ),
			'dashicons-update-alt',
			56
		);

		add_submenu_page(
			'cunchici-abit',
			'Cấu hình Abit',
			'Cấu hình',
			$capability,
			'cunchici-abit',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'cunchici-abit',
			'Đồng bộ sản phẩm Abit',
			'Đồng bộ sản phẩm',
			$capability,
			'cunchici-abit-sync',
			array( $this, 'render_sync_page' )
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
		$response = $this->api->list_products( 0, 1 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Kết nối Abit thành công. API danh sách sản phẩm đã phản hồi.',
				'response_shape' => $this->describe_response_shape( $response ),
			)
		);
	}

	public function ajax_sync_products() {
		$this->guard_ajax();
		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;

		if ( $page > 5000 ) {
			wp_send_json_error( array( 'message' => 'Dừng bảo vệ: số trang vượt quá 5000.' ) );
		}

		$result = $this->sync->sync_page( $page );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	public function render_settings_page() {
		$this->require_capability();
		$settings = $this->settings->all();
		?>
		<div class="wrap">
			<h1>Cún Chic × Abit — Cấu hình</h1>
			<p>Cấu hình kết nối Abit trước khi chạy đồng bộ. Access Token được lưu trong WordPress options và không hiển thị công khai ở frontend.</p>

			<?php settings_errors(); ?>
			<form method="post" action="options.php" style="max-width:900px">
				<?php settings_fields( 'cunchici_abit_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cunchici-abit-base-url">Abit Base URL</label></th>
						<td><input id="cunchici-abit-base-url" class="regular-text code" type="url" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[base_url]" value="<?php echo esc_attr( $settings['base_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cunchici-abit-token">Access Token</label></th>
						<td>
							<input id="cunchici-abit-token" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[access_token]" value="<?php echo esc_attr( $settings['access_token'] ); ?>">
							<p class="description">Token Abit. Không đưa token này vào source code/GitHub.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cunchici-abit-partner">Partner Name / Mã shop</label></th>
						<td><input id="cunchici-abit-partner" class="regular-text" type="text" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[partner_name]" value="<?php echo esc_attr( $settings['partner_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cunchici-abit-store">Product Store ID / Kho Abit</label></th>
						<td>
							<input id="cunchici-abit-store" class="regular-text" type="text" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[productstoreid]" value="<?php echo esc_attr( $settings['productstoreid'] ); ?>">
							<p class="description">Dùng cho API tồn kho. Nếu để trống, sản phẩm vẫn sync nhưng tồn kho sẽ được bỏ qua.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cunchici-abit-limit">Sản phẩm mỗi trang</label></th>
						<td><input id="cunchici-abit-limit" type="number" min="1" max="500" name="<?php echo esc_attr( Cunchici_Abit_Settings::OPTION_KEY ); ?>[sync_limit]" value="<?php echo esc_attr( $settings['sync_limit'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( 'Lưu cấu hình' ); ?>
			</form>

			<hr>
			<h2>Kiểm tra kết nối</h2>
			<p>Nút này chỉ gọi trang đầu tiên với 1 sản phẩm để kiểm tra token/partner và cấu trúc response; không ghi dữ liệu vào WooCommerce.</p>
			<button type="button" class="button button-secondary" id="cunchici-abit-test">Test kết nối Abit</button>
			<pre id="cunchici-abit-test-result" style="max-width:900px;white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ccd0d4;margin-top:12px;display:none"></pre>
		</div>
		<?php $this->render_ajax_script( false ); ?>
		<?php
	}

	public function render_sync_page() {
		$this->require_capability();
		?>
		<div class="wrap">
			<h1>Cún Chic × Abit — Đồng bộ sản phẩm</h1>
			<p>Mỗi dòng sản phẩm Abit được lưu thành <strong>một WooCommerce simple product</strong>. Plugin không tạo variable product và không gom các dòng cùng mẫu thành cha/con.</p>
			<ul style="list-style:disc;padding-left:22px">
				<li>SKU ← mã sản phẩm Abit.</li>
				<li>Giá ← giá sản phẩm Abit.</li>
				<li>Màu sắc và Size ← custom attributes hiển thị trên simple product.</li>
				<li>Tồn kho ← API tồn kho theo Product Store ID đã cấu hình.</li>
				<li>Upsert bằng Abit Product ID, fallback theo SKU để hạn chế tạo trùng.</li>
			</ul>

			<?php if ( ! $this->settings->is_configured() ) : ?>
				<div class="notice notice-warning inline"><p>Chưa đủ Access Token/Partner Name. Hãy cấu hình trước khi chạy.</p></div>
			<?php endif; ?>
			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-error inline"><p>WooCommerce chưa hoạt động. Không thể đồng bộ sản phẩm.</p></div>
			<?php endif; ?>

			<p><button type="button" class="button button-primary button-hero" id="cunchici-abit-sync" <?php disabled( ! $this->settings->is_configured() || ! class_exists( 'WooCommerce' ) ); ?>>Bắt đầu đồng bộ</button></p>
			<div id="cunchici-abit-progress" style="max-width:900px;margin-top:16px"></div>
			<pre id="cunchici-abit-sync-log" style="max-width:900px;min-height:180px;max-height:520px;overflow:auto;white-space:pre-wrap;background:#111;color:#eee;padding:14px"></pre>
		</div>
		<?php $this->render_ajax_script( true ); ?>
		<?php
	}

	private function render_ajax_script( $include_sync ) {
		$nonce = wp_create_nonce( 'cunchici_abit_admin' );
		?>
		<script>
		(function(){
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const testButton = document.getElementById('cunchici-abit-test');
			if (testButton) {
				testButton.addEventListener('click', async function(){
					const out = document.getElementById('cunchici-abit-test-result');
					out.style.display = 'block'; out.textContent = 'Đang kiểm tra...'; testButton.disabled = true;
					try {
						const body = new URLSearchParams({action:'cunchici_abit_test_connection', nonce});
						const res = await fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
						const json = await res.json();
						out.textContent = json.success ? json.data.message + '\n\nCấu trúc response:\n' + JSON.stringify(json.data.response_shape, null, 2) : 'Lỗi: ' + (json.data && json.data.message ? json.data.message : 'Không xác định');
					} catch (e) { out.textContent = 'Lỗi kết nối: ' + e.message; }
					finally { testButton.disabled = false; }
				});
			}

			<?php if ( $include_sync ) : ?>
			const syncButton = document.getElementById('cunchici-abit-sync');
			if (syncButton) syncButton.addEventListener('click', async function(){
				if (!window.confirm('Bắt đầu đồng bộ sản phẩm Abit vào WooCommerce?')) return;
				syncButton.disabled = true;
				const log = document.getElementById('cunchici-abit-sync-log');
				const progress = document.getElementById('cunchici-abit-progress');
				let page = 0, totals = {fetched:0,created:0,updated:0,failed:0};
				log.textContent = '';
				try {
					while (page <= 5000) {
						progress.textContent = 'Đang đồng bộ trang ' + page + '...';
						const body = new URLSearchParams({action:'cunchici_abit_sync_products', nonce, page:String(page)});
						const res = await fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
						const json = await res.json();
						if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'Sync thất bại');
						const d = json.data;
						['fetched','created','updated','failed'].forEach(k => totals[k] += Number(d[k] || 0));
						log.textContent += `Trang ${d.page}: lấy ${d.fetched}, tạo ${d.created}, cập nhật ${d.updated}, lỗi ${d.failed}\n`;
						if (d.stock_warning) log.textContent += '  Cảnh báo tồn kho: ' + d.stock_warning + '\n';
						if (Array.isArray(d.errors)) d.errors.forEach(x => log.textContent += '  Lỗi [' + (x.sku || x.abit_product_id || '?') + ']: ' + x.message + '\n');
						log.scrollTop = log.scrollHeight;
						if (!d.has_more || Number(d.fetched) === 0) break;
						page = Number(d.next_page);
					}
					progress.innerHTML = '<strong>Hoàn tất.</strong> Tổng: lấy ' + totals.fetched + ', tạo ' + totals.created + ', cập nhật ' + totals.updated + ', lỗi ' + totals.failed + '.';
				} catch(e) {
					progress.innerHTML = '<strong>Đã dừng do lỗi:</strong> ' + String(e.message || e);
					log.textContent += '\nDỪNG: ' + String(e.message || e) + '\n';
				} finally { syncButton.disabled = false; }
			});
			<?php endif; ?>
		})();
		</script>
		<?php
	}

	private function describe_response_shape( $response ) {
		if ( ! is_array( $response ) ) {
			return array( 'type' => gettype( $response ) );
		}
		$shape = array( 'top_level_keys' => array_keys( $response ) );
		$sample = $this->find_first_row( $response );
		if ( is_array( $sample ) ) {
			$shape['first_product_keys'] = array_keys( $sample );
		}
		return $shape;
	}

	private function find_first_row( $value ) {
		if ( ! is_array( $value ) ) return null;
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['productid'] ) || isset( $item['productcode'] ) || isset( $item['productname'] ) ) return $item;
				$found = $this->find_first_row( $item );
				if ( $found ) return $found;
			}
		}
		return null;
	}

	private function require_capability() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'cunchici-abit' ) );
		}
	}
}
