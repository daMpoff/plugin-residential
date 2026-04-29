<?php
/**
 * Прогресс долгого сканирования OSM (transient для опроса с фронта).
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Scan_Progress {

	public const TRANSIENT_PREFIX = 'wscosm_sp_';

	public const TTL = 300;

	/**
	 * @param mixed $raw Параметр из запроса.
	 */
	public static function sanitize_id( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$id = preg_replace( '/[^a-f0-9]/', '', strtolower( $raw ) );
		return strlen( $id ) === 32 ? $id : '';
	}

	public static function set( string $id, array $data ): void {
		if ( strlen( $id ) !== 32 ) {
			return;
		}
		$data['ts'] = time();
		set_transient( self::TRANSIENT_PREFIX . $id, $data, self::TTL );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		if ( strlen( $id ) !== 32 ) {
			return null;
		}
		$v = get_transient( self::TRANSIENT_PREFIX . $id );
		return is_array( $v ) ? $v : null;
	}
}
