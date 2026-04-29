<?php
/**
 * Таблицы плагина: логи и кэш объектов OSM для будущих расчётов эргономики.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_DB {

	public const SCHEMA_VERSION = 2;

	public const OPTION_VERSION = 'wscosm_db_version';

	public static function install(): void {
		self::create_tables();
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, true );
	}

	public static function maybe_upgrade(): void {
		$v = (int) get_option( self::OPTION_VERSION, 0 );
		if ( $v < self::SCHEMA_VERSION ) {
			self::create_tables();
			update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, true );
		}
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql_log = "CREATE TABLE {$p}wscosm_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_gmt datetime NOT NULL,
			level varchar(16) NOT NULL,
			scope varchar(48) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			city_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY level_created (level, created_gmt),
			KEY city_created (city_id, created_gmt)
		) $charset_collate;";

		$sql_obj = "CREATE TABLE {$p}wscosm_osm_object (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			city_id bigint(20) unsigned NOT NULL,
			object_key varchar(96) NOT NULL,
			osm_type varchar(16) NOT NULL DEFAULT '',
			osm_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wscosm_kind varchar(64) NOT NULL DEFAULT '',
			geom_type varchar(32) NOT NULL DEFAULT '',
			geometry_json longtext NOT NULL,
			properties_json longtext NOT NULL,
			bbox_s double NULL,
			bbox_w double NULL,
			bbox_n double NULL,
			bbox_e double NULL,
			fetched_gmt datetime NOT NULL,
			ergo_status varchar(24) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			UNIQUE KEY uk_city_object (city_id, object_key),
			KEY city_kind (city_id, wscosm_kind),
			KEY city_ergo (city_id, ergo_status),
			KEY city_id_id (city_id, id),
			KEY city_bbox (city_id, bbox_e, bbox_w, bbox_n, bbox_s)
		) $charset_collate;";

		dbDelta( $sql_log );
		dbDelta( $sql_obj );
	}
}
