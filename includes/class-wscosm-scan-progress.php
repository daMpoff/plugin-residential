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

	/** TTL для длинных задач (генерация буферных придомовых и т.п.), сек. */
	public const TTL_LONG = 1200;

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

	public static function set( string $id, array $data, ?int $ttl_override = null ): void {
		if ( strlen( $id ) !== 32 ) {
			return;
		}
		$data['ts'] = time();
		$ttl        = $ttl_override !== null ? max( 60, $ttl_override ) : self::TTL;
		set_transient( self::TRANSIENT_PREFIX . $id, $data, $ttl );
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
