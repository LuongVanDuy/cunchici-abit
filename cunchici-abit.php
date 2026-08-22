<?php
/**
 * Plugin Name: Cún Chic × Abit
 * Plugin URI: https://github.com/LuongVanDuy/cunchici-abit
 * Description: Đồng bộ sản phẩm và dữ liệu vận hành giữa Abit và WooCommerce cho cunchici.vn.
 * Version: 0.1.1
 * Author: Cún Chic
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cunchici-abit
 */

defined( 'ABSPATH' ) || exit;

define( 'CUNCHICI_ABIT_VERSION', '0.1.1' );
define( 'CUNCHICI_ABIT_FILE', __FILE__ );
define( 'CUNCHICI_ABIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CUNCHICI_ABIT_URL', plugin_dir_url( __FILE__ ) );

require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-settings.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-api.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-product-mapper.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-product-sync.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-admin.php';

/**
 * Bootstrap plugin services after all plugins have loaded.
 *
 * WooCommerce is intentionally checked at runtime instead of activation time so
 * administrators can still open the Abit settings screen while troubleshooting.
 */
function cunchici_abit_bootstrap() {
	$settings = new Cunchici_Abit_Settings();
	$api      = new Cunchici_Abit_API( $settings );
	$sync     = new Cunchici_Abit_Product_Sync( $settings, $api );

	new Cunchici_Abit_Admin( $settings, $api, $sync );
}
add_action( 'plugins_loaded', 'cunchici_abit_bootstrap' );
