<?php
/**
 * Plugin Name: Cún Chic × Abit
 * Plugin URI: https://github.com/LuongVanDuy/cunchici-abit
 * Description: Đồng bộ sản phẩm và dữ liệu vận hành giữa Abit và WooCommerce cho cunchici.vn.
 * Version: 0.2.2
 * Author: Cún Chic
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cunchici-abit
 */

defined( 'ABSPATH' ) || exit;

define( 'CUNCHICI_ABIT_VERSION', '0.2.2' );
define( 'CUNCHICI_ABIT_FILE', __FILE__ );
define( 'CUNCHICI_ABIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CUNCHICI_ABIT_URL', plugin_dir_url( __FILE__ ) );

require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-settings.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-db.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-api.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-product-mapper.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-sync-repository.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-discovery.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-product-sync.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-admin.php';
require_once CUNCHICI_ABIT_DIR . 'includes/class-cunchici-abit-audit.php';

function cunchici_abit_activate() {
	Cunchici_Abit_DB::install();
}
register_activation_hook( __FILE__, 'cunchici_abit_activate' );

/**
 * Bootstrap plugin services. Schema upgrades also run here because updating an
 * already-active plugin does not trigger register_activation_hook again.
 */
function cunchici_abit_bootstrap() {
	Cunchici_Abit_DB::maybe_upgrade();

	$settings   = new Cunchici_Abit_Settings();
	$api        = new Cunchici_Abit_API( $settings );
	$repository = new Cunchici_Abit_Sync_Repository();

	// 0.2.2 catalog rule verified by live CI against the real Abit shop:
	// Admin shows status=1 products, while the API also returns status=0 rows.
	// Normalize old candidates once so inactive rows cannot enter Sync Runs.
	$repository->normalize_inactive_candidates_once();

	$discovery = new Cunchici_Abit_Discovery( $settings, $api, $repository );
	$sync      = new Cunchici_Abit_Product_Sync( $settings, $api, $repository );

	new Cunchici_Abit_Admin( $settings, $api, $sync, $discovery, $repository );
	new Cunchici_Abit_Audit( $api );
}
add_action( 'plugins_loaded', 'cunchici_abit_bootstrap' );
