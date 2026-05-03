<?php
/**
 * CLI helper: export stored OSM objects for a city to GeoJSON.
 *
 * Usage:
 *   C:\xampp\php\php.exe tools/export-city-osm.php 290 storage/berezniki-osm.geojson
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$city_id = isset( $argv[1] ) ? (int) $argv[1] : 0;
$out = isset( $argv[2] ) ? (string) $argv[2] : '';
if ( $city_id <= 0 || $out === '' ) {
	fwrite( STDERR, "Usage: php tools/export-city-osm.php <city_id> <output.geojson>\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! class_exists( 'WSCOSM_Feature_Store' ) ) {
	fwrite( STDERR, "WSCOSM_Feature_Store is not available.\n" );
	exit( 1 );
}

$fc = WSCOSM_Feature_Store::get_feature_collection_for_city( $city_id, 100000 );
$json = wp_json_encode( $fc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
if ( ! is_string( $json ) ) {
	fwrite( STDERR, "Failed to encode GeoJSON.\n" );
	exit( 1 );
}

$dir = dirname( $out );
if ( $dir !== '' && $dir !== '.' && ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}

file_put_contents( $out, $json );
echo 'Exported ' . count( $fc['features'] ?? [] ) . " features to {$out}\n";
