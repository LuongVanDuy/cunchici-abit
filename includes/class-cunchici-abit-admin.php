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

	/**
	 * Chẩn đoán API, không ghi dữ liệu WooCommerce.
	 */
	public function ajax_test_connection() {
		$this->guard_ajax();

		$product_response = $this->api->list_products( 0, 1 );
		if ( is_wp_error( $product_response ) ) {
			wp_send_json_error( array( 'message' => $product_response->get_error_message() ) );
		}

		$diagnostics = array(
			'products' => $this->describe_response( $product_response, 'first_product' ),
		);

		$store_response = $this->api->list_stores( 0, 100 );
		if ( is_wp_error( $store_response ) ) {
			$diagnostics['stores'] = array( 'error' => $store_response->get_error_message() );
		} else {
			$store_rows = $this->extract_rows( $store_response );
			$diagnostics['stores'] = array(
				'top_level_keys' => is_array( $store_response ) ? array_keys( $store_response ) : array(),
				'count'          => count( $store_rows ),
				'rows'           => array_map( array( $this, 'sanitize_sample' ), array_slice( $store_rows, 0, 20 ) ),
			);
		}

		$store_id = trim( (string) $this->settings->get( 'productstoreid', '' ) );
		if ( '' === $store_id ) {
			$diagnostics['stock'] = array(
				'message' => 'Chưa cấu hình Product Store ID. Chọn ID từ danh sách stores rồi lưu cấu hình để test tồn kho.',
			);
		} else {
			$stock_response = $this->api->list_products_with_stock( 0, 1 );
			if ( is_wp_error( $stock_response ) ) {
				$diagnostics['stock'] = array(
					'productstoreid' => $store_id,
					'error'          => $stock_response->get_error_message(),
				);
			} else {
				$diagnostics['stock'] = array_merge(
					array( 'productstoreid' => $store_id ),
					$this->describe_response( $stock_response, 'first_stock' )
				);
			}
		}

		wp_send_json_success(
			array(
				'message'     => 'Kết nối Abit thành công. Đây là dữ liệu chẩn đoán; chưa ghi gì vào WooCommerce.',
				'diagnostics' => $diagnostics,
			)
		);
	}

	/**
	 * Emergency safety lock.
	 *
	 * Sync stays disabled until the real color/size and current-stock fields are
	 * verified from the shop payload. This protects production from accidental
	 * writes while integration mapping is incomplete.
	 */
	public function ajax_sync_products() {
		$this->guard_ajax();
		wp_send_json_error(
			array(
				'message' => 'Đồng bộ đang tạm khóa để xác minh mapping màu, size và tồn kho. Nút sync sẽ được mở lại sau khi test payload hoàn tất.',
			),
			423
		);
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
							<p class="description">Sau khi cập nhật plugin, bấm Test kết nối để xem danh sách kho Abit và lấy đúng ID.</p>
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
			<h2>Kiểm tra kết nối & payload</h2>
			<p>Nút này chỉ đọc 1 sản phẩm, danh sách kho và (nếu đã nhập Product Store ID) 1 record tồn kho. <strong>Không ghi dữ liệu vào WooCommerce.</strong></p>
			<button type="button" class="button button-secondary" id="cunchici-abit-test">Test kết nối Abit</button>
			<pre id="cunchici-abit-test-result" style="max-width:1000px;white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ccd0d4;margin-top:12px;display:none"></pre>
		</div>
		<?php $this->render_ajax_script(); ?>
		<?php
	}

	public function render_sync_page() {
		$this->require_capability();
		?>
		<div class="wrap">
			<h1>Cún Chic × Abit — Đồng bộ sản phẩm</h1>
			<div class="notice notice-warning inline">
				<p><strong>Đồng bộ đang tạm khóa an toàn.</strong> Payload danh sách sản phẩm đã xác minh nhưng chưa có field màu/size và chưa xác minh field tồn kho hiện tại. Hãy hoàn tất bước diagnostic trước khi mở full sync.</p>
			</div>
			<p>Mỗi dòng Abit sẽ là một WooCommerce <strong>simple product</strong>; không tạo variable product, không gom cha/con.</p>
			<p><button type="button" class="button button-primary button-hero" disabled>Đồng bộ đang khóa</button></p>
		</div>
		<?php
	}

	private function render_ajax_script() {
		$nonce = wp_create_nonce( 'cunchici_abit_admin' );
		?>
		<script>
		(function(){
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const button = document.getElementById('cunchici-abit-test');
			if (!button) return;

			button.addEventListener('click', async function(){
				const out = document.getElementById('cunchici-abit-test-result');
				out.style.display = 'block';
				out.textContent = 'Đang kiểm tra...';
				button.disabled = true;

				try {
					const body = new URLSearchParams({action:'cunchici_abit_test_connection', nonce});
					const res = await fetch(ajaxUrl, {
						method:'POST',
						headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
						body
					});
					const json = await res.json();
					out.textContent = json.success
						? json.data.message + '\n\n' + JSON.stringify(json.data.diagnostics, null, 2)
						: 'Lỗi: ' + (json.data && json.data.message ? json.data.message : 'Không xác định');
				} catch (e) {
					out.textContent = 'Lỗi kết nối: ' + e.message;
				} finally {
					button.disabled = false;
				}
			});
		})();
		</script>
		<?php
	}

	private function describe_response( $response, $sample_key ) {
		$result = array(
			'top_level_keys' => is_array( $response ) ? array_keys( $response ) : array(),
		);

		$rows = $this->extract_rows( $response );
		$result['row_count_in_response'] = count( $rows );

		if ( ! empty( $rows ) ) {
			$result[ $sample_key . '_keys' ] = array_keys( $rows[0] );
			$result[ $sample_key ]           = $this->sanitize_sample( $rows[0] );
		}

		return $result;
	}

	private function extract_rows( $response ) {
		if ( ! is_array( $response ) || empty( $response ) ) {
			return array();
		}

		$first = reset( $response );
		if ( is_array( $first ) ) {
			return array_values( $response );
		}

		foreach ( array( 'data', 'products', 'items', 'result', 'list' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				$nested_first = reset( $response[ $key ] );
				if ( is_array( $nested_first ) ) {
					return array_values( $response[ $key ] );
				}
			}
		}

		return array();
	}

	public function sanitize_sample( $row ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}

		$clean = array();
		foreach ( $row as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$text = (string) $value;
				$clean[ $key ] = strlen( $text ) > 500 ? substr( $text, 0, 500 ) . '…' : $value;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = array_slice( $value, 0, 10 );
			} else {
				$clean[ $key ] = gettype( $value );
			}
		}

		return $clean;
	}

	private function require_capability() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'cunchici-abit' ) );
		}
	}
}
