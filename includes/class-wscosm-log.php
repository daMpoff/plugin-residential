<?php
/**
 * Персистентное логирование ошибок и предупреждений (таблица + опционально PHP error_log).
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Log {

	public const L_ERROR   = 'error';
	public const L_WARNING = 'warning';
	public const L_INFO    = 'info';

	/**
	 * @param array<string,mixed> $context
	 */
	public static function log( string $level, string $scope, string $message, array $context = [], $city_id = null ): void {
		global $wpdb;

		if ( ! in_array( $level, [ self::L_ERROR, self::L_WARNING, self::L_INFO ], true ) ) {
			$level = self::L_INFO;
		}

		$scope   = sanitize_key( $scope );
		$scope   = substr( $scope, 0, 48 );
		$message = wp_kses_post( $message );
		if ( strlen( $message ) > 20000 ) {
			$message = substr( $message, 0, 20000 ) . '…';
		}

		$ctx_json = '';
		if ( ! empty( $context ) ) {
			$ctx_json = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( ! is_string( $ctx_json ) ) {
				$ctx_json = '';
			}
			if ( strlen( $ctx_json ) > 65535 ) {
				$ctx_json = substr( $ctx_json, 0, 65000 ) . '…';
			}
		}

		$table = $wpdb->prefix . 'wscosm_log';
		$row   = [
			'created_gmt' => current_time( 'mysql', true ),
			'level'       => $level,
			'scope'       => $scope,
			'message'     => $message,
			'context'     => $ctx_json !== '' ? $ctx_json : '',
		];
		$fmt   = [ '%s', '%s', '%s', '%s', '%s' ];
		if ( $city_id !== null && $city_id > 0 ) {
			$row['city_id'] = $city_id;
			$fmt[]          = '%d';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$wpdb->insert( $table, $row, $fmt );

		if ( $wpdb->last_error !== '' ) {
			if ( (bool) apply_filters( 'wscosm_log_fallback_error_log', true ) ) {
				error_log( 'WSCOSM_Log DB insert failed: ' . $wpdb->last_error . ' | ' . $message );
			}
		}

		if ( (bool) apply_filters( 'wscosm_mirror_logs_to_php_error_log', true ) && in_array( $level, [ self::L_ERROR, self::L_WARNING ], true ) ) {
			$line = sprintf( '[WSCOSM][%s][%s] %s', strtoupper( $level ), $scope, wp_strip_all_tags( $message ) );
			if ( $ctx_json !== '' && strlen( $ctx_json ) < 4000 ) {
				$line .= ' | ' . $ctx_json;
			}
			error_log( $line );
		}
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function error( string $scope, string $message, array $context = [], ?int $city_id = null ): void {
		self::log( self::L_ERROR, $scope, $message, $context, $city_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function warning( string $scope, string $message, array $context = [], ?int $city_id = null ): void {
		self::log( self::L_WARNING, $scope, $message, $context, $city_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function info( string $scope, string $message, array $context = [], ?int $city_id = null ): void {
		if ( ! (bool) apply_filters( 'wscosm_log_info_enabled', false ) ) {
			return;
		}
		self::log( self::L_INFO, $scope, $message, $context, $city_id );
	}

	public static function log_wp_error( string $scope, WP_Error $err, $city_id = null ): void {
		$data = $err->get_error_data();
		$ctx  = [
			'code'        => $err->get_error_code(),
			'message'     => $err->get_error_message(),
			'data'        => is_array( $data ) || is_scalar( $data ) ? $data : null,
			'all_codes'   => array_keys( $err->errors ),
			'all_messages'=> $err->errors,
		];
		self::error( $scope, $err->get_error_message(), $ctx, $city_id );
	}
}
