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

	public static function can_live_overpass( int $city_id ): bool {
		$allowed = current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $city_id );
		return (bool) apply_filters( 'wscosm_can_live_overpass', $allowed, $city_id );
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
}
