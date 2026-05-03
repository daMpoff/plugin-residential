<?php
/**
 * Сохранение объектов OSM (GeoJSON features) в БД для последующей привязки к расчётам эргономики.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Feature_Store {

	/**
	 * Статусы для конвейера эргономики (расширение позже).
	 */
	public const ERGO_PENDING = 'pending';

	// #region agent log
	/**
	 * @param array<string,mixed> $data
	 */
	public static function agent_ndjson_log( string $hypothesis_id, string $location, string $message, array $data = [] ): void {
		if ( ! (bool) apply_filters( 'wscosm_agent_debug_log', false ) ) {
			return;
		}
		$base = defined( 'WSCOSM_DIR' ) ? dirname( WSCOSM_DIR ) : '';
		if ( $base === '' ) {
			return;
		}
		$path = $base . '/debug-97fecd.log';
		$line = wp_json_encode(
			[
				'sessionId'    => '97fecd',
				'timestamp'    => (int) round( microtime( true ) * 1000 ),
				'hypothesisId' => $hypothesis_id,
				'location'     => $location,
				'message'      => $message,
				'data'         => $data,
			],
			JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ( ! is_string( $line ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- debug session
		@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
	}
	// #endregion

	/**
	 * @param array{s:float,w:float,n:float,e:float} $bbox Окно запроса (fallback, если у геометрии нет огибающей).
	 * @param array{type:string,features?:array<int,mixed>} $fc
	 * @param string|null $progress_id ID для WSCOSM_Scan_Progress (32 hex).
	 */
	public static function upsert_collection( int $city_id, array $bbox, array $fc, ?string $progress_id = null ): void {
		// #region agent log
		if ( $city_id <= 0 ) {
			self::agent_ndjson_log( 'H1', 'feature-store.php:upsert_collection', 'early_exit_city_id', [ 'city_id' => $city_id ] );
			return;
		}
		$persist = (bool) apply_filters( 'wscosm_persist_osm_objects', true );
		if ( ! $persist ) {
			self::agent_ndjson_log( 'H1', 'feature-store.php:upsert_collection', 'early_exit_filter_false', [ 'city_id' => $city_id ] );
			return;
		}
		// #endregion

		$features = isset( $fc['features'] ) && is_array( $fc['features'] ) ? $fc['features'] : [];
		if ( empty( $features ) ) {
			// #region agent log
			self::agent_ndjson_log( 'H1', 'feature-store.php:upsert_collection', 'early_exit_empty_features', [ 'city_id' => $city_id ] );
			// #endregion
			$pid_empty = class_exists( 'WSCOSM_Scan_Progress' ) ? WSCOSM_Scan_Progress::sanitize_id( (string) ( $progress_id ?? '' ) ) : '';
			if ( $pid_empty !== '' ) {
				WSCOSM_Scan_Progress::set(
					$pid_empty,
					[
						'phase'   => 'done',
						'total'   => 0,
						'saved'   => 0,
						'message' => '',
					]
				);
			}
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wscosm_osm_object';
		$now   = current_time( 'mysql', true );

		$fb_s = isset( $bbox['s'] ) ? round( (float) $bbox['s'], 7 ) : null;
		$fb_w = isset( $bbox['w'] ) ? round( (float) $bbox['w'], 7 ) : null;
		$fb_n = isset( $bbox['n'] ) ? round( (float) $bbox['n'], 7 ) : null;
		$fb_e = isset( $bbox['e'] ) ? round( (float) $bbox['e'], 7 ) : null;

		$wpdb->suppress_errors( true );

		$total_feats  = count( $features );
		$saved_ok     = 0;
		$loop_n       = 0;
		$tick_every   = max( 1, (int) apply_filters( 'wscosm_scan_progress_tick_every', 25 ) );
		$prog_id      = ( $progress_id !== null && class_exists( 'WSCOSM_Scan_Progress' ) )
			? WSCOSM_Scan_Progress::sanitize_id( $progress_id )
			: '';

		// #region agent log
		$dbg_skip_bad_type = 0;
		$dbg_skip_no_geom  = 0;
		$dbg_skip_no_kind  = 0;
		$dbg_skip_json     = 0;
		$dbg_skip_no_env   = 0;
		$dbg_sql_attempts  = 0;
		$dbg_first_sql_err = '';
		self::agent_ndjson_log(
			'H1',
			'feature-store.php:upsert_collection',
			'upsert_start',
			[
				'city_id'       => $city_id,
				'total_feats'   => $total_feats,
				'table'         => $table,
				'prog_id_set'   => $prog_id !== '',
				'fallback_bbox' => [ 's' => $fb_s, 'w' => $fb_w, 'n' => $fb_n, 'e' => $fb_e ],
			]
		);
		// #endregion

		foreach ( $features as $feat ) {
			++$loop_n;
			if ( is_array( $feat ) && ( $feat['type'] ?? '' ) === 'Feature' ) {
				$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
				if ( $geom !== null ) {
					$props = isset( $feat['properties'] ) && is_array( $feat['properties'] ) ? $feat['properties'] : [];
					$kind  = self::sanitize_kind( isset( $props['wscosm_kind'] ) ? (string) $props['wscosm_kind'] : '' );
					if ( $kind === '' ) {
						++$dbg_skip_no_kind;
					}
					if ( $kind !== '' ) {
						$gtype    = isset( $geom['type'] ) ? sanitize_text_field( (string) $geom['type'] ) : '';
						$osm_type = isset( $props['wscosm_osm_el_type'] ) ? sanitize_text_field( (string) $props['wscosm_osm_el_type'] ) : '';
						$osm_id   = isset( $props['wscosm_osm_id'] ) ? absint( $props['wscosm_osm_id'] ) : 0;
						$key      = self::object_key( $osm_type, $osm_id, $kind, $geom );
						$geom_json  = wp_json_encode( $geom, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
						$props_json = wp_json_encode( $props, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
						if ( is_string( $geom_json ) && is_string( $props_json ) ) {
							$env = self::geometry_envelope( $geom );
							if ( $env === null ) {
								$env = self::make_envelope_from_fallback( $fb_s, $fb_w, $fb_n, $fb_e );
							}
							if ( $env !== null ) {
								$sql = $wpdb->prepare(
									"INSERT INTO {$table} (city_id, object_key, osm_type, osm_id, wscosm_kind, geom_type, geometry_json, properties_json, bbox_s, bbox_w, bbox_n, bbox_e, fetched_gmt, ergo_status)
									VALUES (%d, %s, %s, %d, %s, %s, %s, %s, %f, %f, %f, %f, %s, %s)
									ON DUPLICATE KEY UPDATE
										osm_type = VALUES(osm_type),
										osm_id = VALUES(osm_id),
										wscosm_kind = VALUES(wscosm_kind),
										geom_type = IF(
											CHAR_LENGTH( VALUES( geometry_json ) ) >= CHAR_LENGTH( COALESCE( geometry_json, '' ) ),
											VALUES( geom_type ),
											geom_type
										),
										geometry_json = IF(
											CHAR_LENGTH( VALUES( geometry_json ) ) >= CHAR_LENGTH( COALESCE( geometry_json, '' ) ),
											VALUES( geometry_json ),
											geometry_json
										),
										properties_json = IF(
											CHAR_LENGTH( VALUES( properties_json ) ) >= CHAR_LENGTH( COALESCE( properties_json, '' ) ),
											VALUES( properties_json ),
											properties_json
										),
										bbox_s = LEAST( COALESCE( bbox_s, VALUES( bbox_s ) ), VALUES( bbox_s ) ),
										bbox_w = LEAST( COALESCE( bbox_w, VALUES( bbox_w ) ), VALUES( bbox_w ) ),
										bbox_n = GREATEST( COALESCE( bbox_n, VALUES( bbox_n ) ), VALUES( bbox_n ) ),
										bbox_e = GREATEST( COALESCE( bbox_e, VALUES( bbox_e ) ), VALUES( bbox_e ) ),
										fetched_gmt = VALUES(fetched_gmt),
										ergo_status = IF(ergo_status = 'included', 'included', 'pending')",
									$city_id,
									$key,
									substr( $osm_type, 0, 16 ),
									$osm_id,
									substr( $kind, 0, 64 ),
									substr( $gtype, 0, 32 ),
									$geom_json,
									$props_json,
									$env['s'],
									$env['w'],
									$env['n'],
									$env['e'],
									$now,
									self::ERGO_PENDING
								);
								// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built with prepare above.
								++$dbg_sql_attempts;
								$wpdb->query( $sql );
								if ( $wpdb->last_error !== '' ) {
									if ( $dbg_first_sql_err === '' ) {
										$dbg_first_sql_err = $wpdb->last_error;
									}
									WSCOSM_Log::error(
										'feature_store',
										'Failed to upsert OSM object: ' . $wpdb->last_error,
										[
											'city_id'    => $city_id,
											'object_key' => $key,
										],
										$city_id
									);
								} else {
									++$saved_ok;
								}
							} else {
								++$dbg_skip_no_env;
							}
						} else {
							++$dbg_skip_json;
							WSCOSM_Log::warning(
								'feature_store',
								'JSON encode failed for OSM feature',
								[ 'city_id' => $city_id, 'object_key' => $key ],
								$city_id
							);
						}
					}
				} else {
					++$dbg_skip_no_geom;
				}
			} else {
				++$dbg_skip_bad_type;
			}
			if ( $prog_id !== '' && ( $loop_n % $tick_every === 0 || $loop_n === $total_feats ) ) {
				WSCOSM_Scan_Progress::set(
					$prog_id,
					[
						'phase'   => 'saving',
						'total'   => $total_feats,
						'saved'   => $saved_ok,
						'current' => $loop_n,
						'message' => '',
					]
				);
			}
		}

		$wpdb->suppress_errors( false );

		// #region agent log
		self::agent_ndjson_log(
			'H2',
			'feature-store.php:upsert_collection',
			'upsert_end',
			[
				'city_id'           => $city_id,
				'saved_ok'          => $saved_ok,
				'total_feats'       => $total_feats,
				'sql_attempts'      => $dbg_sql_attempts,
				'first_sql_error'   => $dbg_first_sql_err,
				'skip_bad_type'     => $dbg_skip_bad_type,
				'skip_no_geom'      => $dbg_skip_no_geom,
				'skip_no_kind'      => $dbg_skip_no_kind,
				'skip_json'         => $dbg_skip_json,
				'skip_no_env'       => $dbg_skip_no_env,
				'bbox_union_update' => true,
			]
		);
		// #endregion

		if ( $prog_id !== '' ) {
			WSCOSM_Scan_Progress::set(
				$prog_id,
				[
					'phase'   => 'done',
					'total'   => $total_feats,
					'saved'   => $saved_ok,
					'current' => $total_feats,
					'message' => '',
				]
			);
		}

		/**
		 * После сохранения объектов OSM города (для будущей интеграции с WSErgo_*).
		 *
		 * @param int   $city_id ID города (wsp_city).
		 * @param array $bbox    Нормализованный bbox.
		 * @param array $fc      FeatureCollection.
		 */
		do_action( 'wscosm_after_persist_objects', $city_id, $bbox, $fc );
	}

	/**
	 * Ключ категории для БД (латиница, цифры, _, -; без обрезки префиксов вроде bldg_).
	 */
	public static function sanitize_kind( string $kind ): string {
		$kind = strtolower( trim( $kind ) );
		$kind = preg_replace( '/[^a-z0-9_\-]/', '', $kind );
		return is_string( $kind ) ? substr( $kind, 0, 64 ) : '';
	}

	/**
	 * Огибающая GeoJSON-геометрии в градусах (s,w,n,e) — для индексации по видимой области карты.
	 *
	 * @param array<string,mixed> $geom
	 * @return array{s:float,w:float,n:float,e:float}|null
	 */
	public static function geometry_envelope( array $geom ): ?array {
		if ( ! isset( $geom['coordinates'] ) || ! is_array( $geom['coordinates'] ) ) {
			return null;
		}
		$min_lat = 90.0;
		$max_lat = -90.0;
		$min_lon = 180.0;
		$max_lon = -180.0;
		self::walk_coords_for_bounds( $geom['coordinates'], $min_lat, $max_lat, $min_lon, $max_lon );
		if ( $max_lat < $min_lat || $max_lon < $min_lon ) {
			return null;
		}
		return [
			's' => round( $min_lat, 7 ),
			'w' => round( $min_lon, 7 ),
			'n' => round( $max_lat, 7 ),
			'e' => round( $max_lon, 7 ),
		];
	}

	/**
	 * @param mixed $coords Узел coordinates из GeoJSON.
	 */
	private static function walk_coords_for_bounds( $coords, float &$min_lat, float &$max_lat, float &$min_lon, float &$max_lon ): void {
		if ( ! is_array( $coords ) ) {
			return;
		}
		if ( isset( $coords[0] ) && is_numeric( $coords[0] ) && isset( $coords[1] ) && is_numeric( $coords[1] ) && ! is_array( $coords[0] ) ) {
			$lon = (float) $coords[0];
			$la  = (float) $coords[1];
			$min_lat = min( $min_lat, $la );
			$max_lat = max( $max_lat, $la );
			$min_lon = min( $min_lon, $lon );
			$max_lon = max( $max_lon, $lon );
			return;
		}
		foreach ( $coords as $c ) {
			self::walk_coords_for_bounds( $c, $min_lat, $max_lat, $min_lon, $max_lon );
		}
	}

	/**
	 * @return array{s:float,w:float,n:float,e:float}|null
	 */
	private static function make_envelope_from_fallback( ?float $s, ?float $w, ?float $n, ?float $e ): ?array {
		if ( $s === null || $w === null || $n === null || $e === null || $s >= $n || $w >= $e ) {
			return null;
		}
		return [
			's' => $s,
			'w' => $w,
			'n' => $n,
			'e' => $e,
		];
	}

	/**
	 * Объекты OSM из БД, пересекающие bbox (по огибающей геометрии объекта).
	 *
	 * @param array{s:float,w:float,n:float,e:float} $bbox
	 * @return array{type:string,features:array<int,mixed>}
	 */
	public static function get_feature_collection_for_bbox( int $city_id, array $bbox, int $limit = 50000 ): array {
		global $wpdb;
		if ( $city_id <= 0 ) {
			return [
				'type'     => 'FeatureCollection',
				'features' => [],
			];
		}
		$s = isset( $bbox['s'] ) ? (float) $bbox['s'] : 0.0;
		$w = isset( $bbox['w'] ) ? (float) $bbox['w'] : 0.0;
		$n = isset( $bbox['n'] ) ? (float) $bbox['n'] : 0.0;
		$e = isset( $bbox['e'] ) ? (float) $bbox['e'] : 0.0;
		if ( $s >= $n || $w >= $e ) {
			return [
				'type'     => 'FeatureCollection',
				'features' => [],
			];
		}
		$limit = max( 1, min( 50000, $limit ) );
		$table = $wpdb->prefix . 'wscosm_osm_object';
		$sql   = $wpdb->prepare(
			"SELECT geometry_json, properties_json FROM {$table}
			WHERE city_id = %d
			AND bbox_s IS NOT NULL AND bbox_w IS NOT NULL AND bbox_n IS NOT NULL AND bbox_e IS NOT NULL
			AND bbox_e >= %f
			AND bbox_w <= %f
			AND bbox_n >= %f
			AND bbox_s <= %f
			ORDER BY id ASC
			LIMIT %d",
			$city_id,
			$w,
			$e,
			$s,
			$n,
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = [];
		}
		$features = [];
		foreach ( $rows as $row ) {
			$geom  = json_decode( (string) ( $row['geometry_json'] ?? '' ), true );
			$props = json_decode( (string) ( $row['properties_json'] ?? '' ), true );
			if ( ! is_array( $geom ) || ! is_array( $props ) ) {
				continue;
			}
			$features[] = [
				'type'       => 'Feature',
				'geometry'   => $geom,
				'properties' => $props,
			];
		}
		return [
			'type'     => 'FeatureCollection',
			'features' => $features,
		];
	}

	/**
	 * All stored OSM objects for a city. Territory jobs use this instead of viewport bbox.
	 *
	 * @return array{type:string,features:array<int,mixed>}
	 */
	public static function get_feature_collection_for_city( int $city_id, int $limit = 100000 ): array {
		$rows = self::get_objects_for_city( $city_id, $limit, null );
		$features = [];
		foreach ( $rows as $row ) {
			$geom  = json_decode( (string) ( $row['geometry_json'] ?? '' ), true );
			$props = json_decode( (string) ( $row['properties_json'] ?? '' ), true );
			if ( ! is_array( $geom ) || ! is_array( $props ) ) {
				continue;
			}
			$features[] = [
				'type'       => 'Feature',
				'geometry'   => $geom,
				'properties' => $props,
			];
		}
		return [
			'type'     => 'FeatureCollection',
			'features' => $features,
		];
	}

	/**
	 * @param array<string,mixed> $geom GeoJSON geometry.
	 */
	public static function object_key( string $osm_type, int $osm_id, string $kind, array $geom ): string {
		$osm_type = strtolower( preg_replace( '/[^a-z]/i', '', $osm_type ) );
		if ( $osm_id > 0 && $osm_type !== '' ) {
			return substr( $osm_type . ':' . $osm_id, 0, 96 );
		}
		$h = hash( 'sha256', wp_json_encode( [ $kind, $geom ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) );
		return 'h:' . substr( (string) $h, 0, 90 );
	}

	/**
	 * Количество сохранённых объектов по городу.
	 */
	public static function count_for_city( int $city_id ): int {
		global $wpdb;
		if ( $city_id <= 0 ) {
			return 0;
		}
		$table = $wpdb->prefix . 'wscosm_osm_object';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE city_id = %d", $city_id ) );
		return (int) $n;
	}

	public static function count_buildings_for_city( int $city_id ): int {
		global $wpdb;
		if ( $city_id <= 0 ) {
			return 0;
		}
		$table = $wpdb->prefix . 'wscosm_osm_object';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE city_id = %d AND wscosm_kind LIKE %s AND wscosm_kind <> %s", $city_id, 'bldg\_%', 'bldg_part' ) );
		return (int) $n;
	}

	/**
	 * Выборка сохранённых объектов для будущего расчёта эргономики.
	 *
	 * @param array<string>|null $kinds Фильтр по wscosm_kind; null — все.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_objects_for_city( int $city_id, int $limit = 5000, ?array $kinds = null ): array {
		global $wpdb;
		if ( $city_id <= 0 || $limit < 1 ) {
			return [];
		}
		$table = $wpdb->prefix . 'wscosm_osm_object';
		$limit = min( 50000, $limit );
		if ( ! empty( $kinds ) ) {
			$clean = array_values( array_unique( array_filter( array_map( [ self::class, 'sanitize_kind' ], $kinds ) ) ) );
			if ( empty( $clean ) ) {
				return [];
			}
			$ph  = implode( ',', array_fill( 0, count( $clean ), '%s' ) );
			$sql = "SELECT object_key, osm_type, osm_id, wscosm_kind, geom_type, geometry_json, properties_json, bbox_s, bbox_w, bbox_n, bbox_e, fetched_gmt, ergo_status FROM {$table} WHERE city_id = %d AND wscosm_kind IN ({$ph}) ORDER BY id ASC LIMIT %d";
			$args = array_merge( [ $city_id ], $clean, [ $limit ] );
			$sql  = $wpdb->prepare( $sql, ...$args );
		} else {
			$sql = $wpdb->prepare(
				"SELECT object_key, osm_type, osm_id, wscosm_kind, geom_type, geometry_json, properties_json, bbox_s, bbox_w, bbox_n, bbox_e, fetched_gmt, ergo_status FROM {$table} WHERE city_id = %d ORDER BY id ASC LIMIT %d",
				$city_id,
				$limit
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
}
