<?php
/**
 * REST API для GeoJSON объектов OSM вокруг города.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_REST {

	public const NS = 'wscosm/v1';
	public const META_VORONOI_OBJECT_KEY = 'wscosm_voronoi_object_key';
	public const META_VORONOI_SOURCE     = 'wscosm_voronoi_source';

	public static function can_live_overpass( int $city_id ): bool {
		$allowed = current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $city_id );
		return (bool) apply_filters( 'wscosm_can_live_overpass', $allowed, $city_id );
	}

	public static function can_edit_city_request( WP_REST_Request $request ): bool {
		$city_id = (int) $request->get_param( 'id' );
		return $city_id > 0 && self::can_live_overpass( $city_id );
	}

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/city/(?P<id>\d+)/features',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_city_features' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'source' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => 'auto',
						'enum'              => [ 'auto', 'local', 'live' ],
						'sanitize_callback' => 'sanitize_key',
					],
					'refresh' => [
						'type'     => [ 'string', 'boolean', 'integer' ],
						'required' => false,
					],
					'progress_id' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'south' => [
						'type'    => 'number',
						'required'=> false,
					],
					'west' => [
						'type'     => 'number',
						'required' => false,
					],
					'north' => [
						'type'     => 'number',
						'required' => false,
					],
					'east' => [
						'type'     => 'number',
						'required' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/scan-progress',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_scan_progress' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'progress_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/city/(?P<id>\d+)/yard-ergo-at',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_yard_ergo_at' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'lat' => [
						'type'     => 'number',
						'required' => true,
					],
					'lng' => [
						'type'     => 'number',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/city/(?P<id>\d+)/recalculate-yards-ergo',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'post_recalculate_yards_ergo' ],
				'permission_callback' => [ self::class, 'can_edit_city_request' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/city/(?P<id>\d+)/building-buffer-zone',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'post_building_buffer_zone' ],
				'permission_callback' => [ self::class, 'can_edit_city_request' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/city/(?P<id>\d+)/generate-buffer-yards',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'post_generate_buffer_yards' ],
				'permission_callback' => [ self::class, 'can_edit_city_request' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_city_features( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}

		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}

		$lat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		if ( ! $lat || ! $lng ) {
			return new WP_REST_Response(
				[
					'type'     => 'FeatureCollection',
					'features' => [],
				],
				200,
				[
					'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
					'Pragma'        => 'no-cache',
				]
			);
		}

		$refresh_raw = $request->get_param( 'refresh' );
		$refresh     = ( true === $refresh_raw || 1 === $refresh_raw || '1' === (string) $refresh_raw
			|| 'true' === strtolower( (string) $refresh_raw ) );
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( ! in_array( $source, [ 'auto', 'local', 'live' ], true ) ) {
			$source = 'auto';
		}
		if ( ( $refresh || 'live' === $source ) && ! self::can_live_overpass( $city_id ) ) {
			return new WP_Error(
				'wscosm_live_forbidden',
				'Live Overpass scan is not allowed for this user.',
				[ 'status' => 403 ]
			);
		}

		$progress_id = class_exists( 'WSCOSM_Scan_Progress' )
			? WSCOSM_Scan_Progress::sanitize_id( $request->get_param( 'progress_id' ) )
			: '';

		$has_s = $request->get_param( 'south' ) !== null && $request->get_param( 'south' ) !== '';
		$has_w = $request->get_param( 'west' ) !== null && $request->get_param( 'west' ) !== '';
		$has_n = $request->get_param( 'north' ) !== null && $request->get_param( 'north' ) !== '';
		$has_e = $request->get_param( 'east' ) !== null && $request->get_param( 'east' ) !== '';

		if ( $has_s && $has_w && $has_n && $has_e ) {
			$bbox = WSCOSM_Overpass::normalize_client_bbox(
				$lat,
				$lng,
				[
					's' => (float) $request->get_param( 'south' ),
					'w' => (float) $request->get_param( 'west' ),
					'n' => (float) $request->get_param( 'north' ),
					'e' => (float) $request->get_param( 'east' ),
				]
			);
			if ( is_wp_error( $bbox ) ) {
				if ( class_exists( 'WSCOSM_Log' ) ) {
					WSCOSM_Log::log_wp_error( 'rest_bbox', $bbox, $city_id );
				}
				return $bbox;
			}
		} else {
			$bbox = WSCOSM_Overpass::bbox_from_center( $lat, $lng, WSCOSM_Overpass::default_radius_km() );
		}

		if ( $refresh && $progress_id !== '' ) {
			WSCOSM_Scan_Progress::set(
				$progress_id,
				[
					'phase'   => 'overpass',
					'total'   => 0,
					'saved'   => 0,
					'current' => 0,
					'message' => '',
				]
			);
		}

		$fc            = null;
		$from_overpass = false;

		if ( $refresh || 'live' === $source ) {
			$fc = WSCOSM_Overpass::get_features_for_bbox( $bbox );
			$from_overpass = true;
		} elseif ( 'local' === $source ) {
			$fc = class_exists( 'WSCOSM_Feature_Store' )
				? WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox )
				: [ 'type' => 'FeatureCollection', 'features' => [] ];
		} else {
			$fc = class_exists( 'WSCOSM_Feature_Store' )
				? WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox )
				: [ 'type' => 'FeatureCollection', 'features' => [] ];
			$db_feats = isset( $fc['features'] ) && is_array( $fc['features'] ) ? $fc['features'] : [];
			if ( count( $db_feats ) === 0 && self::can_live_overpass( $city_id ) ) {
				$fc            = WSCOSM_Overpass::get_features_for_bbox( $bbox );
				$from_overpass = true;
			}
		}

		if ( is_wp_error( $fc ) ) {
			if ( $refresh && $progress_id !== '' ) {
				WSCOSM_Scan_Progress::set(
					$progress_id,
					[
						'phase'   => 'error',
						'total'   => 0,
						'saved'   => 0,
						'current' => 0,
						'message' => $fc->get_error_message(),
					]
				);
			}
			if ( class_exists( 'WSCOSM_Log' ) ) {
				WSCOSM_Log::error(
					'rest_features',
					$fc->get_error_message(),
					[
						'code'         => $fc->get_error_code(),
						'data'         => $fc->get_error_data(),
						'bbox'         => $bbox,
						'all_codes'    => array_keys( $fc->errors ),
						'all_messages' => $fc->errors,
					],
					$city_id
				);
			}
			$fc = [
				'type'     => 'FeatureCollection',
				'features' => [],
			];
		} elseif ( $from_overpass && is_array( $fc ) && class_exists( 'WSCOSM_Feature_Store' ) ) {
			$n_feat = count( $fc['features'] ?? [] );
			// #region agent log
			WSCOSM_Feature_Store::agent_ndjson_log(
				'H4',
				'rest.php:get_city_features',
				'before_upsert',
				[
					'city_id'        => $city_id,
					'from_overpass'  => $from_overpass,
					'refresh'        => $refresh,
					'source'         => $source,
					'n_feat'         => $n_feat,
					'bbox'           => $bbox,
					'progress_id_ok' => $progress_id !== '',
				]
			);
			// #endregion
			if ( $refresh && $progress_id !== '' ) {
				WSCOSM_Scan_Progress::set(
					$progress_id,
					[
						'phase'   => 'saving',
						'total'   => $n_feat,
						'saved'   => 0,
						'current' => 0,
						'message' => '',
					]
				);
			}
			try {
				$pid_for_upsert = ( $refresh && $progress_id !== '' ) ? $progress_id : null;
				WSCOSM_Feature_Store::upsert_collection( $city_id, $bbox, $fc, $pid_for_upsert );
			} catch ( \Throwable $e ) {
				if ( $refresh && $progress_id !== '' ) {
					WSCOSM_Scan_Progress::set(
						$progress_id,
						[
							'phase'   => 'error',
							'total'   => $n_feat,
							'saved'   => 0,
							'current' => 0,
							'message' => $e->getMessage(),
						]
					);
				}
				if ( class_exists( 'WSCOSM_Log' ) ) {
					WSCOSM_Log::error(
						'feature_store',
						$e->getMessage(),
						[
							'file' => $e->getFile(),
							'line' => $e->getLine(),
						],
						$city_id
					);
				}
			}

			if ( (bool) apply_filters( 'wscosm_return_saved_features_after_scan', false, $city_id, $bbox ) ) {
				$db_fc = WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox );
				$db_n  = is_array( $db_fc['features'] ?? null ) ? count( $db_fc['features'] ) : 0;
			// Подмена на БД только если в этом bbox есть строки — иначе сохраняем ответ Overpass
			// (избегаем пустой карты при рассогласовании bbox в SELECT и свежего GeoJSON).
				if ( $db_n > 0 ) {
					$fc = $db_fc;
				}
			}
		}

		// #region agent log
		if ( ! ( $from_overpass && is_array( $fc ) && class_exists( 'WSCOSM_Feature_Store' ) ) ) {
			WSCOSM_Feature_Store::agent_ndjson_log(
				'H4',
				'rest.php:get_city_features',
				'upsert_branch_skipped',
				[
					'city_id'        => $city_id,
					'from_overpass'  => $from_overpass,
					'is_array_fc'    => is_array( $fc ),
					'response_feats' => is_array( $fc ) ? count( $fc['features'] ?? [] ) : -1,
				]
			);
		}
		// #endregion

		// GeoJSON по городу меняется при сканировании — не кэшировать в браузере/CDN.
		$headers = [
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'        => 'no-cache',
		];

		return new WP_REST_Response( $fc, 200, $headers );
	}

	/**
	 * Опрос прогресса сканирования (transient).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_scan_progress( WP_REST_Request $request ) {
		$id = class_exists( 'WSCOSM_Scan_Progress' )
			? WSCOSM_Scan_Progress::sanitize_id( $request->get_param( 'progress_id' ) )
			: '';
		if ( $id === '' ) {
			return new WP_Error( 'wscosm_bad_progress', 'Invalid progress id.', [ 'status' => 400 ] );
		}
		$p = WSCOSM_Scan_Progress::get( $id );
		if ( $p === null ) {
			return new WP_REST_Response(
				[
					'phase'   => 'unknown',
					'total'   => 0,
					'saved'   => 0,
					'current' => 0,
					'message' => '',
				],
				200
			);
		}
		return new WP_REST_Response( $p, 200 );
	}

	/**
	 * HTML эргономики для точки (придомовый полигон).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_yard_ergo_at( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}

		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}

		$lat = (float) $request->get_param( 'lat' );
		$lng = (float) $request->get_param( 'lng' );

		if ( ! class_exists( 'WSCOSM_Yard_At' ) ) {
			return new WP_REST_Response(
				[
					'found'   => false,
					'yard_id' => null,
					'html'    => '',
				],
				200
			);
		}

		$yard_id = WSCOSM_Yard_At::find_yard_id_at( $city_id, $lat, $lng );
		if ( ! $yard_id ) {
			return new WP_REST_Response(
				[
					'found'   => false,
					'yard_id' => null,
					'html'    => '<p class="wscosm-yard-ergo-miss description">' . esc_html__(
						'Импортированный придомовый участок с расчётом эргономики в этой точке не найден. Импортируйте участки для города или выберите здание внутри полигона придомового.',
						'worldstat-courtyard-osm'
					) . '</p>',
				],
				200,
				[ 'Cache-Control' => 'private, max-age=60' ]
			);
		}

		$html = WSCOSM_Yard_At::yard_popup_fragment( $yard_id );
		return new WP_REST_Response(
			[
				'found'   => true,
				'yard_id' => $yard_id,
				'html'    => $html,
			],
			200,
			[ 'Cache-Control' => 'private, max-age=120' ]
		);
	}

	/**
	 * Build a simple courtyard zone for one building as "buffer from contour".
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function post_building_buffer_zone( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}
		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];

		$req_object_key = sanitize_text_field( (string) ( $params['object_key'] ?? '' ) );
		$req_osm_type   = sanitize_key( (string) ( $params['osm_type'] ?? '' ) );
		$req_osm_id     = absint( $params['osm_id'] ?? 0 );

		if ( $req_object_key === '' && ( $req_osm_type === '' || $req_osm_id <= 0 ) ) {
			return new WP_Error( 'wscosm_bad_building_ref', 'object_key or osm_type/osm_id is required.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wscosm_osm_object';

		if ( $req_object_key !== '' ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT object_key, osm_type, osm_id, wscosm_kind, geometry_json, properties_json
					FROM {$table}
					WHERE city_id = %d AND object_key = %s
					ORDER BY id DESC
					LIMIT 1",
					$city_id,
					$req_object_key
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT object_key, osm_type, osm_id, wscosm_kind, geometry_json, properties_json
					FROM {$table}
					WHERE city_id = %d AND osm_type = %s AND osm_id = %d
					ORDER BY id DESC
					LIMIT 1",
					$city_id,
					$req_osm_type,
					$req_osm_id
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'wscosm_building_not_found', 'Building not found in local OSM storage.', [ 'status' => 404 ] );
		}

		$kind = sanitize_key( (string) ( $row['wscosm_kind'] ?? '' ) );
		if ( strpos( $kind, 'bldg_' ) !== 0 || $kind === 'bldg_part' ) {
			return new WP_Error( 'wscosm_not_a_building', 'Selected object is not a supported building.', [ 'status' => 400 ] );
		}

		$building_geom = json_decode( (string) ( $row['geometry_json'] ?? '' ), true );
		$building_props = json_decode( (string) ( $row['properties_json'] ?? '' ), true );
		if ( ! is_array( $building_geom ) || ! is_array( $building_props ) ) {
			return new WP_Error( 'wscosm_bad_building_geometry', 'Building geometry is invalid.', [ 'status' => 500 ] );
		}
		$gtype = (string) ( $building_geom['type'] ?? '' );
		if ( $gtype !== 'Polygon' && $gtype !== 'MultiPolygon' ) {
			return new WP_Error( 'wscosm_bad_building_geometry_type', 'Building geometry must be Polygon or MultiPolygon.', [ 'status' => 400 ] );
		}

		$radius_m = self::courtyard_buffer_radius_m();
		$building_key = sanitize_text_field( (string) ( $row['object_key'] ?? '' ) );

		$fc_one = [
			'type'     => 'FeatureCollection',
			'features' => [
				[
					'type'       => 'Feature',
					'geometry'   => $building_geom,
					'properties' => [
						'object_key'         => $building_key,
						'wscosm_osm_el_type' => sanitize_key( (string) ( $row['osm_type'] ?? '' ) ),
						'wscosm_osm_id'      => absint( $row['osm_id'] ?? 0 ),
						'wscosm_kind'        => $kind,
						'name'               => sanitize_text_field( (string) ( $building_props['name'] ?? '' ) ),
					],
				],
			],
		];

		$py_feats = self::try_run_python_buffer_fc( $fc_one, $radius_m, null );
		if ( ! is_array( $py_feats ) || empty( $py_feats[0] ) || ! is_array( $py_feats[0] ) ) {
			return new WP_Error( 'wscosm_buffer_failed', 'Python buffer failed.', [ 'status' => 500 ] );
		}

		$zone_geom = isset( $py_feats[0]['geometry'] ) && is_array( $py_feats[0]['geometry'] ) ? $py_feats[0]['geometry'] : null;
		if ( ! self::is_valid_polygon_geometry( $zone_geom ) ) {
			return new WP_Error( 'wscosm_buffer_failed', 'Python buffer returned invalid geometry.', [ 'status' => 500 ] );
		}

		$zone_bbox = class_exists( 'WSCOSM_Feature_Store' ) ? WSCOSM_Feature_Store::geometry_envelope( $zone_geom ) : null;
		$candidate_fc = ( $zone_bbox && class_exists( 'WSCOSM_Feature_Store' ) )
			? WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $zone_bbox, 12000 )
			: [ 'type' => 'FeatureCollection', 'features' => [] ];

		$objects = [];
		foreach ( (array) ( $candidate_fc['features'] ?? [] ) as $feat ) {
			if ( ! is_array( $feat ) ) {
				continue;
			}
			$props = isset( $feat['properties'] ) && is_array( $feat['properties'] ) ? $feat['properties'] : [];
			$geom  = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( ! $geom ) {
				continue;
			}

			$f_kind = sanitize_key( (string) ( $props['wscosm_kind'] ?? '' ) );
			if ( strpos( $f_kind, 'bldg_' ) === 0 ) {
				continue;
			}

			$f_object_key = sanitize_text_field( (string) ( $props['object_key'] ?? '' ) );
			if ( $f_object_key === '' ) {
				$f_osm_type = sanitize_key( (string) ( $props['wscosm_osm_el_type'] ?? '' ) );
				$f_osm_id   = absint( $props['wscosm_osm_id'] ?? 0 );
				if ( $f_osm_type !== '' && $f_osm_id > 0 ) {
					$f_object_key = $f_osm_type . ':' . $f_osm_id;
				}
			}
			if ( $f_object_key !== '' && $f_object_key === $building_key ) {
				continue;
			}

			if ( ! self::geometry_intersects_zone_simple( $geom, $zone_geom ) ) {
				continue;
			}
			$objects[] = $feat;
			if ( count( $objects ) >= 1500 ) {
				break;
			}
		}

		$building_center = class_exists( 'WSCOSM_Geo' )
			? WSCOSM_Geo::geometry_representative_latlng( $building_geom )
			: null;

		return new WP_REST_Response(
			[
				'building' => [
					'object_key' => $building_key,
					'osm_type'   => sanitize_key( (string) ( $row['osm_type'] ?? '' ) ),
					'osm_id'     => absint( $row['osm_id'] ?? 0 ),
					'kind'       => $kind,
					'name'       => sanitize_text_field( (string) ( $building_props['name'] ?? '' ) ),
					'center'     => is_array( $building_center ) ? [ 'lat' => (float) $building_center[0], 'lng' => (float) $building_center[1] ] : null,
				],
				'radius_m' => $radius_m,
				'zone'     => [
					'type'       => 'Feature',
					'geometry'   => $zone_geom,
					'properties' => [
						'method'     => 'building_contour_buffer',
						'radius_m'   => $radius_m,
						'object_key' => $building_key,
					],
				],
				'objects'  => [
					'type'     => 'FeatureCollection',
					'features' => $objects,
				],
			],
			200
		);
	}

	private static function courtyard_buffer_radius_m(): float {
		$v = (float) get_option( 'wscosm_courtyard_buffer_radius_m', 35 );
		return max( 5.0, min( 200.0, $v ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function scan_progress_set_long( string $progress_id, array $data ): void {
		if ( strlen( $progress_id ) !== 32 || ! class_exists( 'WSCOSM_Scan_Progress' ) ) {
			return;
		}
		WSCOSM_Scan_Progress::set( $progress_id, $data, WSCOSM_Scan_Progress::TTL_LONG );
	}

	/**
	 * @see tools/buffer_building_yards.py — stderr lines "WSCOSM_PROGRESS current total phase".
	 */
	private static function buffer_yards_flush_progress_stderr( string &$stderr_acc, ?string $progress_id ): void {
		if ( ! $progress_id || strlen( $progress_id ) !== 32 ) {
			return;
		}
		while ( ( $p = strpos( $stderr_acc, "\n" ) ) !== false ) {
			$line       = substr( $stderr_acc, 0, $p );
			$stderr_acc = substr( $stderr_acc, $p + 1 );
			if ( strncmp( $line, 'WSCOSM_PROGRESS ', 16 ) !== 0 ) {
				continue;
			}
			$rest = trim( substr( $line, 16 ) );
			if ( $rest === '' ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $rest, 3 );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'buffer',
					'total'   => absint( $parts[1] ),
					'current' => absint( $parts[0] ),
					'saved'   => 0,
					'message' => '',
				]
			);
		}
	}

	/**
	 * Run tools/buffer_building_yards.py; GeoJSON stdout or null → PHP fallback.
	 *
	 * @param array<string,mixed> $source_fc
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function try_run_python_buffer_fc( array $source_fc, float $radius_m, ?string $progress_id ): ?array {
		if ( ! defined( 'WSCOSM_DIR' ) ) {
			return null;
		}

		$script = wp_normalize_path( WSCOSM_DIR . 'tools/buffer_building_yards.py' );
		if ( ! is_readable( $script ) ) {
			return null;
		}

		$r_m = max( 5.0, min( 200.0, $radius_m ) );
		$fc_in = [
			'type'     => 'FeatureCollection',
			'features' => isset( $source_fc['features'] ) && is_array( $source_fc['features'] ) ? $source_fc['features'] : [],
		];

		$stdin_json = wp_json_encode( $fc_in, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $stdin_json ) || $stdin_json === '' ) {
			return null;
		}

		$args_tail  = [ $script, '--radius-m', (string) $r_m ];
		$candidates = [];
		$py_env     = getenv( 'WSCOSM_PYTHON' );
		if ( is_string( $py_env ) && $py_env !== '' ) {
			$candidates[] = array_merge( [ $py_env, '-u' ], $args_tail );
		}
		if ( PHP_OS_FAMILY === 'Windows' ) {
			array_unshift( $candidates, array_merge( [ 'py', '-3', '-u' ], $args_tail ) );
		}
		$candidates[] = array_merge( [ 'python3', '-u' ], $args_tail );
		$candidates[] = array_merge( [ 'python', '-u' ], $args_tail );

		foreach ( $candidates as $argv ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.proc_open_proc_open
			$proc = proc_open(
				$argv,
				[
					0 => [ 'pipe', 'r' ],
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes,
				null,
				null,
				[ 'bypass_shell' => true ]
			);

			if ( ! is_resource( $proc ) ) {
				continue;
			}

			fwrite( $pipes[0], $stdin_json );
			fclose( $pipes[0] );
			unset( $pipes[0] );

			if ( ! isset( $pipes[1], $pipes[2] ) || ! is_resource( $pipes[1] ) || ! is_resource( $pipes[2] ) ) {
				proc_close( $proc );

				continue;
			}

			$p_stdout = $pipes[1];
			$p_stderr = $pipes[2];
			stream_set_blocking( $p_stdout, false );
			stream_set_blocking( $p_stderr, false );
			// Encourage immediate pipe delivery (Windows).
			if ( function_exists( 'stream_set_read_buffer' ) ) {
				stream_set_read_buffer( $p_stdout, 0 );
				stream_set_read_buffer( $p_stderr, 0 );
			}

			$stdout     = '';
			$stderr_acc = '';
			$deadline   = microtime( true ) + 590.0;

			// On Windows, stream_select() is unreliable for proc pipes.
			// Use polling reads to stream progress updates from stderr.
			do {
				if ( microtime( true ) > $deadline ) {
					proc_terminate( $proc, 15 );
					break;
				}

				$running = proc_get_status( $proc )['running'];

				$had_any = false;

				if ( is_resource( $p_stderr ) ) {
					$chunk = stream_get_contents( $p_stderr );
					if ( is_string( $chunk ) && '' !== $chunk ) {
						$stderr_acc .= $chunk;
						self::buffer_yards_flush_progress_stderr( $stderr_acc, $progress_id );
						$had_any = true;
					}
				}

				if ( is_resource( $p_stdout ) ) {
					$chunk = stream_get_contents( $p_stdout );
					if ( is_string( $chunk ) && '' !== $chunk ) {
						$stdout .= $chunk;
						$had_any = true;
					}
				}

				if ( ! $running && ! $had_any ) {
					break;
				}

				if ( ! $had_any ) {
					usleep( 12000 );
				}

			} while ( true );

			if ( is_resource( $p_stderr ) ) {
				stream_set_blocking( $p_stderr, true );
				while ( ! feof( $p_stderr ) ) {
					$d = fread( $p_stderr, 65536 );
					if ( false === $d || '' === $d ) {
						break;
					}
					$stderr_acc .= (string) $d;
				}
			}

			self::buffer_yards_flush_progress_stderr( $stderr_acc, $progress_id );

			if ( is_resource( $p_stdout ) ) {
				stream_set_blocking( $p_stdout, true );
				while ( ! feof( $p_stdout ) ) {
					$d = fread( $p_stdout, 65536 );
					if ( false === $d || '' === $d ) {
						break;
					}
					$stdout .= (string) $d;
				}
			}

			if ( is_resource( $p_stdout ) ) {
				fclose( $p_stdout );
			}

			if ( is_resource( $p_stderr ) ) {
				fclose( $p_stderr );
			}

			$exit_code = proc_close( $proc );

			if ( 0 !== (int) $exit_code ) {
				continue;
			}

			if ( '' === trim( $stdout ) ) {
				continue;
			}

			$decoded = json_decode( $stdout, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( (string) ( $decoded['type'] ?? '' ) !== 'FeatureCollection' ) {
				continue;
			}
			$feats = isset( $decoded['features'] ) && is_array( $decoded['features'] ) ? $decoded['features'] : [];
			if ( [] === $feats ) {
				continue;
			}

			return $feats;
		}

		return null;
	}

	// (PHP buffer geometry removed — Python is required.)

	/**
	 * Fast conservative intersection check without heavy GIS libs.
	 *
	 * @param array<string,mixed> $geometry
	 * @param array<string,mixed> $zone
	 */
	private static function geometry_intersects_zone_simple( array $geometry, array $zone ): bool {
		$rep = class_exists( 'WSCOSM_Geo' ) ? WSCOSM_Geo::geometry_representative_latlng( $geometry ) : null;
		if ( is_array( $rep ) && class_exists( 'WSCOSM_Geo' ) ) {
			if ( WSCOSM_Geo::point_in_geometry( (float) $rep[0], (float) $rep[1], $zone ) ) {
				return true;
			}
			$rep_dist = WSCOSM_Geo::min_distance_point_to_geometry_m( (float) $rep[0], (float) $rep[1], $zone );
			if ( $rep_dist <= 15.0 ) {
				return true;
			}
		}
		return self::geometry_has_vertex_in_zone( $geometry, $zone );
	}

	/**
	 * @param array<string,mixed> $geometry
	 * @param array<string,mixed> $zone
	 */
	private static function geometry_has_vertex_in_zone( array $geometry, array $zone ): bool {
		$coords = $geometry['coordinates'] ?? null;
		if ( ! is_array( $coords ) || ! class_exists( 'WSCOSM_Geo' ) ) {
			return false;
		}
		$stack = [ $coords ];
		while ( ! empty( $stack ) ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node[0], $node[1] ) && is_numeric( $node[0] ) && is_numeric( $node[1] ) && ! is_array( $node[0] ) ) {
				if ( WSCOSM_Geo::point_in_geometry( (float) $node[1], (float) $node[0], $zone ) ) {
					return true;
				}
				continue;
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$stack[] = $child;
				}
			}
		}
		return false;
	}

	/**
	 * Generate and save buffer courtyard polygons for all eligible buildings of the city.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function post_generate_buffer_yards( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		$city_err = self::assert_city_publishable_for_yards( $city_id );
		if ( $city_err instanceof WP_Error ) {
			return $city_err;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 600 );
		}

		$params           = $request->get_json_params();
		$params           = is_array( $params ) ? $params : [];
		$replace_existing = ! isset( $params['replace_existing'] ) || (bool) $params['replace_existing'];
		$radius_m         = self::courtyard_buffer_radius_m();
		$progress_id      = class_exists( 'WSCOSM_Scan_Progress' )
			? WSCOSM_Scan_Progress::sanitize_id( (string) ( $params['progress_id'] ?? '' ) )
			: '';

		if ( '' !== $progress_id ) {
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'prepare',
					'total'   => 0,
					'current' => 0,
					'saved'   => 0,
					'message' => '',
				]
			);
		}

		$source_fc = class_exists( 'WSCOSM_Feature_Store' )
			? WSCOSM_Feature_Store::get_building_polygon_feature_collection_for_city( $city_id, 120000 )
			: [ 'type' => 'FeatureCollection', 'features' => [] ];

		$raw_features = isset( $source_fc['features'] ) && is_array( $source_fc['features'] ) ? $source_fc['features'] : [];
		$n_in         = count( $raw_features );

		if ( '' !== $progress_id ) {
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'buffer',
					'total'   => $n_in,
					'current' => 0,
					'saved'   => 0,
					'message' => '',
				]
			);
		}

		$py_out = self::try_run_python_buffer_fc( $source_fc, $radius_m, $progress_id );
		if ( ! is_array( $py_out ) || [] === $py_out ) {
			if ( '' !== $progress_id ) {
				self::scan_progress_set_long(
					$progress_id,
					[
						'phase'   => 'error',
						'total'   => $n_in,
						'current' => 0,
						'saved'   => 0,
						'message' => 'python_buffer_failed',
					]
				);
			}
			return new WP_Error(
				'wscosm_python_buffer_failed',
				'Python buffer is required but failed to run.',
				[ 'status' => 500 ]
			);
		}
		$features = $py_out;

		if ( empty( $features ) ) {
			if ( '' !== $progress_id ) {
				self::scan_progress_set_long(
					$progress_id,
					[
						'phase'   => 'error',
						'total'   => $n_in,
						'current' => 0,
						'saved'   => 0,
						'message' => 'no_building_buffers',
					]
				);
			}
			return new WP_Error(
				'wscosm_no_building_buffers',
				'No supported OSM building polygons were found for buffer generation.',
				[ 'status' => 409 ]
			);
		}

		$n_gen = count( $features );
		if ( '' !== $progress_id ) {
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'saving',
					'total'   => $n_gen,
					'current' => 0,
					'saved'   => 0,
					'message' => '',
				]
			);
		}

		$stats = self::ingest_generated_yard_features( $city_id, $features, $replace_existing, $progress_id );
		$stats['source_buildings']   = $n_gen;
		$stats['generated_features'] = $n_gen;
		$stats['radius_m']           = $radius_m;

		if ( '' !== $progress_id ) {
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'done',
					'total'   => $n_gen,
					'current' => $n_gen,
					'saved'   => isset( $stats['saved'] ) ? (int) $stats['saved'] : 0,
					'message' => '',
				]
			);
		}

		return new WP_REST_Response( $stats, 200 );
	}

	// (PHP bulk buffer generator removed — Python is required.)

	/**
	 * Пересчитать индекс эргономики для всех придомовых участков города и сбросить кеш GeoJSON полигонов.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function post_recalculate_yards_ergo( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}

		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}

		if ( ! class_exists( 'WSErgo_CPT' ) || ! class_exists( 'WSErgo_Calculator' ) ) {
			return new WP_Error(
				'wscosm_ergo_missing',
				'WorldStat Ergonomics is required.',
				[ 'status' => 409 ]
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 600 );
		}

		$t_batch = microtime( true );

		$city_key   = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
		$entity_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ENTITY_TYPE : 'wsosm_entity_type';

		$q = new WP_Query(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_term_meta_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => $city_key,
						'value' => $city_id,
					],
					[
						'key'   => $entity_key,
						'value' => 'building',
					],
				],
			]
		);

		$ids   = array_map( 'intval', (array) $q->posts );
		$total = count( $ids );

		if ( class_exists( 'WSCOSM_Log' ) ) {
			WSCOSM_Log::ergo_recalc(
				'start',
				[
					'total_yards' => $total,
					'city_title'  => get_the_title( $city_id ),
				],
				$city_id
			);
		}

		$processed               = 0;
		$with_nonzero_dimensions = 0;
		$osm_raw_writes          = 0;

		$kind_index         = [];
		$osm_feature_count  = 0;
		$t_osm              = microtime( true );
		if ( class_exists( 'WSCOSM_Feature_Store' ) && class_exists( 'WSCOSM_Yard_Osm_Raw' ) ) {
			$fc                = WSCOSM_Feature_Store::get_feature_collection_for_city( $city_id, 100000 );
			$osm_feature_count = isset( $fc['features'] ) && is_array( $fc['features'] ) ? count( $fc['features'] ) : 0;
			$kind_index        = WSCOSM_Yard_Osm_Raw::build_kind_index( isset( $fc['features'] ) && is_array( $fc['features'] ) ? $fc['features'] : [] );
		}
		$osm_load_sec = round( microtime( true ) - $t_osm, 3 );
		if ( class_exists( 'WSCOSM_Log' ) ) {
			WSCOSM_Log::ergo_recalc(
				'osm_index_ready',
				[
					'osm_features'       => $osm_feature_count,
					'osm_kinds_indexed'   => count( $kind_index ),
					'duration_prepare_sec' => $osm_load_sec,
				],
				$city_id
			);
		}

		$tick_every = max( 1, (int) apply_filters( 'wscosm_ergo_recalc_log_every', 25 ) );
		$t_loop     = microtime( true );

		foreach ( $ids as $pid ) {
			if ( $pid <= 0 ) {
				continue;
			}
			if ( ! empty( $kind_index ) && class_exists( 'WSCOSM_Yard_Osm_Raw' ) ) {
				$osm_raw_writes += WSCOSM_Yard_Osm_Raw::sync_yard_raw_from_osm( $pid, $kind_index );
			}
			if ( class_exists( 'WSErgo_Indicators' ) ) {
				WSErgo_Indicators::sync_dimension_meta_from_indicators( $pid );
			}
			WSErgo_Calculator::compute_and_store_index( $pid );
			if ( class_exists( 'WSErgo_Model' ) ) {
				$scores = WSErgo_Model::get_scores_from_post( $pid );
				foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
					if ( isset( $scores[ $dim ] ) && (float) $scores[ $dim ] > 0 ) {
						++$with_nonzero_dimensions;
						break;
					}
				}
			}
			++$processed;

			if ( class_exists( 'WSCOSM_Log' ) && $total > 0 && ( $processed === 1 || $processed % $tick_every === 0 || $processed === $total ) ) {
				$elapsed_loop = microtime( true ) - $t_loop;
				$avg          = $processed > 0 ? $elapsed_loop / $processed : 0.0;
				$remaining    = $total - $processed;
				$eta_sec      = ( $avg > 0 && $remaining > 0 ) ? round( $avg * $remaining, 1 ) : 0.0;
				WSCOSM_Log::ergo_recalc(
					'progress',
					[
						'processed'            => $processed,
						'total'                => $total,
						'pct'                  => round( 100 * $processed / $total, 1 ),
						'elapsed_batch_sec'    => round( $elapsed_loop, 2 ),
						'avg_sec_per_yard'     => $processed > 0 ? round( $elapsed_loop / $processed, 4 ) : null,
						'eta_remaining_sec'    => $eta_sec,
						'eta_remaining_human'  => WSCOSM_Log::format_duration_ru( $eta_sec ),
					],
					$city_id
				);
			}
		}

		if ( class_exists( 'WSErgo_Data' ) ) {
			WSErgo_Data::bust_city_polygons_cache( $city_id );
		}

		$indicator_defs = class_exists( 'WSErgo_Indicators' ) ? count( WSErgo_Indicators::get_definitions() ) : 0;
		$duration_total = round( microtime( true ) - $t_batch, 2 );

		if ( class_exists( 'WSCOSM_Log' ) ) {
			WSCOSM_Log::ergo_recalc(
				'complete',
				[
					'processed'               => $processed,
					'total'                   => $total,
					'with_nonzero_dimensions' => $with_nonzero_dimensions,
					'osm_raw_meta_updates'    => $osm_raw_writes,
					'indicator_definitions'   => $indicator_defs,
					'duration_total_sec'      => $duration_total,
					'duration_total_human'    => WSCOSM_Log::format_duration_ru( $duration_total ),
					'osm_prepare_sec'         => $osm_load_sec,
				],
				$city_id
			);
		}

		return new WP_REST_Response(
			[
				'processed'               => $processed,
				'total'                   => $total,
				'city_id'                 => $city_id,
				'with_nonzero_dimensions' => $with_nonzero_dimensions,
				'indicator_definitions'   => $indicator_defs,
				'osm_raw_meta_updates'    => $osm_raw_writes,
				'duration_sec'            => $duration_total,
				'duration_human'          => class_exists( 'WSCOSM_Log' ) ? WSCOSM_Log::format_duration_ru( $duration_total ) : '',
			],
			200
		);
	}

	/**
	 * @return WP_Error|null Null if city is OK for importing generated yards.
	 */
	private static function assert_city_publishable_for_yards( int $city_id ): ?WP_Error {
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}

		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}

		if ( ! class_exists( 'WSErgo_CPT' ) ) {
			return new WP_Error(
				'wscosm_ergo_missing',
				'WorldStat Ergonomics is required to save generated yards.',
				[ 'status' => 409 ]
			);
		}

		return null;
	}

	/**
	 * @param array<int, mixed> $features GeoJSON Feature objects.
	 * @return array{saved:int,deleted:int,skipped:int,errors:array<int, string>}
	 */
	private static function ingest_generated_yard_features( int $city_id, array $features, bool $replace_existing, ?string $progress_id = null ): array {
		$saved        = 0;
		$deleted      = 0;
		$skipped      = 0;
		$errors       = [];
		$prev_suspend = WSErgo_CPT::$suspend_autorecalc;
		WSErgo_CPT::$suspend_autorecalc = true;

		$save_total   = count( $features );
		$tick_every   = max( 1, (int) min( 50, ceil( max( $save_total, 1 ) / 60 ) ) );

		try {
			if ( $replace_existing ) {
				$deleted = self::delete_existing_generated_yards( $city_id );
			}

			foreach ( $features as $feature ) {
				if ( ! is_array( $feature ) || ( $feature['type'] ?? '' ) !== 'Feature' ) {
					++$skipped;
					continue;
				}

				$geometry = isset( $feature['geometry'] ) && is_array( $feature['geometry'] ) ? $feature['geometry'] : null;
				$props    = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : [];
				if ( ! self::is_valid_polygon_geometry( $geometry ) ) {
					++$skipped;
					continue;
				}

				$object_key = sanitize_text_field( (string) ( $props['object_key'] ?? '' ) );
				if ( $object_key === '' ) {
					$osm_type = sanitize_key( (string) ( $props['wscosm_osm_el_type'] ?? '' ) );
					$osm_id   = absint( $props['wscosm_osm_id'] ?? 0 );
					if ( $osm_type !== '' && $osm_id > 0 ) {
						$object_key = $osm_type . ':' . $osm_id;
					}
				}
				if ( $object_key === '' ) {
					++$skipped;
					continue;
				}

				$center = isset( $props['center'] ) && is_array( $props['center'] ) ? $props['center'] : [];
				$lat    = isset( $center['lat'] ) ? (float) $center['lat'] : (float) ( $props['lat'] ?? 0 );
				$lng    = isset( $center['lng'] ) ? (float) $center['lng'] : (float) ( $props['lng'] ?? 0 );
				if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( ! $lat && ! $lng ) ) {
					++$skipped;
					continue;
				}

				$title = sanitize_text_field( (string) ( $props['title'] ?? '' ) );
				if ( $title === '' ) {
					$name  = sanitize_text_field( (string) ( $props['name'] ?? '' ) );
					$title = $name !== '' ? $name : sprintf(
						/* translators: %s: building object id (OSM ref or raster identifier). */
						__( 'Generated yard %s', 'worldstat-courtyard-osm' ),
						$object_key
					);
				}

				$post_id = self::find_existing_voronoi_yard( $city_id, $object_key );
				if ( $post_id > 0 ) {
					$res = wp_update_post(
						[
							'ID'         => $post_id,
							'post_title' => $title,
						],
						true
					);
				} else {
					$res = wp_insert_post(
						[
							'post_type'   => WSErgo_CPT::SLUG_YARD,
							'post_status' => 'publish',
							'post_title'  => $title,
						],
						true
					);
				}

				if ( is_wp_error( $res ) ) {
					$errors[] = $res->get_error_message();
					continue;
				}

				$post_id  = (int) $res;
				$geojson  = wp_json_encode( $geometry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				$city_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
				$type_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ENTITY_TYPE : 'wsosm_entity_type';
				$addr_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ADDRESS_FULL : 'wsosm_address_full';
				$stat_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_STATUS : 'wsosm_status';

				update_post_meta( $post_id, WSErgo_CPT::META_LAT, $lat );
				update_post_meta( $post_id, WSErgo_CPT::META_LNG, $lng );
				update_post_meta( $post_id, WSErgo_CPT::META_GEOJSON, is_string( $geojson ) ? $geojson : '' );
				update_post_meta( $post_id, $city_key, $city_id );
				update_post_meta( $post_id, $type_key, 'building' );
				update_post_meta( $post_id, $addr_key, $title );
				update_post_meta( $post_id, $stat_key, 'raster_allocation' );
				update_post_meta( $post_id, self::META_VORONOI_OBJECT_KEY, substr( $object_key, 0, 96 ) );
				update_post_meta( $post_id, self::META_VORONOI_SOURCE, 'wscosm' );
				update_post_meta( $post_id, 'wscosm_territory_method', sanitize_key( (string) ( $props['method'] ?? 'generated' ) ) );

				if ( isset( $props['wscosm_osm_el_type'] ) ) {
					update_post_meta( $post_id, 'wscosm_osm_el_type', sanitize_key( (string) $props['wscosm_osm_el_type'] ) );
				}
				if ( isset( $props['wscosm_osm_id'] ) ) {
					update_post_meta( $post_id, 'wscosm_osm_id', absint( $props['wscosm_osm_id'] ) );
				}

				++$saved;

				if ( is_string( $progress_id ) && strlen( $progress_id ) === 32 && 0 === $saved % $tick_every ) {
					self::scan_progress_set_long(
						$progress_id,
						[
							'phase'   => 'saving',
							'total'   => $save_total,
							'current' => $saved,
							'saved'   => $saved,
							'message' => '',
						]
					);
				}
			}
		} finally {
			WSErgo_CPT::$suspend_autorecalc = $prev_suspend;
		}

		if ( is_string( $progress_id ) && strlen( $progress_id ) === 32 && $save_total > 0 ) {
			self::scan_progress_set_long(
				$progress_id,
				[
					'phase'   => 'saving',
					'total'   => $save_total,
					'current' => $saved,
					'saved'   => $saved,
					'message' => '',
				]
			);
		}

		if ( class_exists( 'WSErgo_Data' ) ) {
			WSErgo_Data::bust_city_polygons_cache( $city_id );
		}

		return [
			'saved'   => $saved,
			'deleted' => $deleted,
			'skipped' => $skipped,
			'errors'  => array_slice( $errors, 0, 5 ),
		];
	}

	private static function delete_existing_generated_yards( int $city_id ): int {
		$city_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
		$deleted  = 0;

		$generated_conditions = [
			'relation' => 'OR',
			[
				'key'     => self::META_VORONOI_SOURCE,
				'value'   => 'wscosm',
				'compare' => '=',
			],
			[
				'key'     => self::META_VORONOI_OBJECT_KEY,
				'compare' => 'EXISTS',
			],
		];

		do {
			$q = new WP_Query(
				[
					'post_type'              => WSErgo_CPT::SLUG_YARD,
					'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
					'posts_per_page'         => 200,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => [
						'relation' => 'AND',
						[
							'key'   => $city_key,
							'value' => $city_id,
						],
						$generated_conditions,
					],
				]
			);
			$ids = array_map( 'intval', (array) $q->posts );
			foreach ( $ids as $post_id ) {
				if ( wp_delete_post( $post_id, true ) ) {
					++$deleted;
				}
			}
		} while ( ! empty( $ids ) );

		$deleted += self::delete_generated_yards_by_legacy_title( $city_id );

		return $deleted;
	}

	private static function delete_generated_yards_by_legacy_title( int $city_id ): int {
		if ( ! class_exists( 'WSErgo_CPT' ) ) {
			return 0;
		}
		$city_key        = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
		$entity_type_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ENTITY_TYPE : 'wsosm_entity_type';
		$prefixes        = self::legacy_generated_yard_title_prefixes( $city_id );
		$deleted         = 0;

		$ids = get_posts(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page'         => 8000,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => $city_key,
						'value' => $city_id,
					],
					[
						'key'   => $entity_type_key,
						'value' => 'building',
					],
				],
			]
		);

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			if ( '' !== get_post_meta( $post_id, self::META_VORONOI_OBJECT_KEY, true ) ) {
				continue;
			}
			$title      = get_the_title( $post_id );
			$matches_px = false;
			foreach ( $prefixes as $prefix ) {
				$prefix = (string) $prefix;
				if ( $prefix !== '' && strpos( $title, $prefix ) === 0 ) {
					$matches_px = true;
					break;
				}
			}
			if ( ! $matches_px ) {
				continue;
			}
			if ( wp_delete_post( $post_id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * @return array<int, string>
	 */
	private static function legacy_generated_yard_title_prefixes( int $city_id ): array {
		$defaults = [ 'Generated yard ' ];
		$tpl      = __( 'Generated yard %s', 'worldstat-courtyard-osm' );
		if ( is_string( $tpl ) ) {
			$before = strstr( $tpl, '%s', true );
			if ( false !== $before && $before !== '' ) {
				$defaults[] = $before;
			}
		}
		$defaults = array_values( array_unique( array_filter( array_map( 'strval', $defaults ) ) ) );

		return apply_filters( 'wscosm_legacy_generated_yard_title_prefixes', $defaults, $city_id );
	}

	private static function find_existing_voronoi_yard( int $city_id, string $object_key ): int {
		$city_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
		$q        = new WP_Query(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => $city_key,
						'value' => $city_id,
					],
					[
						'key'   => self::META_VORONOI_OBJECT_KEY,
						'value' => substr( $object_key, 0, 96 ),
					],
				],
			]
		);
		$ids = array_map( 'intval', (array) $q->posts );
		if ( ! empty( $ids ) ) {
			return $ids[0];
		}

		if ( ! preg_match( '/^([a-z]+):(\d+)$/', $object_key, $m ) ) {
			return 0;
		}

		$q = new WP_Query(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => $city_key,
						'value' => $city_id,
					],
					[
						'key'   => 'wscosm_osm_el_type',
						'value' => sanitize_key( $m[1] ),
					],
					[
						'key'   => 'wscosm_osm_id',
						'value' => absint( $m[2] ),
					],
				],
			]
		);
		$ids = array_map( 'intval', (array) $q->posts );
		return empty( $ids ) ? 0 : $ids[0];
	}

	/**
	 * @param mixed $geometry GeoJSON geometry.
	 */
	private static function is_valid_polygon_geometry( $geometry ): bool {
		if ( ! is_array( $geometry ) || ! isset( $geometry['type'], $geometry['coordinates'] ) ) {
			return false;
		}
		if ( (string) $geometry['type'] !== 'Polygon' ) {
			return false;
		}
		$rings = $geometry['coordinates'];
		if ( ! is_array( $rings ) || empty( $rings[0] ) || ! is_array( $rings[0] ) || count( $rings[0] ) < 4 ) {
			return false;
		}
		foreach ( $rings[0] as $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) < 2 || ! is_numeric( $pair[0] ) || ! is_numeric( $pair[1] ) ) {
				return false;
			}
			$lon = (float) $pair[0];
			$lat = (float) $pair[1];
			if ( $lon < -180 || $lon > 180 || $lat < -90 || $lat > 90 ) {
				return false;
			}
		}
		return true;
	}

}
