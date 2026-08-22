<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics for reconciling Abit API totals with the Abit admin UI.
 *
 * This class never writes WooCommerce products and never stores API payloads.
 */
class Cunchici_Abit_Audit {
	private $api;

	public function __construct( Cunchici_Abit_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'wp_ajax_cunchici_abit_audit_products_page', array( $this, 'ajax_products_page' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'cunchici-abit',
			'Đối soát sản phẩm Abit',
			'Đối soát API',
			'manage_woocommerce',
			'cunchici-abit-audit',
			array( $this, 'render_page' )
		);
	}

	public function ajax_products_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Bạn không có quyền thực hiện thao tác này.' ), 403 );
		}

		check_ajax_referer( 'cunchici_abit_audit', 'nonce' );

		$page  = isset( $_POST['page_num'] ) ? absint( $_POST['page_num'] ) : 0;
		$limit = 100;

		if ( $page > 10000 ) {
			wp_send_json_error( array( 'message' => 'Dừng bảo vệ: page vượt quá giới hạn.' ), 400 );
		}

		$response = $this->api->list_products( $page, $limit );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$rows = $this->extract_rows( $response );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'productid'    => isset( $row['productid'] ) ? (string) $row['productid'] : '',
				'productcode'  => isset( $row['productcode'] ) ? (string) $row['productcode'] : '',
				'productname'  => isset( $row['productname'] ) ? (string) $row['productname'] : '',
				'status'       => array_key_exists( 'status', $row ) ? $row['status'] : null,
				'modifiedtime' => isset( $row['modifiedtime'] ) ? (string) $row['modifiedtime'] : '',
			);
		}

		wp_send_json_success(
			array(
				'page'      => $page,
				'count'     => count( $out ),
				'rows'      => $out,
				'has_more'  => count( $out ) >= $limit,
				'next_page' => $page + 1,
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'cunchici-abit' ) );
		}

		$nonce = wp_create_nonce( 'cunchici_abit_audit' );
		?>
		<div class="wrap">
			<h1>Cún Chic × Abit — Đối soát API</h1>
			<p>Trang này đọc toàn bộ API <code>listProductsforPartner</code> để đối chiếu số lượng với màn quản trị Abit. <strong>Không tạo/cập nhật/xóa sản phẩm WooCommerce.</strong></p>

			<div style="max-width:1100px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:18px">
				<h2 style="margin-top:0">Đếm và phân tích sản phẩm</h2>
				<p>Nhập tổng số sản phẩm bạn đang thấy trong admin Abit để plugin tự tính phần chênh lệch.</p>
				<label for="ccabit-audit-expected"><strong>Số lượng trong admin Abit</strong></label>
				<input type="number" min="0" id="ccabit-audit-expected" value="2579" style="width:130px;margin:0 12px">
				<button type="button" class="button button-primary" id="ccabit-audit-run">Chạy đối soát toàn bộ API</button>
				<button type="button" class="button" id="ccabit-audit-stop" disabled>Dừng</button>
				<p id="ccabit-audit-progress" style="margin-top:16px"></p>
			</div>

			<div id="ccabit-audit-result" style="display:none;max-width:1100px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:18px">
				<h2 style="margin-top:0">Kết quả đối soát</h2>
				<pre id="ccabit-audit-summary" style="white-space:pre-wrap;background:#f6f7f7;padding:14px;border:1px solid #dcdcde"></pre>

				<h3>Sản phẩm có status khác 1</h3>
				<p>Danh sách này rất hữu ích nếu tổng <code>status = 1</code> trùng đúng số lượng trong admin Abit.</p>
				<pre id="ccabit-audit-inactive" style="max-height:420px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:14px;border:1px solid #dcdcde"></pre>

				<h3>SKU trùng</h3>
				<pre id="ccabit-audit-duplicates" style="max-height:420px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:14px;border:1px solid #dcdcde"></pre>
			</div>
		</div>

		<script>
		(function () {
			'use strict';

			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const runButton = document.getElementById('ccabit-audit-run');
			const stopButton = document.getElementById('ccabit-audit-stop');
			const progress = document.getElementById('ccabit-audit-progress');
			const resultBox = document.getElementById('ccabit-audit-result');
			const summary = document.getElementById('ccabit-audit-summary');
			const inactiveBox = document.getElementById('ccabit-audit-inactive');
			const duplicatesBox = document.getElementById('ccabit-audit-duplicates');
			let stopRequested = false;

			function safeText(value) {
				return value == null ? '' : String(value);
			}

			async function fetchPage(page) {
				const body = new URLSearchParams({
					action: 'cunchici_abit_audit_products_page',
					nonce: nonce,
					page_num: String(page)
				});
				const response = await fetch(ajaxUrl, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
					body: body
				});
				const json = await response.json();
				if (!json.success) {
					throw new Error(json.data && json.data.message ? json.data.message : 'Không đọc được API Abit.');
				}
				return json.data;
			}

			stopButton.addEventListener('click', function () {
				stopRequested = true;
				stopButton.disabled = true;
				progress.textContent = 'Đang dừng sau request hiện tại...';
			});

			runButton.addEventListener('click', async function () {
				stopRequested = false;
				runButton.disabled = true;
				stopButton.disabled = false;
				resultBox.style.display = 'none';
				progress.textContent = 'Đang bắt đầu đối soát...';

				const productIds = new Map();
				const skus = new Map();
				const statusCounts = new Map();
				const inactiveRows = [];
				let totalRows = 0;
				let emptySku = 0;
				let page = 0;

				try {
					while (!stopRequested && page <= 10000) {
						progress.textContent = 'Đang đọc API trang ' + page + '… Đã nhận ' + totalRows + ' dòng.';
						const data = await fetchPage(page);

						for (const row of data.rows || []) {
							totalRows++;

							const id = safeText(row.productid).trim();
							if (id) {
								if (!productIds.has(id)) productIds.set(id, []);
								productIds.get(id).push(row);
							}

							const sku = safeText(row.productcode).trim();
							if (!sku) {
								emptySku++;
							} else {
								if (!skus.has(sku)) skus.set(sku, []);
								skus.get(sku).push(row);
							}

							const status = row.status == null ? 'null' : safeText(row.status);
							statusCounts.set(status, (statusCounts.get(status) || 0) + 1);
							if (status !== '1') inactiveRows.push(row);
						}

						if (!data.has_more || Number(data.count) === 0) break;
						page = Number(data.next_page);
					}

					if (stopRequested) {
						progress.textContent = 'Đã dừng. Kết quả chưa đầy đủ nên không dùng để đối chiếu.';
						return;
					}

					const duplicateIdGroups = Array.from(productIds.entries()).filter(([, rows]) => rows.length > 1);
					const duplicateSkuGroups = Array.from(skus.entries()).filter(([, rows]) => rows.length > 1);
					const expected = Math.max(0, Number(document.getElementById('ccabit-audit-expected').value || 0));
					const uniqueIds = productIds.size;
					const statusOne = Number(statusCounts.get('1') || 0);
					const delta = expected ? uniqueIds - expected : null;

					const statusObject = {};
					Array.from(statusCounts.entries()).sort((a, b) => a[0].localeCompare(b[0])).forEach(([key, value]) => { statusObject[key] = value; });

					let conclusion = '';
					if (expected && statusOne === expected && uniqueIds !== expected) {
						conclusion = 'KHẢ NĂNG RẤT CAO: admin Abit đang đếm status=1, còn API trả thêm các trạng thái khác.';
					} else if (duplicateIdGroups.length) {
						conclusion = 'API có productid lặp giữa các page. Cần sửa pagination/dedup trước khi sync.';
					} else if (expected && uniqueIds !== expected) {
						conclusion = 'API trả ' + Math.abs(delta) + ' productid ' + (delta > 0 ? 'nhiều hơn' : 'ít hơn') + ' admin. Xem status và SKU trùng bên dưới để xác định nguyên nhân.';
					} else {
						conclusion = 'Số unique productid API khớp với số admin đã nhập.';
					}

					summary.textContent = JSON.stringify({
						api_total_rows: totalRows,
						api_unique_product_ids: uniqueIds,
						admin_expected_count: expected || null,
						difference_unique_vs_admin: delta,
						status_counts: statusObject,
						status_not_1: inactiveRows.length,
						duplicate_productid_groups: duplicateIdGroups.length,
						unique_non_empty_skus: skus.size,
						duplicate_sku_groups: duplicateSkuGroups.length,
						empty_sku_rows: emptySku,
						conclusion: conclusion
					}, null, 2);

					inactiveBox.textContent = inactiveRows.length
						? inactiveRows.slice(0, 500).map(row => '[' + safeText(row.status) + '] ' + safeText(row.productcode) + ' | #' + safeText(row.productid) + ' | ' + safeText(row.productname)).join('\n')
						: 'Không có sản phẩm status khác 1.';

					duplicatesBox.textContent = duplicateSkuGroups.length
						? duplicateSkuGroups.slice(0, 500).map(([sku, rows]) => sku + ' (' + rows.length + '): ' + rows.map(row => '#' + safeText(row.productid)).join(', ')).join('\n')
						: 'Không có SKU trùng.';

					resultBox.style.display = 'block';
					progress.textContent = 'Hoàn tất: ' + totalRows + ' dòng API / ' + uniqueIds + ' unique productid.';
				} catch (error) {
					progress.textContent = 'Lỗi đối soát: ' + error.message;
				} finally {
					runButton.disabled = false;
					stopButton.disabled = true;
				}
			});
		})();
		</script>
		<?php
	}

	private function extract_rows( $response ) {
		if ( ! is_array( $response ) || empty( $response ) ) {
			return array();
		}

		$first = reset( $response );
		if ( is_array( $first ) && ( isset( $first['productid'] ) || isset( $first['productcode'] ) ) ) {
			return array_values( $response );
		}

		foreach ( array( 'data', 'products', 'items', 'result', 'list' ) as $key ) {
			if ( ! isset( $response[ $key ] ) || ! is_array( $response[ $key ] ) ) {
				continue;
			}

			$nested_first = reset( $response[ $key ] );
			if ( is_array( $nested_first ) ) {
				return array_values( $response[ $key ] );
			}
		}

		return array();
	}
}
