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

	public static function can_activate_territory_job_request( WP_REST_Request $request ): bool {
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( $job_id === '' || ! class_exists( 'WSCOSM_Territory_Job' ) ) {
			return false;
		}
		$city_id = WSCOSM_Territory_Job::get_city_id_for_job( $job_id );
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
			'/city/(?P<id>\d+)/voronoi-yards',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_voronoi_yards' ],
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
			'/city/(?P<id>\d+)/territory-jobs',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'start_territory_job' ],
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
			'/territory-jobs/(?P<job_id>[a-zA-Z0-9]+)/status',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_territory_job_status' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NS,
			'/territory-jobs/(?P<job_id>[a-zA-Z0-9]+)/result',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_territory_job_result' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NS,
			'/territory-jobs/(?P<job_id>[a-zA-Z0-9]+)/activate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'activate_territory_job' ],
				'permission_callback' => [ self::class, 'can_activate_territory_job_request' ],
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

	public static function start_territory_job( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) || ! class_exists( 'WSCOSM_Territory_Job' ) ) {
			return new WP_Error( 'wscosm_bad_city', 'Invalid city id.', [ 'status' => 400 ] );
		}
		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			return new WP_Error( 'wscosm_not_found', 'City not found.', [ 'status' => 404 ] );
		}
		$lat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		if ( ! $lat || ! $lng ) {
			return new WP_Error( 'wscosm_no_coords', 'City coordinates are missing.', [ 'status' => 400 ] );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$bounds = isset( $params['bounds'] ) && is_array( $params['bounds'] ) ? $params['bounds'] : $params;
		$has_bbox = isset( $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'] );
		if ( $has_bbox ) {
			$bbox = WSCOSM_Overpass::normalize_client_bbox(
				$lat,
				$lng,
				[
					's' => (float) $bounds['south'],
					'w' => (float) $bounds['west'],
					'n' => (float) $bounds['north'],
					'e' => (float) $bounds['east'],
				]
			);
			if ( is_wp_error( $bbox ) ) {
				return $bbox;
			}
		} else {
			$bbox = WSCOSM_Overpass::bbox_from_center( $lat, $lng, WSCOSM_Overpass::default_radius_km() );
		}
		$config = isset( $params['config'] ) && is_array( $params['config'] ) ? $params['config'] : [];
		$status = WSCOSM_Territory_Job::enqueue( $city_id, $bbox, $config );
		$job_id = (string) ( $status['job_id'] ?? '' );
		$status['status_url'] = rest_url( self::NS . '/territory-jobs/' . $job_id . '/status' );
		$status['result_url'] = rest_url( self::NS . '/territory-jobs/' . $job_id . '/result' );
		$status['activate_url'] = rest_url( self::NS . '/territory-jobs/' . $job_id . '/activate' );
		return new WP_REST_Response( $status, 202 );
	}

	public static function get_territory_job_status( WP_REST_Request $request ) {
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( $job_id === '' || ! class_exists( 'WSCOSM_Territory_Job' ) ) {
			return new WP_Error( 'wscosm_bad_job', 'Invalid job id.', [ 'status' => 400 ] );
		}
		WSCOSM_Territory_Job::maybe_run_queued( $job_id );
		return new WP_REST_Response( WSCOSM_Territory_Job::get_status( $job_id ), 200 );
	}

	public static function get_territory_job_result( WP_REST_Request $request ) {
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( $job_id === '' || ! class_exists( 'WSCOSM_Territory_Job' ) ) {
			return new WP_Error( 'wscosm_bad_job', 'Invalid job id.', [ 'status' => 400 ] );
		}
		return new WP_REST_Response( WSCOSM_Territory_Job::get_result( $job_id ), 200 );
	}

	/**
	 * Atomically replace wscosm-generated yards with the finished territory job GeoJSON result.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function activate_territory_job( WP_REST_Request $request ) {
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( $job_id === '' || ! class_exists( 'WSCOSM_Territory_Job' ) ) {
			return new WP_Error( 'wscosm_bad_job', 'Invalid job id.', [ 'status' => 400 ] );
		}

		WSCOSM_Territory_Job::maybe_run_queued( $job_id );
		$job_status = WSCOSM_Territory_Job::get_status( $job_id );
		if ( (string) ( $job_status['status'] ?? '' ) !== 'done' ) {
			return new WP_Error(
				'wscosm_job_not_ready',
				'Territory job is not finished yet.',
				[ 'status' => 409 ]
			);
		}

		$city_id = WSCOSM_Territory_Job::get_city_id_for_job( $job_id );
		$city_err = self::assert_city_publishable_for_yards( $city_id );
		if ( $city_err instanceof WP_Error ) {
			return $city_err;
		}

		$result   = WSCOSM_Territory_Job::get_result( $job_id );
		$features = isset( $result['features'] ) && is_array( $result['features'] ) ? $result['features'] : [];
		if ( empty( $features ) ) {
			return new WP_Error(
				'wscosm_empty_territory_result',
				'Job result has no territory features.',
				[ 'status' => 400 ]
			);
		}

		$stats = self::ingest_generated_yard_features( $city_id, $features, true );
		return new WP_REST_Response( $stats, 200 );
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
	 * Save generated raster allocation territories as WorldStat Ergonomics yards.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_voronoi_yards( WP_REST_Request $request ) {
		$city_id = (int) $request->get_param( 'id' );
		$city_err = self::assert_city_publishable_for_yards( $city_id );
		if ( $city_err instanceof WP_Error ) {
			return $city_err;
		}

		$params           = $request->get_json_params();
		$features         = isset( $params['features'] ) && is_array( $params['features'] ) ? $params['features'] : [];
		$replace_existing = isset( $params['replace_existing'] ) && (bool) $params['replace_existing'];
		if ( empty( $features ) ) {
			return new WP_Error( 'wscosm_empty_generated_yards', 'No generated yard features provided.', [ 'status' => 400 ] );
		}

		$features = array_slice( $features, 0, 500 );
		$stats    = self::ingest_generated_yard_features( $city_id, $features, $replace_existing );
		return new WP_REST_Response( $stats, 200 );
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
	private static function ingest_generated_yard_features( int $city_id, array $features, bool $replace_existing ): array {
		$saved        = 0;
		$deleted      = 0;
		$skipped      = 0;
		$errors       = [];
		$prev_suspend = WSErgo_CPT::$suspend_autorecalc;
		WSErgo_CPT::$suspend_autorecalc = true;

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
					$name = sanitize_text_field( (string) ( $props['name'] ?? '' ) );
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
			}
		} finally {
			WSErgo_CPT::$suspend_autorecalc = $prev_suspend;
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
		/**
		 * Optional: widen “replace dataset” deletes (never delete unrelated yards unless they match WP_Query below).
		 */
		$generated_conditions = apply_filters( 'wscosm_territory_replace_conditions', $generated_conditions, $city_id );

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

	/**
	 * Deletes plugin-generated yards that lack wscosm_voronoi_object_key but match known auto-generated title prefixes.
	 */
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
	 * Title prefixes used by auto-generated yard posts without meta object_key (legacy saves).
	 *
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
