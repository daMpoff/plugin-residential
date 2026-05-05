<?php
/**
 * One-time cleanup script: delete all courtyard yard posts.
 *
 * Usage:
 *   php tools/cleanup_yards_once.php --wp-load="C:/xampp/htdocs/wordpress/wp-load.php"
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$wp_load = '';
foreach ( $argv as $arg ) {
	if ( strpos( $arg, '--wp-load=' ) === 0 ) {
		$wp_load = substr( $arg, 10 );
		break;
	}
}

if ( $wp_load === '' || ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Pass --wp-load=... with full path to wp-load.php\n" );
	exit( 2 );
}

require_once $wp_load;

if ( ! function_exists( 'get_posts' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 3 );
}

$post_type = class_exists( 'WSErgo_CPT' ) ? WSErgo_CPT::SLUG_YARD : 'wsp_yard';

$ids = get_posts(
	[
		'post_type'              => $post_type,
		'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future' ],
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	]
);

$deleted = 0;
foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
	if ( wp_delete_post( $post_id, true ) ) {
		$deleted++;
	}
}

echo "Deleted yard posts: {$deleted}\n";
