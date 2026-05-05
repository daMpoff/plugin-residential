<?php
/**
 * Plugin Name:       WorldStat — Courtyard OSM Map
 * Plugin URI:        https://example.com/worldstat-courtyard-osm
 * Description:       Расширение World Statistics: карта придомовой среды города (OSM + полигоны из эргономики), без правок базовых плагинов.
 * Version:           1.2.24
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform, worldstat-cities
 * Author:            Ergonosphera
 * License:           GPL v2 or later
 * Text Domain:       worldstat-courtyard-osm
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSCOSM_VERSION', '1.2.25' );
define( 'WSCOSM_FILE', __FILE__ );
define( 'WSCOSM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSCOSM_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! class_exists( 'WorldStat_Core' ) ) {
			echo '<div class="notice notice-error"><p><strong>WorldStat Courtyard OSM</strong> requires <strong>World Statistics Platform</strong>.</p></div>';
		}
		if ( ! class_exists( 'WSCities_CPT' ) ) {
			echo '<div class="notice notice-error"><p><strong>WorldStat Courtyard OSM</strong> requires <strong>WorldStat Cities</strong>.</p></div>';
		}
		if ( ! class_exists( 'WSErgo_Data' ) ) {
			echo '<div class="notice notice-warning"><p><strong>WorldStat Courtyard OSM</strong>: activate <strong>WorldStat Ergonomics</strong> to show saved courtyard polygons.</p></div>';
		}
	}
);

require_once WSCOSM_DIR . 'includes/class-wscosm-db.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-log.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-geo.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-yard-osm-raw.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-yard-at.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-scan-progress.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-feature-store.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-overpass.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-rest.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-city-map.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-country-tab.php';
require_once WSCOSM_DIR . 'includes/class-wscosm-admin.php';

register_activation_hook(
	WSCOSM_FILE,
	static function (): void {
		WSCOSM_DB::install();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		WSCOSM_DB::maybe_upgrade();
	},
	5
);

if ( is_admin() ) {
	WSCOSM_Admin::init();
}

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'worldstat-courtyard-osm',
			false,
			dirname( plugin_basename( WSCOSM_FILE ) ) . '/languages'
		);
	},
	0
);

add_action(
	'worldstat_init',
	static function () {
		if ( ! class_exists( 'WorldStat_Extensions' ) ) {
			return;
		}

		WorldStat_Extensions::register(
			[
				'id'                => 'courtyard_osm',
				'name'              => 'Courtyard OSM Map',
				'version'           => WSCOSM_VERSION,
				'author'            => 'Ergonosphera',
				'description'       => 'Карта придомовой среды: OSM (скамейки, освещение, дорожки) и полигоны придомовых из эргономики; вкладка страны.',
				'icon'              => 'dashicons-location-alt',
				'requires_platform' => '1.0.0',
				'depends'           => [ 'cities' ],
			]
		);

		WorldStat_Extensions::add_country_tab(
			'courtyard_osm',
			[
				'title'    => 'Придомовые территории',
				'icon'     => 'dashicons-location',
				'callback' => [ 'WSCOSM_Country_Tab', 'render_tab_shell' ],
				'priority' => 30,
			]
		);
	},
	40
);

add_action( 'rest_api_init', [ 'WSCOSM_REST', 'register' ] );
add_action( 'wp_enqueue_scripts', [ 'WSCOSM_City_Map', 'enqueue_assets' ], 25 );
add_action( 'wp_enqueue_scripts', [ 'WSCOSM_Country_Tab', 'enqueue_country_assets' ], 30 );
add_filter( 'worldstat_ui_map_opts', [ 'WSCOSM_City_Map', 'filter_worldstat_ui_map_opts' ], 10, 1 );
add_action( 'wsp_city_after_location_map', [ 'WSCOSM_City_Map', 'render_legend_after_location_map' ], 10, 2 );
add_action( 'worldstat_after_city', [ 'WSCOSM_City_Map', 'render_section' ], 20, 2 );
add_action( 'wp_ajax_wscosm_country_city', [ 'WSCOSM_Country_Tab', 'ajax_city_yards' ] );
add_action( 'wp_ajax_nopriv_wscosm_country_city', [ 'WSCOSM_Country_Tab', 'ajax_city_yards' ] );
