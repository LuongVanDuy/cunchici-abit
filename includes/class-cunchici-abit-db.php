<?php

defined( 'ABSPATH' ) || exit;

class Cunchici_Abit_DB {
	const SCHEMA_VERSION = '0.2.0';
	const SCHEMA_OPTION  = 'cunchici_abit_db_version';

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'cunchici_abit_items';
	}

	public static function runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'cunchici_abit_runs';
	}

	public static function maybe_upgrade() {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = self::items_table();
		$runs    = self::runs_table();

		$sql_items = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			abit_product_id varchar(191) NOT NULL,
			sku varchar(191) NOT NULL DEFAULT '',
			product_name text NOT NULL,
			category_label varchar(255) NOT NULL DEFAULT '',
			price decimal(20,6) NOT NULL DEFAULT 0,
			created_time datetime NULL,
			modified_time datetime NULL,
			payload_hash char(40) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			sync_status varchar(20) NOT NULL DEFAULT 'pending',
			woo_product_id bigint(20) unsigned NULL,
			last_error text NULL,
			discovered_at datetime NOT NULL,
			synced_at datetime NULL,
			queue_run_id bigint(20) unsigned NULL,
			queue_status varchar(20) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY abit_product_id (abit_product_id),
			KEY sku (sku),
			KEY sync_status (sync_status),
			KEY category_label (category_label),
			KEY queue_run_id (queue_run_id),
			KEY modified_time (modified_time)
		) {$charset};";

		$sql_runs = "CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_type varchar(20) NOT NULL DEFAULT 'sync',
			status varchar(20) NOT NULL DEFAULT 'queued',
			options longtext NULL,
			total int(11) unsigned NOT NULL DEFAULT 0,
			processed int(11) unsigned NOT NULL DEFAULT 0,
			succeeded int(11) unsigned NOT NULL DEFAULT 0,
			failed int(11) unsigned NOT NULL DEFAULT 0,
			current_item_id bigint(20) unsigned NULL,
			current_product_name text NULL,
			created_at datetime NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY run_type (run_type)
		) {$charset};";

		dbDelta( $sql_items );
		dbDelta( $sql_runs );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}
}
