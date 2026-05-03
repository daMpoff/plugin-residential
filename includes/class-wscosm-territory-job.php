<?php
/**
 * Server-side constrained territory allocation jobs.
 *
 * Geometry engine: PHP (equirectangular-ish local meters). PostGIS is preferred
 * for accuracy at scale; this implementation matches current XAMPP/MySQL stacks.
 * Use filter `wscosm_territory_geometry_engine` later to swap to PostGIS/SQL.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Territory_Job {

	public const METHOD = 'server_constrained_distance_allocation';

	private const TRANSIENT_TTL = 7200;

	private static $last_smoothing_ms = 0.0;

	private static $last_holes_removed = 0;

	/**
	 * City id from job payload (for REST permission checks).
	 */
	public static function get_city_id_for_job( string $job_id ): int {
		$payload = self::get_payload( $job_id );
		return is_array( $payload ) ? max( 0, (int) ( $payload['city_id'] ?? 0 ) ) : 0;
	}

	public static function default_config(): array {
		return [
			'preset'           => 'standard',
			'cell_size_m'      => 3.0,
			'max_distance_m'   => 38.0,
			'min_area_m2'      => 10.0,
			'max_grid_cells'   => 160000,
			'use_line_of_sight'=> true,
			'use_footways'     => false,
			'smooth_tolerance_m' => 0.0,
			'min_hole_area_m2' => 18.0,
			'road_buffers'     => [
				'motorway'     => 18.0,
				'motorway_link'=> 12.0,
				'trunk'        => 16.0,
				'trunk_link'   => 10.0,
				'primary'      => 15.0,
				'primary_link' => 9.0,
				'secondary'    => 12.0,
				'tertiary'     => 10.0,
				'unclassified' => 8.0,
				'residential'   => 7.0,
				'living_street' => 6.0,
				'service'      => 4.0,
				'footway'      => 3.0,
				'pedestrian'   => 4.0,
				'path'         => 3.0,
				'default'      => 8.0,
			],
		];
	}

	private static function normalize_config( array $config ): array {
		$defaults = self::default_config();
		$map = [
			'cellSizeMeters'        => 'cell_size_m',
			'maxDistanceMeters'     => 'max_distance_m',
			'minAreaM2'             => 'min_area_m2',
			'maxGridCells'          => 'max_grid_cells',
			'useLineOfSightCheck'   => 'use_line_of_sight',
			'useFootwaysAsBarriers' => 'use_footways',
			'smoothToleranceMeters' => 'smooth_tolerance_m',
			'minHoleAreaM2'         => 'min_hole_area_m2',
			'roadBuffers'           => 'road_buffers',
		];
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $config ) && ! array_key_exists( $to, $config ) ) {
				$config[ $to ] = $config[ $from ];
			}
		}
		$preset = sanitize_key( (string) ( $config['preset'] ?? $defaults['preset'] ) );
		if ( $preset === 'high_accuracy' ) {
			$defaults = array_merge(
				$defaults,
				[
					'preset'             => 'high_accuracy',
					'cell_size_m'        => 4.0,
					'max_distance_m'     => 35.0,
					'max_grid_cells'     => 260000,
					'use_line_of_sight'  => true,
					'smooth_tolerance_m' => 0.75,
					'min_hole_area_m2'   => 22.0,
				]
			);
		}
		$out = array_merge( $defaults, $config );
		$out['preset']         = in_array( sanitize_key( (string) $out['preset'] ), [ 'standard', 'high_accuracy' ], true ) ? sanitize_key( (string) $out['preset'] ) : 'standard';
		$out['cell_size_m']    = max( 1.0, (float) $out['cell_size_m'] );
		if ( $out['preset'] === 'high_accuracy' ) {
			$out['cell_size_m'] = max( 4.0, (float) $out['cell_size_m'] );
		}
		$out['max_distance_m'] = max( 1.0, (float) $out['max_distance_m'] );
		$out['min_area_m2']    = max( 0.0, (float) $out['min_area_m2'] );
		$out['max_grid_cells'] = max( 100, (int) $out['max_grid_cells'] );
		$out['use_line_of_sight'] = (bool) $out['use_line_of_sight'];
		$out['use_footways']   = (bool) $out['use_footways'];
		$out['smooth_tolerance_m'] = max( 0.0, min( (float) $out['smooth_tolerance_m'], (float) $out['cell_size_m'] * 0.5 ) );
		$out['min_hole_area_m2'] = max( 0.0, (float) $out['min_hole_area_m2'] );
		if ( ! is_array( $out['road_buffers'] ) ) {
			$out['road_buffers'] = $defaults['road_buffers'];
		} else {
			$out['road_buffers'] = array_merge( $defaults['road_buffers'], $out['road_buffers'] );
		}
		return $out;
	}

	public static function planning_diagnostics( array $bbox, array $config = [] ): array {
		$config = self::normalize_config( $config );
		$origin_lat = ( (float) $bbox['s'] + (float) $bbox['n'] ) / 2.0;
		$sw = self::project_point( (float) $bbox['w'], (float) $bbox['s'], $origin_lat );
		$ne = self::project_point( (float) $bbox['e'], (float) $bbox['n'], $origin_lat );
		$width = max( 1.0, $ne['x'] - $sw['x'] );
		$height = max( 1.0, $ne['y'] - $sw['y'] );
		$requested = max( 1.0, (float) $config['cell_size_m'] );
		$max_cells = max( 1, (int) $config['max_grid_cells'] );
		$requested_nx = max( 1, (int) ceil( $width / $requested ) );
		$requested_ny = max( 1, (int) ceil( $height / $requested ) );
		$requested_cells = $requested_nx * $requested_ny;
		$effective_cell = max( $requested, sqrt( ( $width * $height ) / $max_cells ) );
		$effective_nx = max( 1, (int) ceil( $width / $effective_cell ) );
		$effective_ny = max( 1, (int) ceil( $height / $effective_cell ) );

		return [
			'preset' => (string) $config['preset'],
			'requested_cell_size_m' => round( $requested, 2 ),
			'effective_cell_size_m' => round( $effective_cell, 2 ),
			'max_grid_cells' => $max_cells,
			'requested_grid_cells' => $requested_cells,
			'effective_grid_cells' => $effective_nx * $effective_ny,
			'bbox_width_m' => round( $width, 1 ),
			'bbox_height_m' => round( $height, 1 ),
			'bbox_area_km2' => round( self::bbox_area_km2( $bbox ), 3 ),
		];
	}

	public static function validate_bbox_for_job( array $bbox, array $config = [] ) {
		$plan = self::planning_diagnostics( $bbox, $config );
		$requested = max( 1.0, (float) $plan['requested_cell_size_m'] );
		$effective = max( 1.0, (float) $plan['effective_cell_size_m'] );
		$max_multiplier = (float) apply_filters( 'wscosm_territory_max_cell_multiplier', 1.15, $plan );
		$too_coarse = $effective > $requested * max( 1.0, $max_multiplier );

		if ( $too_coarse || (int) $plan['requested_grid_cells'] > (int) $plan['max_grid_cells'] ) {
			return new WP_Error(
				'wscosm_territory_bbox_too_large',
				sprintf(
					'Слишком большая область для расчетной придомовой территории: нужно примерно %s клеток при %.2f м, лимит %s. Приблизьте карту или сканируйте меньший квартал.',
					number_format_i18n( (int) $plan['requested_grid_cells'] ),
					$requested,
					number_format_i18n( (int) $plan['max_grid_cells'] )
				),
				[
					'status' => 400,
					'plan'   => $plan,
				]
			);
		}

		return $plan;
	}

	public static function enqueue( int $city_id, array $bbox, array $config = [] ): array {
		$job_id = str_replace( '-', '', wp_generate_uuid4() );
		$token  = wp_generate_password( 32, false, false );
		$payload = [
			'job_id'     => $job_id,
			'token'      => $token,
			'city_id'    => $city_id,
			'bbox'       => $bbox,
			'config'     => self::normalize_config( $config ),
			'created_at' => time(),
		];
		self::set_payload( $job_id, $payload );
		self::set_status(
			$job_id,
			[
				'job_id'     => $job_id,
				'status'     => 'queued',
				'phase'      => 'queued',
				'current'    => 0,
				'total'      => 1,
				'message'    => 'Queued',
				'created_at' => gmdate( 'c' ),
				'config'     => self::public_config_snapshot( (array) $payload['config'] ),
			]
		);

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => [
					'action' => 'wscosm_run_territory_job',
					'job_id' => $job_id,
					'token'  => $token,
				],
			]
		);

		return self::get_status( $job_id );
	}

	public static function enqueue_city( int $city_id, array $config = [] ): array {
		$job_id = str_replace( '-', '', wp_generate_uuid4() );
		$token  = wp_generate_password( 32, false, false );
		$payload = [
			'job_id'     => $job_id,
			'token'      => $token,
			'city_id'    => $city_id,
			'mode'       => 'python_worker',
			'bbox'       => [],
			'config'     => self::normalize_config( $config ),
			'created_at' => time(),
		];
		self::set_payload( $job_id, $payload );
		self::set_status(
			$job_id,
			[
				'job_id'     => $job_id,
				'status'     => 'queued',
				'phase'      => 'queued',
				'current'    => 0,
				'total'      => 1,
				'message'    => 'Queued Python territory worker',
				'created_at' => gmdate( 'c' ),
				'mode'       => 'python_worker',
				'config'     => self::public_config_snapshot( (array) $payload['config'] ),
			]
		);

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => [
					'action' => 'wscosm_run_territory_job',
					'job_id' => $job_id,
					'token'  => $token,
				],
			]
		);

		return self::get_status( $job_id );
	}

	public static function ajax_run(): void {
		$job_id  = sanitize_key( (string) ( $_POST['job_id'] ?? '' ) );
		$token   = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );
		$payload = self::get_payload( $job_id );
		if ( ! $payload || ! hash_equals( (string) ( $payload['token'] ?? '' ), $token ) ) {
			wp_send_json_error( [ 'message' => 'bad_job' ], 403 );
		}
		self::run( $job_id );
		wp_send_json_success( self::get_status( $job_id ) );
	}

	public static function run( string $job_id ): void {
		@set_time_limit( 0 );
		$payload = self::get_payload( $job_id );
		if ( ! $payload ) {
			return;
		}
		self::set_status(
			$job_id,
			[
				'job_id'  => $job_id,
				'status'  => 'running',
				'phase'   => 'loading',
				'current' => 0,
				'total'   => 1,
				'message' => 'Loading OSM objects',
				'config'  => self::public_config_snapshot( (array) $payload['config'] ),
			]
		);

		try {
			$progress = static function ( string $phase, int $current, int $total, string $message = '' ) use ( $job_id, $payload ): void {
					self::set_status(
						$job_id,
						[
							'job_id'  => $job_id,
							'status'  => 'running',
							'phase'   => $phase,
							'current' => $current,
							'total'   => max( 1, $total ),
							'message' => $message,
							'updated_at' => gmdate( 'c' ),
							'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 2 ),
							'mode' => (string) ( $payload['mode'] ?? 'bbox' ),
							'config' => self::public_config_snapshot( (array) $payload['config'] ),
						]
					);
				};
			$mode = (string) ( $payload['mode'] ?? '' );
			if ( $mode === 'python_worker' ) {
				$result = self::compute_python_worker( (int) $payload['city_id'], (array) $payload['config'], $progress );
			} elseif ( $mode === 'city_sequential' ) {
				$result = self::compute_city_sequential( (int) $payload['city_id'], (array) $payload['config'], $progress );
			} else {
				$result = self::compute( (int) $payload['city_id'], (array) $payload['bbox'], (array) $payload['config'], $progress );
			}
			self::set_result( $job_id, $result );
			self::set_status(
				$job_id,
				[
					'job_id'  => $job_id,
					'status'  => 'done',
					'phase'   => 'done',
					'current' => count( $result['features'] ?? [] ),
					'total'   => count( $result['features'] ?? [] ),
					'message' => 'Done',
					'stats'   => $result['stats'] ?? [],
				]
			);
		} catch ( \Throwable $e ) {
			self::set_status(
				$job_id,
				[
					'job_id'  => $job_id,
					'status'  => 'error',
					'phase'   => 'error',
					'current' => 0,
					'total'   => 1,
					'message' => $e->getMessage(),
				]
			);
		}
	}

	public static function get_status( string $job_id ): array {
		$status = get_transient( self::status_key( $job_id ) );
		return is_array( $status ) ? $status : [ 'job_id' => $job_id, 'status' => 'missing', 'phase' => 'missing' ];
	}

	public static function maybe_run_queued( string $job_id ): void {
		$status = self::get_status( $job_id );
		if ( (string) ( $status['status'] ?? '' ) !== 'queued' ) {
			return;
		}
		$payload = self::get_payload( $job_id );
		$created = is_array( $payload ) ? (int) ( $payload['created_at'] ?? 0 ) : 0;
		if ( $created > 0 && time() - $created < 2 ) {
			return;
		}
		self::run( $job_id );
	}

	public static function get_result( string $job_id ): array {
		$result = get_transient( self::result_key( $job_id ) );
		if ( is_array( $result ) && isset( $result['_file'] ) ) {
			$path = (string) $result['_file'];
			if ( $path !== '' && is_file( $path ) ) {
				$decoded = json_decode( (string) file_get_contents( $path ), true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}
		return is_array( $result ) ? $result : [ 'type' => 'FeatureCollection', 'features' => [] ];
	}

	private static function compute( int $city_id, array $bbox, array $config, callable $progress ): array {
		$job_started = microtime( true );
		self::$last_smoothing_ms = 0.0;
		self::$last_holes_removed = 0;
		$events = [];
		self::trace_event( $events, 'start', [ 'city_id' => $city_id, 'bbox' => $bbox, 'config' => self::public_config_snapshot( $config ) ] );
		$t = microtime( true );
		$fc = class_exists( 'WSCOSM_Feature_Store' )
			? WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox, 50000 )
			: [ 'type' => 'FeatureCollection', 'features' => [] ];
		$fetch_osm_ms = self::elapsed_ms( $t );
		$features = is_array( $fc['features'] ?? null ) ? $fc['features'] : [];
		self::trace_event( $events, 'fetch_osm_done', [ 'ms' => $fetch_osm_ms, 'features' => count( $features ) ] );
		$origin_lat = ( (float) $bbox['s'] + (float) $bbox['n'] ) / 2.0;
		$t = microtime( true );
		$inputs = self::collect_inputs( $features, $origin_lat, $config );
		$classify_ms = self::elapsed_ms( $t );
		self::trace_event( $events, 'classify_done', self::input_counts( $inputs ) + [ 'ms' => $classify_ms ] );
		$progress( 'grid', 0, 1, 'Building free grid' );
		$t = microtime( true );
		$grid = self::build_grid( $bbox, $origin_lat, $inputs, $config, $progress );
		$grid_build_ms = self::elapsed_ms( $t );
		self::trace_event( $events, 'grid_done', [ 'ms' => $grid_build_ms, 'nx' => $grid['nx'], 'ny' => $grid['ny'], 'cell' => $grid['cell'], 'free' => $grid['free_count'] ] );
		$progress( 'allocation', 0, max( 1, $grid['free_count'] ), 'Allocating cells' );
		$t = microtime( true );
		$allocation = self::allocate_cells( $grid, $inputs, $config, $progress );
		$nearest_assignment_ms = self::elapsed_ms( $t );
		self::trace_event( $events, 'allocation_done', [ 'ms' => $nearest_assignment_ms, 'assigned' => $allocation['assigned_count'], 'los_rejected' => $allocation['line_of_sight_rejected'], 'los_ms' => $allocation['line_of_sight_ms'] ] );
		$progress( 'polygonize', 0, max( 1, count( $allocation['by_building'] ) ), 'Polygonizing' );
		$t = microtime( true );
		$territories = self::polygonize( $allocation['by_building'], $grid, $origin_lat, $config, $inputs['buildings'], $inputs, $allocation['meta'] );
		$polygonize_ms = self::elapsed_ms( $t );
		self::trace_event( $events, 'polygonize_done', [ 'ms' => $polygonize_ms, 'territories' => count( $territories ), 'smoothing_ms' => round( self::$last_smoothing_ms, 2 ) ] );
		$warnings = self::quality_warnings( $inputs, $grid, $allocation, $territories );
		$grid_cells_total = $grid['nx'] * $grid['ny'];
		$free_cells = (int) $grid['free_count'];
		$assigned_cells = (int) $allocation['assigned_count'];
		$bbox_area_km2 = self::bbox_area_km2( $bbox );
		$assigned_ratio = $free_cells > 0 ? round( $assigned_cells / $free_cells, 4 ) : 0.0;
		$rejected_los_ratio = $free_cells > 0 ? round( (int) $allocation['line_of_sight_rejected'] / $free_cells, 4 ) : 0.0;
		$building_type_coverage_ratio = count( $inputs['buildings'] ) > 0 ? round( (int) $inputs['typed_buildings_count'] / count( $inputs['buildings'] ), 4 ) : 0.0;
		$quality_level = self::osm_quality_level( $inputs, $assigned_ratio, $rejected_los_ratio, $building_type_coverage_ratio );
		$encode_started = microtime( true );
		$encoded_probe = wp_json_encode( [ 'type' => 'FeatureCollection', 'features' => $territories ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$geojson_encode_ms = self::elapsed_ms( $encode_started );
		$geojson_size_kb = is_string( $encoded_probe ) ? round( strlen( $encoded_probe ) / 1024, 1 ) : null;
		self::trace_event( $events, 'encode_probe_done', [ 'ms' => $geojson_encode_ms, 'geojson_size_kb' => $geojson_size_kb ] );
		$stats = [
			'method'          => self::METHOD,
			'source_preset'   => (string) $config['preset'],
			'buildings'       => count( $inputs['buildings'] ),
			'buildings_count' => count( $inputs['buildings'] ),
			'residential_buildings_count' => (int) $inputs['residential_buildings_count'],
			'building_type_coverage_ratio' => $building_type_coverage_ratio,
			'roads'           => count( $inputs['roads'] ),
			'roads_count'     => count( $inputs['roads'] ) + count( $inputs['soft_roads'] ),
			'hard_barriers_count' => count( $inputs['roads'] ) + count( $inputs['railways'] ) + count( $inputs['barriers'] ),
			'soft_barriers_count' => count( $inputs['soft_roads'] ) + count( $inputs['soft_areas'] ),
			'railways'        => count( $inputs['railways'] ),
			'railway_count'   => count( $inputs['railways'] ),
			'waters'          => count( $inputs['waters'] ),
			'water_count'     => count( $inputs['waters'] ),
			'barriers'        => count( $inputs['barriers'] ),
			'area_obstacles'  => count( $inputs['areas'] ),
			'polygon_obstacles_count' => count( $inputs['areas'] ),
			'obstacles'       => count( $inputs['buildings'] ) + count( $inputs['roads'] ) + count( $inputs['railways'] ) + count( $inputs['waters'] ) + count( $inputs['barriers'] ) + count( $inputs['areas'] ),
			'grid_cells'      => $grid_cells_total,
			'grid_cells_total'=> $grid_cells_total,
			'free_cells'      => $free_cells,
			'grid_cells_free' => $free_cells,
			'assigned_cells'  => $assigned_cells,
			'grid_cells_assigned' => $assigned_cells,
			'grid_cells_rejected_obstacle' => max( 0, $grid_cells_total - $free_cells ),
			'unassigned_cells'=> max( 0, $free_cells - $assigned_cells ),
			'grid_cells_unassigned'=> max( 0, $free_cells - $assigned_cells ),
			'line_of_sight_rejected_cells' => (int) $allocation['line_of_sight_rejected'],
			'grid_cells_rejected_los' => (int) $allocation['line_of_sight_rejected'],
			'territories'     => count( $territories ),
			'generated_polygons_count' => count( $territories ),
			'holes_removed_count' => self::$last_holes_removed,
			'cell_size_m'     => $grid['cell'],
			'max_distance_m'  => (float) $config['max_distance_m'],
			'distance_median' => self::median( $allocation['distances'] ),
			'median_distance_m' => self::median( $allocation['distances'] ),
			'p95_distance_m'  => self::percentile( $allocation['distances'], 0.95 ),
			'max_assigned_distance_m' => empty( $allocation['distances'] ) ? null : round( max( $allocation['distances'] ), 2 ),
			'assigned_ratio'  => $assigned_ratio,
			'rejected_los_ratio' => $rejected_los_ratio,
			'osm_quality_level' => $quality_level,
			'has_residential_container' => false,
			'has_apartment_complex_container' => false,
			'barrier_density_per_km2' => $bbox_area_km2 > 0 ? round( ( count( $inputs['roads'] ) + count( $inputs['railways'] ) + count( $inputs['barriers'] ) ) / $bbox_area_km2, 2 ) : null,
			'total_job_ms'    => self::elapsed_ms( $job_started ),
			'fetch_osm_ms'    => $fetch_osm_ms,
			'classify_ms'     => $classify_ms,
			'grid_build_ms'   => $grid_build_ms,
			'nearest_assignment_ms' => $nearest_assignment_ms,
			'line_of_sight_ms'=> (float) $allocation['line_of_sight_ms'],
			'polygonize_ms'   => $polygonize_ms,
			'smoothing_ms'    => round( self::$last_smoothing_ms, 2 ),
			'geojson_encode_ms' => $geojson_encode_ms,
			'geojson_size_kb' => $geojson_size_kb,
			'memory_peak_mb'  => round( memory_get_peak_usage( true ) / 1048576, 2 ),
			'php_memory_limit'=> ini_get( 'memory_limit' ),
			'estimated_grid_memory_mb' => round( $grid_cells_total * 18 / 1048576, 2 ),
			'warnings'        => $warnings,
		];
		return [
			'type'     => 'FeatureCollection',
			'features' => $territories,
			'stats'    => $stats,
			'debug'    => [
				'stats' => $stats,
				'events' => $events,
			],
		];
	}

	private static function compute_city_sequential( int $city_id, array $config, callable $progress ): array {
		$job_started = microtime( true );
		self::$last_smoothing_ms = 0.0;
		self::$last_holes_removed = 0;
		$events = [];
		self::trace_event( $events, 'start_city_sequential', [ 'city_id' => $city_id, 'config' => self::public_config_snapshot( $config ) ] );

		$progress( 'fetch_osm', 0, 1, 'Loading all city OSM objects' );
		$t = microtime( true );
		$fc = class_exists( 'WSCOSM_Feature_Store' )
			? WSCOSM_Feature_Store::get_feature_collection_for_city( $city_id, 100000 )
			: [ 'type' => 'FeatureCollection', 'features' => [] ];
		$fetch_osm_ms = self::elapsed_ms( $t );
		$features = is_array( $fc['features'] ?? null ) ? $fc['features'] : [];
		self::trace_event( $events, 'fetch_city_osm_done', [ 'ms' => $fetch_osm_ms, 'features' => count( $features ) ] );

		$city_bbox = self::feature_collection_bbox( $features );
		if ( ! $city_bbox ) {
			return self::empty_result(
				self::METHOD,
				$config,
				[
					'calculation_scope' => 'city_sequential',
					'osm_features_count' => count( $features ),
					'target_buildings_count' => 0,
					'total_job_ms' => self::elapsed_ms( $job_started ),
					'fetch_osm_ms' => $fetch_osm_ms,
					'warnings' => [ 'no_osm_features_for_city' ],
				],
				$events
			);
		}

		$origin_lat = ( (float) $city_bbox['s'] + (float) $city_bbox['n'] ) / 2.0;
		$t = microtime( true );
		$inputs = self::collect_inputs( $features, $origin_lat, $config );
		$classify_ms = self::elapsed_ms( $t );
		$targets = array_values( $inputs['buildings'] );
		$total_targets = count( $targets );
		self::trace_event( $events, 'classify_city_done', self::input_counts( $inputs ) + [ 'ms' => $classify_ms, 'target_buildings' => $total_targets ] );

		$territories = [];
		$distances = [];
		$grid_cells_total = 0;
		$free_cells = 0;
		$assigned_cells = 0;
		$los_rejected = 0;
		$line_of_sight_ms = 0.0;
		$grid_build_ms = 0.0;
		$nearest_assignment_ms = 0.0;
		$polygonize_ms = 0.0;
		$skipped_no_cells = 0;
		$chunk_size_m = (float) apply_filters( 'wscosm_territory_chunk_size_m', 220.0 );
		$chunks = self::building_chunks( $targets, $chunk_size_m );
		$total_chunks = count( $chunks );
		$processed_targets = 0;
		$progress( 'chunks', 0, max( 1, $total_chunks ), 'Processing city building chunks' );

		foreach ( $chunks as $chunk_index => $chunk_targets ) {
			if ( empty( $chunk_targets ) ) {
				continue;
			}
			$target_keys = [];
			$chunk_bounds = null;
			foreach ( $chunk_targets as $target ) {
				$target_key = (string) ( $target['key'] ?? '' );
				if ( $target_key === '' || ! is_array( $target['bounds'] ?? null ) ) {
					continue;
				}
				$target_keys[ $target_key ] = true;
				$chunk_bounds = $chunk_bounds === null ? $target['bounds'] : self::merge_projected_bounds( $chunk_bounds, $target['bounds'] );
			}
			if ( empty( $target_keys ) || ! $chunk_bounds ) {
				continue;
			}
			$local_bounds = self::expand_projected_bounds( $chunk_bounds, (float) $config['max_distance_m'] + max( 10.0, (float) $config['cell_size_m'] * 3.0 ) );
			$local_bbox = self::bbox_from_projected_bounds( $local_bounds, $origin_lat );
			$local_inputs = self::slice_inputs_by_projected_bounds( $inputs, $local_bounds );

			$t = microtime( true );
			$grid = self::build_grid( $local_bbox, $origin_lat, $local_inputs, $config, null );
			$grid_build_ms += self::elapsed_ms( $t );
			$grid_cells_total += (int) $grid['nx'] * (int) $grid['ny'];
			$free_cells += (int) $grid['free_count'];

			$t = microtime( true );
			$allocation = self::allocate_cells(
				$grid,
				$local_inputs,
				$config,
				static function (): void {}
			);
			$nearest_assignment_ms += self::elapsed_ms( $t );
			$line_of_sight_ms += (float) $allocation['line_of_sight_ms'];
			$los_rejected += (int) $allocation['line_of_sight_rejected'];

			$target_cells = [];
			$target_meta = [];
			foreach ( $target_keys as $target_key => $_ ) {
				if ( isset( $allocation['by_building'][ $target_key ] ) ) {
					$target_cells[ $target_key ] = $allocation['by_building'][ $target_key ];
					$assigned_cells += count( $allocation['by_building'][ $target_key ] );
					if ( isset( $allocation['meta'][ $target_key ] ) ) {
						$target_meta[ $target_key ] = $allocation['meta'][ $target_key ];
						if ( ! empty( $target_meta[ $target_key ]['distances'] ) && is_array( $target_meta[ $target_key ]['distances'] ) ) {
							$distances = array_merge( $distances, $target_meta[ $target_key ]['distances'] );
						}
					}
				} else {
					++$skipped_no_cells;
				}
			}
			if ( ! empty( $target_cells ) ) {
				$t = microtime( true );
				$made = self::polygonize( $target_cells, $grid, $origin_lat, $config, $local_inputs['buildings'], $local_inputs, $target_meta );
				$polygonize_ms += self::elapsed_ms( $t );
				foreach ( $made as $feature ) {
					if ( isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) {
						$feature['properties']['calculation_scope'] = 'city_sequential';
					}
					$territories[] = $feature;
				}
			}
			$processed_targets += count( $target_keys );

			$progress(
				'chunks',
				$chunk_index + 1,
				max( 1, $total_chunks ),
				sprintf( 'Processing city building chunks (%d/%d, buildings %d/%d)', $chunk_index + 1, $total_chunks, $processed_targets, $total_targets )
			);
		}

		$warnings = self::quality_warnings(
			$inputs,
			[ 'free_count' => max( 1, $free_cells ), 'nx' => 1, 'ny' => max( 1, $grid_cells_total ) ],
			[ 'assigned_count' => $assigned_cells, 'line_of_sight_rejected' => $los_rejected ],
			$territories
		);
		if ( $skipped_no_cells > 0 ) {
			$warnings[] = 'some_buildings_without_assigned_cells';
		}
		$assigned_ratio = $free_cells > 0 ? round( $assigned_cells / $free_cells, 4 ) : 0.0;
		$rejected_los_ratio = $free_cells > 0 ? round( $los_rejected / $free_cells, 4 ) : 0.0;
		$building_type_coverage_ratio = count( $inputs['buildings'] ) > 0 ? round( (int) $inputs['typed_buildings_count'] / count( $inputs['buildings'] ), 4 ) : 0.0;
		$encode_started = microtime( true );
		$encoded_probe = wp_json_encode( [ 'type' => 'FeatureCollection', 'features' => $territories ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$geojson_encode_ms = self::elapsed_ms( $encode_started );
		$stats = [
			'method' => self::METHOD,
			'calculation_scope' => 'city_sequential',
			'source_preset' => (string) $config['preset'],
			'osm_features_count' => count( $features ),
			'buildings' => count( $inputs['buildings'] ),
			'buildings_count' => count( $inputs['buildings'] ),
			'target_buildings_count' => $total_targets,
			'processed_buildings_count' => $processed_targets,
			'chunks_count' => $total_chunks,
			'chunk_size_m' => $chunk_size_m,
			'skipped_buildings_without_cells' => $skipped_no_cells,
			'residential_buildings_count' => (int) $inputs['residential_buildings_count'],
			'building_type_coverage_ratio' => $building_type_coverage_ratio,
			'roads_count' => count( $inputs['roads'] ) + count( $inputs['soft_roads'] ),
			'hard_barriers_count' => count( $inputs['roads'] ) + count( $inputs['railways'] ) + count( $inputs['barriers'] ),
			'soft_barriers_count' => count( $inputs['soft_roads'] ) + count( $inputs['soft_areas'] ),
			'water_count' => count( $inputs['waters'] ),
			'railway_count' => count( $inputs['railways'] ),
			'polygon_obstacles_count' => count( $inputs['areas'] ),
			'grid_cells_total' => $grid_cells_total,
			'grid_cells_free' => $free_cells,
			'grid_cells_assigned' => $assigned_cells,
			'grid_cells_rejected_los' => $los_rejected,
			'grid_cells_unassigned' => max( 0, $free_cells - $assigned_cells ),
			'generated_polygons_count' => count( $territories ),
			'holes_removed_count' => self::$last_holes_removed,
			'cell_size_m' => (float) $config['cell_size_m'],
			'max_distance_m' => (float) $config['max_distance_m'],
			'distance_median' => self::median( $distances ),
			'median_distance_m' => self::median( $distances ),
			'p95_distance_m' => self::percentile( $distances, 0.95 ),
			'max_assigned_distance_m' => empty( $distances ) ? null : round( max( $distances ), 2 ),
			'assigned_ratio' => $assigned_ratio,
			'rejected_los_ratio' => $rejected_los_ratio,
			'osm_quality_level' => self::osm_quality_level( $inputs, $assigned_ratio, $rejected_los_ratio, $building_type_coverage_ratio ),
			'city_bbox' => $city_bbox,
			'total_job_ms' => self::elapsed_ms( $job_started ),
			'fetch_osm_ms' => $fetch_osm_ms,
			'classify_ms' => $classify_ms,
			'grid_build_ms' => round( $grid_build_ms, 2 ),
			'nearest_assignment_ms' => round( $nearest_assignment_ms, 2 ),
			'line_of_sight_ms' => round( $line_of_sight_ms, 2 ),
			'polygonize_ms' => round( $polygonize_ms, 2 ),
			'smoothing_ms' => round( self::$last_smoothing_ms, 2 ),
			'geojson_encode_ms' => $geojson_encode_ms,
			'geojson_size_kb' => is_string( $encoded_probe ) ? round( strlen( $encoded_probe ) / 1024, 1 ) : null,
			'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 2 ),
			'php_memory_limit' => ini_get( 'memory_limit' ),
			'warnings' => $warnings,
		];

		self::trace_event( $events, 'city_sequential_done', [ 'territories' => count( $territories ), 'stats' => $stats ] );

		return [
			'type'     => 'FeatureCollection',
			'features' => $territories,
			'stats'    => $stats,
			'debug'    => [
				'stats' => $stats,
				'events' => $events,
			],
		];
	}

	private static function compute_python_worker( int $city_id, array $config, callable $progress ): array {
		$started = microtime( true );
		$progress( 'python_export', 0, 1, 'Exporting city OSM for Python worker' );
		if ( ! class_exists( 'WSCOSM_Feature_Store' ) ) {
			throw new RuntimeException( 'WSCOSM_Feature_Store is not available.' );
		}

		$fc = WSCOSM_Feature_Store::get_feature_collection_for_city( $city_id, 100000 );
		$features = is_array( $fc['features'] ?? null ) ? $fc['features'] : [];
		if ( empty( $features ) ) {
			throw new RuntimeException( 'No OSM objects stored for this city.' );
		}

		$dir = self::worker_storage_dir();
		$input = $dir . '/territory-input-city-' . $city_id . '-' . wp_generate_password( 8, false, false ) . '.geojson';
		$output = $dir . '/territory-output-city-' . $city_id . '-' . wp_generate_password( 8, false, false ) . '.geojson';
		$json = wp_json_encode( $fc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) || file_put_contents( $input, $json ) === false ) {
			throw new RuntimeException( 'Failed to write Python worker input GeoJSON.' );
		}

		$python = self::python_command();
		$script = self::python_worker_path();
		$cmd = self::build_python_worker_command( $python, $script, $input, $output, $config );
		$progress( 'python_worker', 0, max( 1, count( $features ) ), 'Starting Python/Shapely territory worker' );

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];
		$process = proc_open( $cmd, $descriptors, $pipes, defined( 'WSCOSM_DIR' ) ? WSCOSM_DIR : null );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Failed to start Python worker.' );
		}
		fclose( $pipes[0] );
		$stderr = '';
		$last_message = '';
		while ( ! feof( $pipes[1] ) ) {
			$line = fgets( $pipes[1] );
			if ( ! is_string( $line ) ) {
				break;
			}
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$last_message = $line;
			if ( preg_match( '/processed buildings\s+(\d+)\/(\d+)/i', $line, $m ) ) {
				$progress( 'python_worker', (int) $m[1], (int) $m[2], $line );
			} else {
				$progress( 'python_worker', 0, max( 1, count( $features ) ), $line );
			}
		}
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		if ( $exit_code !== 0 ) {
			$message = trim( (string) $stderr );
			if ( $message === '' ) {
				$message = $last_message !== '' ? $last_message : 'Python worker failed.';
			}
			throw new RuntimeException( $message );
		}
		if ( ! is_file( $output ) ) {
			throw new RuntimeException( 'Python worker did not create output GeoJSON.' );
		}
		$result = json_decode( (string) file_get_contents( $output ), true );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Python worker output is not valid JSON.' );
		}
		if ( ! isset( $result['stats'] ) || ! is_array( $result['stats'] ) ) {
			$result['stats'] = [];
		}
		$result['stats']['calculation_scope'] = 'python_worker';
		$result['stats']['input_geojson'] = basename( $input );
		$result['stats']['output_geojson'] = basename( $output );
		$result['stats']['total_job_ms'] = self::elapsed_ms( $started );
		$result['stats']['memory_peak_mb'] = round( memory_get_peak_usage( true ) / 1048576, 2 );
		$result['debug'] = [
			'stats' => $result['stats'],
			'events' => [
				[
					't' => gmdate( 'c' ),
					'event' => 'python_worker_done',
					'data' => $result['stats'],
				],
			],
		];
		$progress( 'python_worker', count( $result['features'] ?? [] ), count( $result['features'] ?? [] ), 'Python worker done' );
		return $result;
	}

	private static function collect_inputs( array $features, float $origin_lat, array $config ): array {
		$inputs = [
			'buildings'                   => [],
			'roads'                       => [],
			'soft_roads'                  => [],
			'railways'                    => [],
			'waters'                      => [],
			'barriers'                    => [],
			'areas'                       => [],
			'soft_areas'                  => [],
			'residential_buildings_count' => 0,
			'typed_buildings_count'       => 0,
		];
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}
			$geom  = is_array( $feature['geometry'] ?? null ) ? $feature['geometry'] : null;
			$props = is_array( $feature['properties'] ?? null ) ? $feature['properties'] : [];
			if ( ! $geom ) {
				continue;
			}
			$kind = (string) ( $props['wscosm_kind'] ?? '' );
			if ( strpos( $kind, 'bldg_' ) === 0 && $kind !== 'bldg_part' ) {
				$polygons = self::projected_polygons( $geom, $origin_lat );
				if ( empty( $polygons ) ) {
					continue;
				}
				$bounds = self::projected_bounds( $polygons );
				$key = self::building_key( $props, $geom );
				$building_kind = (string) ( $props['wscosm_kind'] ?? '' );
				if ( $building_kind === 'bldg_residential' ) {
					++$inputs['residential_buildings_count'];
				}
				if ( ! in_array( $building_kind, [ 'bldg_yes', 'bldg_other' ], true ) ) {
					++$inputs['typed_buildings_count'];
				}
				$inputs['buildings'][] = [
					'key'      => $key,
					'props'    => $props,
					'polygons' => $polygons,
					'segments' => self::projected_segments( $geom, $origin_lat ),
					'bounds'   => $bounds,
					'center'   => [ 'x' => ( $bounds['w'] + $bounds['e'] ) / 2.0, 'y' => ( $bounds['s'] + $bounds['n'] ) / 2.0 ],
				];
				continue;
			}
			$road_class = self::road_barrier_class( $props, $kind, $config );
			if ( $road_class['class'] === 'hard' ) {
				$segments = self::projected_segments( $geom, $origin_lat );
				$inputs['roads'][] = [ 'buffer' => (float) $road_class['buffer'], 'segments' => $segments, 'bounds' => self::segments_bounds( $segments, (float) $road_class['buffer'] ), 'feature' => $feature ];
				continue;
			}
			if ( $road_class['class'] === 'soft' ) {
				$inputs['soft_roads'][] = [ 'buffer' => (float) $road_class['buffer'], 'segments' => self::projected_segments( $geom, $origin_lat ), 'feature' => $feature ];
				continue;
			}
			if ( $kind === 'railway' || self::prop_tag( $props, 'railway' ) !== '' ) {
				$segments = self::projected_segments( $geom, $origin_lat );
				$inputs['railways'][] = [ 'buffer' => 10.0, 'segments' => $segments, 'bounds' => self::segments_bounds( $segments, 10.0 ), 'feature' => $feature ];
				continue;
			}
			if ( $kind === 'water' || self::prop_tag( $props, 'natural' ) === 'water' || self::prop_tag( $props, 'waterway' ) !== '' ) {
				$segments = self::projected_segments( $geom, $origin_lat );
				$polygons = self::projected_polygons( $geom, $origin_lat );
				$inputs['waters'][] = [
					'buffer'   => in_array( (string) ( $geom['type'] ?? '' ), [ 'LineString', 'MultiLineString' ], true ) ? 8.0 : 0.0,
					'segments' => $segments,
					'polygons' => $polygons,
					'bounds'   => ! empty( $polygons ) ? self::projected_bounds( $polygons ) : self::segments_bounds( $segments, 8.0 ),
					'feature'  => $feature,
				];
				continue;
			}
			if ( $kind === 'barrier' || preg_match( '/^(fence|wall|retaining_wall)$/', self::prop_tag( $props, 'barrier' ) ) ) {
				$segments = self::projected_segments( $geom, $origin_lat );
				$inputs['barriers'][] = [ 'buffer' => 3.0, 'segments' => $segments, 'bounds' => self::segments_bounds( $segments, 3.0 ), 'feature' => $feature ];
				continue;
			}
			if ( self::is_hard_area_obstacle( $props, $kind, $geom, $origin_lat ) ) {
				$polygons = self::projected_polygons( $geom, $origin_lat );
				$inputs['areas'][] = [ 'polygons' => $polygons, 'bounds' => self::projected_bounds( $polygons ), 'feature' => $feature ];
				continue;
			}
			if ( self::is_soft_barrier( $props, $kind ) ) {
				$inputs['soft_areas'][] = [ 'polygons' => self::projected_polygons( $geom, $origin_lat ), 'feature' => $feature ];
			}
		}
		return $inputs;
	}

	private static function build_grid( array $bbox, float $origin_lat, array $inputs, array $config, ?callable $progress = null ): array {
		$sw = self::project_point( (float) $bbox['w'], (float) $bbox['s'], $origin_lat );
		$ne = self::project_point( (float) $bbox['e'], (float) $bbox['n'], $origin_lat );
		$width = max( 1.0, $ne['x'] - $sw['x'] );
		$height = max( 1.0, $ne['y'] - $sw['y'] );
		$requested = max( 1.0, (float) $config['cell_size_m'] );
		$cell = max( $requested, sqrt( ( $width * $height ) / max( 1, (int) $config['max_grid_cells'] ) ) );
		$nx = max( 1, (int) ceil( $width / $cell ) );
		$ny = max( 1, (int) ceil( $height / $cell ) );
		$total = $nx * $ny;
		$tick_every = max( 500, min( 1000, (int) floor( $total / 160 ) ) );
		$free = [];
		$free_count = 0;
		for ( $iy = 0; $iy < $ny; $iy++ ) {
			for ( $ix = 0; $ix < $nx; $ix++ ) {
				$idx = $iy * $nx + $ix;
				$p = [ 'x' => $sw['x'] + ( $ix + 0.5 ) * $cell, 'y' => $sw['y'] + ( $iy + 0.5 ) * $cell ];
				$is_free = ! self::is_blocked_point( $p, $inputs );
				$free[ $idx ] = $is_free;
				if ( $is_free ) {
					++$free_count;
				}
				if ( $progress && ( $idx % $tick_every === 0 || $idx + 1 === $total ) ) {
					$progress(
						'grid',
						$idx + 1,
						$total,
						sprintf(
							'Building free grid (%d/%d cells, %d free, %.2fm cell)',
							$idx + 1,
							$total,
							$free_count,
							$cell
						)
					);
				}
			}
		}
		return [
			'min_x'      => $sw['x'],
			'min_y'      => $sw['y'],
			'cell'       => $cell,
			'nx'         => $nx,
			'ny'         => $ny,
			'free'       => $free,
			'free_count' => $free_count,
		];
	}

	private static function allocate_cells( array $grid, array $inputs, array $config, callable $progress ): array {
		$buildings = $inputs['buildings'];
		$nx = (int) $grid['nx'];
		$ny = (int) $grid['ny'];
		$cell = (float) $grid['cell'];
		$total = $nx * $ny;
		$max_distance = (float) $config['max_distance_m'];
		$dist = array_fill( 0, $total, INF );
		$owner = array_fill( 0, $total, '' );
		$q = new SplPriorityQueue();
		$q->setExtractFlags( SplPriorityQueue::EXTR_DATA );
		$seed_distance = max( $cell * 1.6, 8.0 );
		foreach ( $buildings as $building ) {
			$bounds = $building['bounds'];
			$min_ix = max( 0, (int) floor( ( $bounds['w'] - $seed_distance - $grid['min_x'] ) / $cell ) );
			$max_ix = min( $nx - 1, (int) ceil( ( $bounds['e'] + $seed_distance - $grid['min_x'] ) / $cell ) );
			$min_iy = max( 0, (int) floor( ( $bounds['s'] - $seed_distance - $grid['min_y'] ) / $cell ) );
			$max_iy = min( $ny - 1, (int) ceil( ( $bounds['n'] + $seed_distance - $grid['min_y'] ) / $cell ) );
			for ( $iy = $min_iy; $iy <= $max_iy; $iy++ ) {
				for ( $ix = $min_ix; $ix <= $max_ix; $ix++ ) {
					$idx = $iy * $nx + $ix;
					if ( empty( $grid['free'][ $idx ] ) ) {
						continue;
					}
					$p = self::grid_center( $grid, $ix, $iy );
					$d = self::distance_to_building( $p, $building );
					if ( $d <= $seed_distance && $d < $dist[ $idx ] ) {
						$dist[ $idx ] = $d;
						$owner[ $idx ] = $building['key'];
						$q->insert( [ 'idx' => $idx, 'dist' => $d, 'owner' => $building['key'] ], -$d );
					}
				}
			}
		}
		$dirs = [
			[ 1, 0, 1.0 ], [ -1, 0, 1.0 ], [ 0, 1, 1.0 ], [ 0, -1, 1.0 ],
			[ 1, 1, 1.41421356237 ], [ 1, -1, 1.41421356237 ], [ -1, 1, 1.41421356237 ], [ -1, -1, 1.41421356237 ],
		];
		$visited = 0;
		while ( ! $q->isEmpty() ) {
			$item = $q->extract();
			$idx = (int) $item['idx'];
			if ( abs( (float) $item['dist'] - $dist[ $idx ] ) > 0.0001 || $item['owner'] !== $owner[ $idx ] ) {
				continue;
			}
			++$visited;
			if ( $visited % 500 === 0 ) {
				$progress( 'allocation', $visited, (int) $grid['free_count'], 'Allocating cells' );
			}
			$ix = $idx % $nx;
			$iy = intdiv( $idx, $nx );
			foreach ( $dirs as $dir ) {
				$x = $ix + $dir[0];
				$y = $iy + $dir[1];
				if ( $x < 0 || $x >= $nx || $y < 0 || $y >= $ny ) {
					continue;
				}
				$nidx = $y * $nx + $x;
				if ( empty( $grid['free'][ $nidx ] ) ) {
					continue;
				}
				$nd = $dist[ $idx ] + $cell * $dir[2];
				if ( $nd > $max_distance ) {
					continue;
				}
				if ( $nd + 0.0001 < $dist[ $nidx ] || ( abs( $nd - $dist[ $nidx ] ) <= 0.0001 && strcmp( $owner[ $idx ], $owner[ $nidx ] ) < 0 ) ) {
					$dist[ $nidx ] = $nd;
					$owner[ $nidx ] = $owner[ $idx ];
					$q->insert( [ 'idx' => $nidx, 'dist' => $nd, 'owner' => $owner[ $idx ] ], -$nd );
				}
			}
		}
		$by_building = [];
		$meta = [];
		$building_map = [];
		foreach ( $buildings as $building ) {
			$building_map[ $building['key'] ] = $building;
		}
		$assigned = 0;
		$line_of_sight_rejected = 0;
		$line_of_sight_ms = 0.0;
		$distances = [];
		for ( $idx = 0; $idx < $total; $idx++ ) {
			if ( $owner[ $idx ] === '' ) {
				continue;
			}
			$owner_key = $owner[ $idx ];
			$building = $building_map[ $owner_key ] ?? null;
			if ( $building && ! empty( $config['use_line_of_sight'] ) ) {
				$p = self::grid_center( $grid, $idx % $nx, intdiv( $idx, $nx ) );
				$los_started = microtime( true );
				$blocked_by = self::path_blocked_by( $p, $building, $inputs );
				$line_of_sight_ms += self::elapsed_ms( $los_started );
				if ( $blocked_by !== '' ) {
					++$line_of_sight_rejected;
					if ( ! isset( $meta[ $owner_key ] ) ) {
						$meta[ $owner_key ] = [ 'distances' => [], 'blocked_by' => [] ];
					}
					$meta[ $owner_key ]['blocked_by'][ $blocked_by ] = true;
					continue;
				}
			}
			++$assigned;
			if ( ! isset( $by_building[ $owner_key ] ) ) {
				$by_building[ $owner_key ] = [];
			}
			if ( ! isset( $meta[ $owner_key ] ) ) {
				$meta[ $owner_key ] = [ 'distances' => [], 'blocked_by' => [] ];
			}
			$cell_distance = is_finite( (float) $dist[ $idx ] ) ? round( (float) $dist[ $idx ], 2 ) : null;
			if ( $cell_distance !== null ) {
				$meta[ $owner_key ]['distances'][] = $cell_distance;
				$distances[] = $cell_distance;
			}
			$by_building[ $owner_key ][] = [ $idx % $nx, intdiv( $idx, $nx ) ];
		}
		return [
			'by_building'            => $by_building,
			'assigned_count'         => $assigned,
			'line_of_sight_rejected' => $line_of_sight_rejected,
			'line_of_sight_ms'       => round( $line_of_sight_ms, 2 ),
			'distances'              => $distances,
			'meta'                   => $meta,
		];
	}

	private static function polygonize( array $by_building, array $grid, float $origin_lat, array $config, array $buildings, array $inputs, array $meta ): array {
		$out = [];
		$idx = 0;
		$building_map = [];
		foreach ( $buildings as $building ) {
			$building_map[ $building['key'] ] = $building;
		}
		foreach ( $by_building as $building_key => $cells ) {
			$building = $building_map[ $building_key ] ?? null;
			$building_props = is_array( $building['props'] ?? null ) ? $building['props'] : [];
			$center_ll = $building ? self::lon_lat_point( (float) $building['center']['x'], (float) $building['center']['y'], $origin_lat ) : [ 0, 0 ];
			$building_meta = is_array( $meta[ $building_key ] ?? null ) ? $meta[ $building_key ] : [];
			$blocked_by = array_keys( is_array( $building_meta['blocked_by'] ?? null ) ? $building_meta['blocked_by'] : [] );
			$quality_flags = [];
			if ( ! empty( $blocked_by ) ) {
				$quality_flags[] = 'line_of_sight_trimmed';
			}
			foreach ( self::split_components( $cells ) as $component_index => $component ) {
				$geom = self::cells_to_polygon( $component, $grid, $origin_lat, $config, $inputs );
				if ( ! $geom ) {
					continue;
				}
				$area = round( count( $component ) * $grid['cell'] * $grid['cell'], 1 );
				if ( $area < (float) $config['min_area_m2'] ) {
					continue;
				}
				$id = 'territory_' . substr( md5( $building_key . ':' . $component_index . ':' . $area ), 0, 12 );
				$out[] = [
					'type'       => 'Feature',
					'properties' => [
						'id'             => $id,
						'object_key'     => $id,
						'building_id'    => $building_key,
						'osm_id'         => (string) ( $building_props['wscosm_osm_id'] ?? '' ),
						'wscosm_kind'    => (string) ( $building_props['wscosm_kind'] ?? 'bldg_other' ),
						'wscosm_osm_el_type' => (string) ( $building_props['wscosm_osm_el_type'] ?? '' ),
						'wscosm_osm_id'  => (int) ( $building_props['wscosm_osm_id'] ?? 0 ),
						'area_m2'        => $area,
						'method'         => self::METHOD,
						'source_preset'  => (string) $config['preset'],
						'cell_size_m'    => round( (float) $grid['cell'], 2 ),
						'max_distance_m' => (float) $config['max_distance_m'],
						'distance_median'=> self::median( is_array( $building_meta['distances'] ?? null ) ? $building_meta['distances'] : [] ),
						'quality_flags'  => $quality_flags,
						'blocked_by'     => $blocked_by,
						'created_at'     => gmdate( 'c' ),
						'name'           => (string) ( $building_props['name'] ?? '' ),
						'title'          => (string) ( $building_props['name'] ?? $building_key ),
						'center'         => [ 'lat' => $center_ll[1], 'lng' => $center_ll[0] ],
						'lat'            => $center_ll[1],
						'lng'            => $center_ll[0],
					],
					'geometry'   => $geom,
				];
				++$idx;
			}
		}
		return $out;
	}

	private static function split_components( array $cells ): array {
		$set = [];
		foreach ( $cells as $cell ) {
			$set[ $cell[0] . ',' . $cell[1] ] = $cell;
		}
		$visited = [];
		$out = [];
		foreach ( $cells as $cell ) {
			$key = $cell[0] . ',' . $cell[1];
			if ( isset( $visited[ $key ] ) ) {
				continue;
			}
			$q = [ $cell ];
			$visited[ $key ] = true;
			$component = [];
			while ( $q ) {
				$cur = array_pop( $q );
				$component[] = $cur;
				foreach ( [ [ 1, 0 ], [ -1, 0 ], [ 0, 1 ], [ 0, -1 ] ] as $d ) {
					$nk = ( $cur[0] + $d[0] ) . ',' . ( $cur[1] + $d[1] );
					if ( isset( $set[ $nk ] ) && ! isset( $visited[ $nk ] ) ) {
						$visited[ $nk ] = true;
						$q[] = $set[ $nk ];
					}
				}
			}
			$out[] = $component;
		}
		return $out;
	}

	private static function cells_to_polygon( array $cells, array $grid, float $origin_lat, array $config, array $inputs ): ?array {
		$set = [];
		foreach ( $cells as $cell ) {
			$set[ $cell[0] . ',' . $cell[1] ] = true;
		}
		$edges = [];
		$add = static function ( array $a, array $b ) use ( &$edges ): void {
			$k = $a[0] . ',' . $a[1];
			$edges[ $k ][] = [ 'a' => $a, 'b' => $b, 'used' => false ];
		};
		foreach ( $cells as $cell ) {
			$ix = $cell[0];
			$iy = $cell[1];
			if ( ! isset( $set[ $ix . ',' . ( $iy + 1 ) ] ) ) {
				$add( [ $ix, $iy + 1 ], [ $ix + 1, $iy + 1 ] );
			}
			if ( ! isset( $set[ ( $ix + 1 ) . ',' . $iy ] ) ) {
				$add( [ $ix + 1, $iy + 1 ], [ $ix + 1, $iy ] );
			}
			if ( ! isset( $set[ $ix . ',' . ( $iy - 1 ) ] ) ) {
				$add( [ $ix + 1, $iy ], [ $ix, $iy ] );
			}
			if ( ! isset( $set[ ( $ix - 1 ) . ',' . $iy ] ) ) {
				$add( [ $ix, $iy ], [ $ix, $iy + 1 ] );
			}
		}
		$rings = [];
		foreach ( array_keys( $edges ) as $start_key ) {
			foreach ( $edges[ $start_key ] as $edge_index => $edge ) {
				if ( $edges[ $start_key ][ $edge_index ]['used'] ) {
					continue;
				}
				$ring = [ $edge['a'] ];
				$cur_key = $start_key;
				$cur_index = $edge_index;
				$guard = 0;
				while ( isset( $edges[ $cur_key ][ $cur_index ] ) && ! $edges[ $cur_key ][ $cur_index ]['used'] && $guard++ < 100000 ) {
					$edges[ $cur_key ][ $cur_index ]['used'] = true;
					$b = $edges[ $cur_key ][ $cur_index ]['b'];
					$ring[] = $b;
					$next_key = $b[0] . ',' . $b[1];
					$cur_key = $next_key;
					$cur_index = -1;
					foreach ( $edges[ $next_key ] ?? [] as $i => $next ) {
						if ( ! $next['used'] ) {
							$cur_index = $i;
							break;
						}
					}
					if ( count( $ring ) > 3 && $ring[0][0] === $b[0] && $ring[0][1] === $b[1] ) {
						break;
					}
					if ( $cur_index < 0 ) {
						break;
					}
				}
				$last = $ring[ count( $ring ) - 1 ];
				if ( count( $ring ) >= 4 && $ring[0][0] === $last[0] && $ring[0][1] === $last[1] ) {
					$rings[] = $ring;
				}
			}
		}
		if ( empty( $rings ) ) {
			return null;
		}
		usort(
			$rings,
			static function ( array $a, array $b ): int {
				return abs( self::grid_ring_area( $b ) ) <=> abs( self::grid_ring_area( $a ) );
			}
		);
		$coords = [];
		foreach ( $rings as $ring_index => $ring ) {
			if ( $ring_index > 0 ) {
				$hole_area = abs( self::grid_ring_area( $ring ) ) * (float) $grid['cell'] * (float) $grid['cell'];
				if ( $hole_area < (float) $config['min_hole_area_m2'] ) {
					++self::$last_holes_removed;
					continue;
				}
			}
			$simp = self::simplify_collinear_ring_grid( $ring );
			if ( count( $simp ) < 4 ) {
				$simp = $ring;
			}
			$simp = self::smooth_ring_grid( $simp, $grid, $config, $inputs );
			$coords[] = array_map(
				static function ( array $v ) use ( $grid, $origin_lat ): array {
					$x = $grid['min_x'] + $v[0] * $grid['cell'];
					$y = $grid['min_y'] + $v[1] * $grid['cell'];
					return self::lon_lat_point( $x, $y, $origin_lat );
				},
				$simp
			);
		}
		if ( empty( $coords ) ) {
			return null;
		}
		$coords = self::simplify_raster_polygon_coordinates( $coords );
		return [ 'type' => 'Polygon', 'coordinates' => $coords ];
	}

	/**
	 * Remove collinear vertices along raster outlines (aligned with JS simplifyPolygonCoordinates).
	 *
	 * @param array<int, array<int, array{0:float,1:float}>> $coordinates GeoJSON Polygon rings [lng, lat].
	 */
	private static function simplify_raster_polygon_coordinates( array $coordinates ): array {
		$out = [];
		foreach ( $coordinates as $ring ) {
			if ( ! is_array( $ring ) ) {
				continue;
			}
			$out[] = self::simplify_closed_geojson_ring_ll( $ring );
		}
		return $out;
	}

	/**
	 * @param array<int, array{0:float,1:float}> $ring Closed ring [lng, lat].
	 * @return array<int, array{0:float,1:float}>
	 */
	private static function simplify_closed_geojson_ring_ll( array $ring ): array {
		$n = count( $ring );
		if ( $n <= 4 ) {
			return $ring;
		}
		$mod       = $n - 1;
		$simplified = [];
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$prev = $ring[ ( $i + $n - 2 ) % $mod ];
			$cur  = $ring[ $i ];
			$next = $ring[ ( $i + 1 ) % $mod ];
			$area2 = ( $prev[0] - $cur[0] ) * ( $next[1] - $cur[1] )
				- ( $prev[1] - $cur[1] ) * ( $next[0] - $cur[0] );
			if ( abs( $area2 ) > 1e-14 ) {
				$simplified[] = $cur;
			}
		}
		if ( count( $simplified ) < 3 ) {
			return $ring;
		}
		$f = $simplified[0];
		$l = $simplified[ count( $simplified ) - 1 ];
		if ( ( abs( $f[0] - $l[0] ) > 1e-12 ) || ( abs( $f[1] - $l[1] ) > 1e-12 ) ) {
			$simplified[] = $f;
		}
		return $simplified;
	}

	private static function grid_ring_area( array $ring ): float {
		$sum = 0.0;
		for ( $i = 0; $i < count( $ring ) - 1; $i++ ) {
			$sum += $ring[ $i ][0] * $ring[ $i + 1 ][1] - $ring[ $i + 1 ][0] * $ring[ $i ][1];
		}
		return $sum / 2.0;
	}

	/**
	 * Drop collinear corners on cell-grid rings (axis-aligned steps) before projecting to lon/lat.
	 *
	 * @param array<int, array{0:int|float,1:int|float}> $ring Closed ring in grid corner indices.
	 * @return array<int, array{0:int|float,1:int|float}>
	 */
	private static function simplify_collinear_ring_grid( array $ring ): array {
		$n = count( $ring );
		if ( $n <= 4 ) {
			return $ring;
		}
		$closed = ( count( $ring ) > 1 && $ring[0][0] === $ring[ $n - 1 ][0] && $ring[0][1] === $ring[ $n - 1 ][1] );
		$pts    = $closed ? array_slice( $ring, 0, -1 ) : $ring;
		$m      = count( $pts );
		if ( $m < 3 ) {
			return $ring;
		}
		$simplified = [];
		for ( $i = 0; $i < $m; $i++ ) {
			$prev = $pts[ ( $i + $m - 1 ) % $m ];
			$cur  = $pts[ $i ];
			$next = $pts[ ( $i + 1 ) % $m ];
			$cross = ( ( $prev[0] - $cur[0] ) * ( $next[1] - $cur[1] ) )
				- ( ( $prev[1] - $cur[1] ) * ( $next[0] - $cur[0] ) );
			if ( 0 !== $cross ) {
				$simplified[] = $cur;
			}
		}
		if ( count( $simplified ) < 3 ) {
			return $ring;
		}
		if ( $closed ) {
			$simplified[] = $simplified[0];
		}
		return $simplified;
	}

	private static function smooth_ring_grid( array $ring, array $grid, array $config, array $inputs ): array {
		$started = microtime( true );
		$tolerance_m = (float) ( $config['smooth_tolerance_m'] ?? 0.0 );
		if ( $tolerance_m <= 0.0 || count( $ring ) <= 8 ) {
			return $ring;
		}
		$closed = count( $ring ) > 1 && $ring[0][0] === $ring[ count( $ring ) - 1 ][0] && $ring[0][1] === $ring[ count( $ring ) - 1 ][1];
		if ( ! $closed ) {
			return $ring;
		}
		$pts = array_slice( $ring, 0, -1 );
		$tol_grid = min( 0.5, $tolerance_m / max( 0.1, (float) $grid['cell'] ) );
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$count = count( $pts );
			if ( $count <= 4 ) {
				break;
			}
			$next = [];
			for ( $i = 0; $i < $count; $i++ ) {
				$prev = $pts[ ( $i + $count - 1 ) % $count ];
				$cur  = $pts[ $i ];
				$after = $pts[ ( $i + 1 ) % $count ];
				$d = self::distance_to_grid_segment( $cur, $prev, $after );
				if ( $d <= $tol_grid && self::grid_segment_is_clear( $prev, $after, $grid, $inputs ) ) {
					continue;
				}
				$next[] = $cur;
			}
			if ( count( $next ) === $count || count( $next ) < 4 ) {
				break;
			}
			$pts = $next;
		}
		$pts[] = $pts[0];
		self::$last_smoothing_ms += self::elapsed_ms( $started );
		return $pts;
	}

	private static function distance_to_grid_segment( array $p, array $a, array $b ): float {
		return self::distance_to_segment(
			[ 'x' => (float) $p[0], 'y' => (float) $p[1] ],
			[ 'x' => (float) $a[0], 'y' => (float) $a[1] ],
			[ 'x' => (float) $b[0], 'y' => (float) $b[1] ]
		);
	}

	private static function grid_segment_is_clear( array $a, array $b, array $grid, array $inputs ): bool {
		$pa = [
			'x' => $grid['min_x'] + (float) $a[0] * (float) $grid['cell'],
			'y' => $grid['min_y'] + (float) $a[1] * (float) $grid['cell'],
		];
		$pb = [
			'x' => $grid['min_x'] + (float) $b[0] * (float) $grid['cell'],
			'y' => $grid['min_y'] + (float) $b[1] * (float) $grid['cell'],
		];
		return self::line_blocked_by( $pa, $pb, $inputs ) === '';
	}

	private static function is_blocked_point( array $p, array $inputs ): bool {
		foreach ( $inputs['buildings'] as $building ) {
			if ( ! self::item_may_touch_point( $building, $p ) ) {
				continue;
			}
			if ( self::point_in_polygons( $p, $building['polygons'] ) ) {
				return true;
			}
		}
		foreach ( [ 'roads', 'railways', 'barriers' ] as $key ) {
			if ( self::min_distance_to_buffered_lines( $p, $inputs[ $key ], true ) <= 0 ) {
				return true;
			}
		}
		foreach ( $inputs['waters'] as $water ) {
			if ( ! self::item_may_touch_point( $water, $p ) ) {
				continue;
			}
			if ( ! empty( $water['polygons'] ) && self::point_in_polygons( $p, $water['polygons'] ) ) {
				return true;
			}
			if ( (float) ( $water['buffer'] ?? 0 ) > 0 && self::min_distance_to_buffered_lines( $p, [ $water ], true ) <= 0 ) {
				return true;
			}
		}
		foreach ( $inputs['areas'] as $area ) {
			if ( ! self::item_may_touch_point( $area, $p ) ) {
				continue;
			}
			if ( self::point_in_polygons( $p, $area['polygons'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function min_distance_to_buffered_lines( array $p, array $items, bool $use_bounds = false ): float {
		$best = INF;
		foreach ( $items as $item ) {
			if ( $use_bounds && ! self::item_may_touch_point( $item, $p ) ) {
				continue;
			}
			foreach ( $item['segments'] ?? [] as $seg ) {
				$best = min( $best, self::distance_to_segment( $p, $seg['a'], $seg['b'] ) - (float) ( $item['buffer'] ?? 0 ) );
			}
		}
		return $best;
	}

	private static function path_blocked_by( array $p, array $building, array $inputs ): string {
		$target = is_array( $building['center'] ?? null ) ? $building['center'] : null;
		if ( ! $target ) {
			return '';
		}
		return self::line_blocked_by( $p, $target, $inputs );
	}

	private static function line_blocked_by( array $a, array $b, array $inputs ): string {
		foreach ( [ 'roads' => 'road', 'railways' => 'railway', 'barriers' => 'barrier' ] as $key => $label ) {
			if ( self::segment_distance_to_buffered_lines( $a, $b, $inputs[ $key ], true ) <= 0.0 ) {
				return $label;
			}
		}
		foreach ( $inputs['waters'] as $water ) {
			if ( ! self::item_may_touch_segment( $water, $a, $b ) ) {
				continue;
			}
			if ( ! empty( $water['polygons'] ) && self::line_hits_polygons( $a, $b, $water['polygons'] ) ) {
				return 'water';
			}
			if ( (float) ( $water['buffer'] ?? 0 ) > 0 && self::segment_distance_to_buffered_lines( $a, $b, [ $water ], true ) <= 0.0 ) {
				return 'water';
			}
		}
		foreach ( $inputs['areas'] as $area ) {
			if ( ! self::item_may_touch_segment( $area, $a, $b ) ) {
				continue;
			}
			if ( self::line_hits_polygons( $a, $b, $area['polygons'] ) ) {
				return 'area_obstacle';
			}
		}
		return '';
	}

	private static function segment_distance_to_buffered_lines( array $a, array $b, array $items, bool $use_bounds = false ): float {
		$best = INF;
		foreach ( $items as $item ) {
			if ( $use_bounds && ! self::item_may_touch_segment( $item, $a, $b ) ) {
				continue;
			}
			foreach ( $item['segments'] ?? [] as $seg ) {
				$best = min( $best, self::segment_to_segment_distance( $a, $b, $seg['a'], $seg['b'] ) - (float) ( $item['buffer'] ?? 0 ) );
			}
		}
		return $best;
	}

	private static function segment_to_segment_distance( array $a, array $b, array $c, array $d ): float {
		if ( self::segments_intersect( $a, $b, $c, $d ) ) {
			return 0.0;
		}
		return min(
			self::distance_to_segment( $a, $c, $d ),
			self::distance_to_segment( $b, $c, $d ),
			self::distance_to_segment( $c, $a, $b ),
			self::distance_to_segment( $d, $a, $b )
		);
	}

	private static function distance_to_building( array $p, array $building ): float {
		if ( self::point_in_polygons( $p, $building['polygons'] ) ) {
			return 0.0;
		}
		$best = INF;
		foreach ( $building['segments'] as $seg ) {
			$best = min( $best, self::distance_to_segment( $p, $seg['a'], $seg['b'] ) );
		}
		return $best;
	}

	private static function distance_to_segment( array $p, array $a, array $b ): float {
		$dx = $b['x'] - $a['x'];
		$dy = $b['y'] - $a['y'];
		$len2 = $dx * $dx + $dy * $dy;
		if ( $len2 <= 0 ) {
			return hypot( $p['x'] - $a['x'], $p['y'] - $a['y'] );
		}
		$t = ( ( $p['x'] - $a['x'] ) * $dx + ( $p['y'] - $a['y'] ) * $dy ) / $len2;
		$t = max( 0.0, min( 1.0, $t ) );
		return hypot( $p['x'] - ( $a['x'] + $t * $dx ), $p['y'] - ( $a['y'] + $t * $dy ) );
	}

	private static function point_in_polygons( array $p, array $polygons ): bool {
		foreach ( $polygons as $poly ) {
			if ( empty( $poly[0] ) || ! self::point_in_ring( $p, $poly[0] ) ) {
				continue;
			}
			$in_hole = false;
			for ( $i = 1; $i < count( $poly ); $i++ ) {
				if ( self::point_in_ring( $p, $poly[ $i ] ) ) {
					$in_hole = true;
					break;
				}
			}
			if ( ! $in_hole ) {
				return true;
			}
		}
		return false;
	}

	private static function point_in_ring( array $p, array $ring ): bool {
		$inside = false;
		$count = count( $ring );
		for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
			$a = $ring[ $i ];
			$b = $ring[ $j ];
			$crosses = ( $a['y'] > $p['y'] ) !== ( $b['y'] > $p['y'] );
			if ( $crosses && $p['x'] < ( ( $b['x'] - $a['x'] ) * ( $p['y'] - $a['y'] ) ) / ( $b['y'] - $a['y'] ?: 1e-12 ) + $a['x'] ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	private static function line_hits_polygons( array $a, array $b, array $polygons ): bool {
		foreach ( $polygons as $poly ) {
			if ( empty( $poly[0] ) ) {
				continue;
			}
			if ( self::point_in_polygons( $a, [ $poly ] ) || self::point_in_polygons( $b, [ $poly ] ) ) {
				return true;
			}
			foreach ( $poly as $ring ) {
				for ( $i = 1; $i < count( $ring ); $i++ ) {
					if ( self::segments_intersect( $a, $b, $ring[ $i - 1 ], $ring[ $i ] ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private static function segments_intersect( array $a, array $b, array $c, array $d ): bool {
		$o1 = self::orientation( $a, $b, $c );
		$o2 = self::orientation( $a, $b, $d );
		$o3 = self::orientation( $c, $d, $a );
		$o4 = self::orientation( $c, $d, $b );
		if ( $o1 !== $o2 && $o3 !== $o4 ) {
			return true;
		}
		return ( $o1 === 0 && self::point_on_segment( $c, $a, $b ) )
			|| ( $o2 === 0 && self::point_on_segment( $d, $a, $b ) )
			|| ( $o3 === 0 && self::point_on_segment( $a, $c, $d ) )
			|| ( $o4 === 0 && self::point_on_segment( $b, $c, $d ) );
	}

	private static function orientation( array $a, array $b, array $c ): int {
		$val = ( (float) $b['y'] - (float) $a['y'] ) * ( (float) $c['x'] - (float) $b['x'] )
			- ( (float) $b['x'] - (float) $a['x'] ) * ( (float) $c['y'] - (float) $b['y'] );
		if ( abs( $val ) < 1e-9 ) {
			return 0;
		}
		return $val > 0 ? 1 : 2;
	}

	private static function point_on_segment( array $p, array $a, array $b ): bool {
		return (float) $p['x'] <= max( (float) $a['x'], (float) $b['x'] ) + 1e-9
			&& (float) $p['x'] + 1e-9 >= min( (float) $a['x'], (float) $b['x'] )
			&& (float) $p['y'] <= max( (float) $a['y'], (float) $b['y'] ) + 1e-9
			&& (float) $p['y'] + 1e-9 >= min( (float) $a['y'], (float) $b['y'] );
	}

	private static function projected_polygons( array $geom, float $origin_lat ): array {
		$type = (string) ( $geom['type'] ?? '' );
		$coords = $geom['coordinates'] ?? [];
		$ring_project = static function ( array $ring ) use ( $origin_lat ): array {
			return array_map(
				static function ( array $c ) use ( $origin_lat ): array {
					return self::project_point( (float) $c[0], (float) $c[1], $origin_lat );
				},
				$ring
			);
		};
		if ( $type === 'Polygon' ) {
			return [ array_map( $ring_project, is_array( $coords ) ? $coords : [] ) ];
		}
		if ( $type === 'MultiPolygon' ) {
			return array_map(
				static function ( array $poly ) use ( $ring_project ): array {
					return array_map( $ring_project, $poly );
				},
				is_array( $coords ) ? $coords : []
			);
		}
		return [];
	}

	private static function projected_segments( array $geom, float $origin_lat ): array {
		$segments = [];
		$add_line = static function ( array $line ) use ( &$segments, $origin_lat ): void {
			for ( $i = 1; $i < count( $line ); $i++ ) {
				if ( ! is_array( $line[ $i - 1 ] ) || ! is_array( $line[ $i ] ) ) {
					continue;
				}
				$segments[] = [
					'a' => self::project_point( (float) $line[ $i - 1 ][0], (float) $line[ $i - 1 ][1], $origin_lat ),
					'b' => self::project_point( (float) $line[ $i ][0], (float) $line[ $i ][1], $origin_lat ),
				];
			}
		};
		$type = (string) ( $geom['type'] ?? '' );
		$coords = $geom['coordinates'] ?? [];
		if ( $type === 'LineString' ) {
			$add_line( is_array( $coords ) ? $coords : [] );
		} elseif ( $type === 'MultiLineString' || $type === 'Polygon' ) {
			foreach ( is_array( $coords ) ? $coords : [] as $line ) {
				if ( is_array( $line ) ) {
					$add_line( $line );
				}
			}
		} elseif ( $type === 'MultiPolygon' ) {
			foreach ( is_array( $coords ) ? $coords : [] as $poly ) {
				foreach ( is_array( $poly ) ? $poly : [] as $line ) {
					if ( is_array( $line ) ) {
						$add_line( $line );
					}
				}
			}
		}
		return $segments;
	}

	private static function projected_bounds( array $polygons ): array {
		$b = [ 'w' => INF, 's' => INF, 'e' => -INF, 'n' => -INF ];
		foreach ( $polygons as $poly ) {
			foreach ( $poly as $ring ) {
				foreach ( $ring as $p ) {
					$b['w'] = min( $b['w'], $p['x'] );
					$b['e'] = max( $b['e'], $p['x'] );
					$b['s'] = min( $b['s'], $p['y'] );
					$b['n'] = max( $b['n'], $p['y'] );
				}
			}
		}
		return $b;
	}

	private static function expand_projected_bounds( array $bounds, float $margin_m ): array {
		return [
			'w' => (float) $bounds['w'] - $margin_m,
			's' => (float) $bounds['s'] - $margin_m,
			'e' => (float) $bounds['e'] + $margin_m,
			'n' => (float) $bounds['n'] + $margin_m,
		];
	}

	private static function merge_projected_bounds( array $a, array $b ): array {
		return [
			'w' => min( (float) $a['w'], (float) $b['w'] ),
			's' => min( (float) $a['s'], (float) $b['s'] ),
			'e' => max( (float) $a['e'], (float) $b['e'] ),
			'n' => max( (float) $a['n'], (float) $b['n'] ),
		];
	}

	private static function building_chunks( array $targets, float $tile_size_m ): array {
		$tile_size_m = max( 100.0, $tile_size_m );
		$chunks = [];
		foreach ( $targets as $target ) {
			if ( ! is_array( $target['center'] ?? null ) ) {
				continue;
			}
			$tx = (int) floor( (float) $target['center']['x'] / $tile_size_m );
			$ty = (int) floor( (float) $target['center']['y'] / $tile_size_m );
			$key = $tx . ':' . $ty;
			if ( ! isset( $chunks[ $key ] ) ) {
				$chunks[ $key ] = [];
			}
			$chunks[ $key ][] = $target;
		}
		ksort( $chunks, SORT_NATURAL );
		return array_values( $chunks );
	}

	private static function bbox_from_projected_bounds( array $bounds, float $origin_lat ): array {
		$sw = self::lon_lat_point( (float) $bounds['w'], (float) $bounds['s'], $origin_lat );
		$ne = self::lon_lat_point( (float) $bounds['e'], (float) $bounds['n'], $origin_lat );
		return [
			's' => min( (float) $sw[1], (float) $ne[1] ),
			'w' => min( (float) $sw[0], (float) $ne[0] ),
			'n' => max( (float) $sw[1], (float) $ne[1] ),
			'e' => max( (float) $sw[0], (float) $ne[0] ),
		];
	}

	private static function slice_inputs_by_projected_bounds( array $inputs, array $bounds ): array {
		$out = $inputs;
		foreach ( [ 'buildings', 'roads', 'railways', 'waters', 'barriers', 'areas' ] as $key ) {
			$out[ $key ] = array_values(
				array_filter(
					$inputs[ $key ] ?? [],
					static function ( array $item ) use ( $bounds ): bool {
						$item_bounds = is_array( $item['bounds'] ?? null ) ? $item['bounds'] : null;
						return ! $item_bounds || self::bounds_intersect( $item_bounds, $bounds );
					}
				)
			);
		}
		return $out;
	}

	private static function feature_collection_bbox( array $features ): ?array {
		$bbox = [ 's' => INF, 'w' => INF, 'n' => -INF, 'e' => -INF ];
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature['geometry'] ?? null ) ) {
				continue;
			}
			self::walk_geojson_coordinates(
				$feature['geometry']['coordinates'] ?? [],
				static function ( array $coord ) use ( &$bbox ): void {
					if ( count( $coord ) < 2 || ! is_numeric( $coord[0] ) || ! is_numeric( $coord[1] ) ) {
						return;
					}
					$lon = (float) $coord[0];
					$lat = (float) $coord[1];
					$bbox['w'] = min( $bbox['w'], $lon );
					$bbox['e'] = max( $bbox['e'], $lon );
					$bbox['s'] = min( $bbox['s'], $lat );
					$bbox['n'] = max( $bbox['n'], $lat );
				}
			);
		}
		return is_finite( $bbox['s'] ) && is_finite( $bbox['w'] ) && is_finite( $bbox['n'] ) && is_finite( $bbox['e'] ) ? $bbox : null;
	}

	private static function walk_geojson_coordinates( $coords, callable $visit ): void {
		if ( ! is_array( $coords ) || empty( $coords ) ) {
			return;
		}
		if ( isset( $coords[0], $coords[1] ) && is_numeric( $coords[0] ) && is_numeric( $coords[1] ) ) {
			$visit( $coords );
			return;
		}
		foreach ( $coords as $child ) {
			self::walk_geojson_coordinates( $child, $visit );
		}
	}

	private static function empty_result( string $method, array $config, array $stats, array $events = [] ): array {
		$stats = array_merge(
			[
				'method' => $method,
				'source_preset' => (string) ( $config['preset'] ?? '' ),
				'generated_polygons_count' => 0,
			],
			$stats
		);
		return [
			'type'     => 'FeatureCollection',
			'features' => [],
			'stats'    => $stats,
			'debug'    => [
				'stats' => $stats,
				'events' => $events,
			],
		];
	}

	private static function worker_storage_dir(): string {
		$upload = wp_upload_dir();
		$base = is_array( $upload ) && ! empty( $upload['basedir'] ) ? (string) $upload['basedir'] : ( defined( 'WSCOSM_DIR' ) ? WSCOSM_DIR . 'storage' : sys_get_temp_dir() );
		$dir = trailingslashit( $base ) . 'wscosm-territory';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0777, true );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			$dir = defined( 'WSCOSM_DIR' ) ? WSCOSM_DIR . 'storage/wscosm-territory' : sys_get_temp_dir() . '/wscosm-territory';
			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0777, true );
			}
		}
		return rtrim( $dir, "/\\" );
	}

	private static function python_command(): string {
		return (string) apply_filters( 'wscosm_territory_python_command', 'python' );
	}

	private static function python_worker_path(): string {
		return defined( 'WSCOSM_DIR' ) ? WSCOSM_DIR . 'tools/territory_worker.py' : __DIR__ . '/../tools/territory_worker.py';
	}

	private static function build_python_worker_command( string $python, string $script, string $input, string $output, array $config ): string {
		$args = [
			$python,
			$script,
			'--input',
			$input,
			'--output',
			$output,
			'--max-distance',
			(string) (float) ( $config['max_distance_m'] ?? 35.0 ),
			'--min-area',
			(string) (float) ( $config['min_area_m2'] ?? 10.0 ),
		];
		return implode(
			' ',
			array_map(
				static function ( string $arg ): string {
					return escapeshellarg( $arg );
				},
				$args
			)
		);
	}

	private static function segments_bounds( array $segments, float $buffer = 0.0 ): ?array {
		$b = [ 'w' => INF, 's' => INF, 'e' => -INF, 'n' => -INF ];
		foreach ( $segments as $seg ) {
			foreach ( [ 'a', 'b' ] as $key ) {
				if ( ! isset( $seg[ $key ]['x'], $seg[ $key ]['y'] ) ) {
					continue;
				}
				$b['w'] = min( $b['w'], (float) $seg[ $key ]['x'] - $buffer );
				$b['e'] = max( $b['e'], (float) $seg[ $key ]['x'] + $buffer );
				$b['s'] = min( $b['s'], (float) $seg[ $key ]['y'] - $buffer );
				$b['n'] = max( $b['n'], (float) $seg[ $key ]['y'] + $buffer );
			}
		}
		return is_finite( $b['w'] ) && is_finite( $b['s'] ) && is_finite( $b['e'] ) && is_finite( $b['n'] ) ? $b : null;
	}

	private static function item_may_touch_segment( array $item, array $a, array $b ): bool {
		$bounds = is_array( $item['bounds'] ?? null ) ? $item['bounds'] : null;
		if ( ! $bounds ) {
			return true;
		}
		$buffer = (float) ( $item['buffer'] ?? 0.0 );
		$seg_bounds = [
			'w' => min( (float) $a['x'], (float) $b['x'] ) - $buffer,
			'e' => max( (float) $a['x'], (float) $b['x'] ) + $buffer,
			's' => min( (float) $a['y'], (float) $b['y'] ) - $buffer,
			'n' => max( (float) $a['y'], (float) $b['y'] ) + $buffer,
		];
		return self::bounds_intersect( $bounds, $seg_bounds );
	}

	private static function item_may_touch_point( array $item, array $p ): bool {
		$bounds = is_array( $item['bounds'] ?? null ) ? $item['bounds'] : null;
		if ( ! $bounds ) {
			return true;
		}
		$buffer = (float) ( $item['buffer'] ?? 0.0 );
		return (float) $p['x'] + $buffer >= (float) $bounds['w']
			&& (float) $p['x'] - $buffer <= (float) $bounds['e']
			&& (float) $p['y'] + $buffer >= (float) $bounds['s']
			&& (float) $p['y'] - $buffer <= (float) $bounds['n'];
	}

	private static function bounds_intersect( array $a, array $b ): bool {
		return (float) $a['e'] >= (float) $b['w']
			&& (float) $a['w'] <= (float) $b['e']
			&& (float) $a['n'] >= (float) $b['s']
			&& (float) $a['s'] <= (float) $b['n'];
	}

	private static function grid_center( array $grid, int $ix, int $iy ): array {
		return [
			'x' => $grid['min_x'] + ( $ix + 0.5 ) * $grid['cell'],
			'y' => $grid['min_y'] + ( $iy + 0.5 ) * $grid['cell'],
		];
	}

	private static function project_point( float $lon, float $lat, float $origin_lat ): array {
		$cos = cos( $origin_lat * M_PI / 180.0 );
		$cos = max( 0.15, min( 1.0, abs( $cos ) ) );
		return [ 'x' => $lon * 111320.0 * $cos, 'y' => $lat * 111320.0 ];
	}

	private static function lon_lat_point( float $x, float $y, float $origin_lat ): array {
		$cos = cos( $origin_lat * M_PI / 180.0 );
		$cos = max( 0.15, min( 1.0, abs( $cos ) ) );
		return [ round( $x / ( 111320.0 * $cos ), 7 ), round( $y / 111320.0, 7 ) ];
	}

	private static function prop_tag( array $props, string $name ): string {
		return (string) ( $props[ 'tag_' . $name ] ?? $props[ $name ] ?? '' );
	}

	private static function road_buffer_meters( array $props, array $config ): float {
		$class = self::road_barrier_class( $props, (string) ( $props['wscosm_kind'] ?? '' ), $config );
		return $class['class'] === 'hard' ? (float) $class['buffer'] : 0.0;
	}

	private static function road_barrier_class( array $props, string $kind, array $config ): array {
		$buffers = isset( $config['road_buffers'] ) && is_array( $config['road_buffers'] )
			? $config['road_buffers']
			: self::default_config()['road_buffers'];
		$hw      = strtolower( self::prop_tag( $props, 'highway' ) );

		if ( $hw !== '' ) {
			$service = strtolower( self::prop_tag( $props, 'service' ) );
			if ( preg_match( '/^(footway|path|pedestrian|steps|cycleway|living_street)$/', $hw ) ) {
				return [ 'class' => 'none', 'buffer' => 0.0 ];
			}
			if ( $hw === 'service' && preg_match( '/^(driveway|parking_aisle|drive-through|alley)$/', $service ) ) {
				return [ 'class' => 'none', 'buffer' => 0.0 ];
			}
			if ( preg_match( '/^(motorway|motorway_link|trunk|trunk_link|primary|primary_link|secondary)$/', $hw ) ) {
				return [ 'class' => 'hard', 'buffer' => (float) ( $buffers[ $hw ] ?? $buffers['default'] ?? 8.0 ) ];
			}
			if ( preg_match( '/^(tertiary|unclassified|residential|service)$/', $hw ) ) {
				return [ 'class' => 'soft', 'buffer' => (float) ( $buffers[ $hw ] ?? $buffers['default'] ?? 8.0 ) ];
			}
			return [ 'class' => 'soft', 'buffer' => (float) ( $buffers['default'] ?? 8.0 ) ];
		}

		// Stored features may omit tag_highway; classify_element already set wscosm_kind.
		if ( $kind === 'road' ) {
			return [ 'class' => 'soft', 'buffer' => (float) max( $buffers['tertiary'] ?? 10.0, $buffers['default'] ?? 8.0 ) ];
		}
		if ( $kind === 'path' ) {
			return [ 'class' => 'none', 'buffer' => 0.0 ];
		}

		return [ 'class' => 'none', 'buffer' => 0.0 ];
	}

	private static function is_soft_barrier( array $props, string $kind ): bool {
		if ( self::prop_tag( $props, 'barrier' ) === 'hedge' ) {
			return true;
		}
		if ( $kind === 'parking' ) {
			return ! self::is_hard_parking( $props );
		}
		if ( in_array( $kind, [ 'landuse_construction', 'landuse_commercial', 'landuse_retail' ], true ) ) {
			return true;
		}
		return false;
	}

	private static function is_hard_area_obstacle( array $props, string $kind, array $geom, float $origin_lat ): bool {
		$polygons = self::projected_polygons( $geom, $origin_lat );
		if ( empty( $polygons ) ) {
			return false;
		}
		if ( in_array( $kind, [ 'landuse_industrial', 'landuse_railway', 'restricted_area' ], true ) ) {
			return true;
		}
		if ( $kind === 'parking' ) {
			return self::is_hard_parking( $props );
		}
		if ( in_array( $kind, [ 'landuse_commercial', 'landuse_retail' ], true ) ) {
			return self::polygons_area_m2( $polygons ) >= 1000.0;
		}
		return false;
	}

	private static function is_hard_parking( array $props ): bool {
		$parking = strtolower( self::prop_tag( $props, 'parking' ) );
		$building = strtolower( self::prop_tag( $props, 'building' ) );
		return in_array( $parking, [ 'multi-storey', 'underground', 'rooftop', 'garage_boxes' ], true )
			|| in_array( $building, [ 'parking', 'garages', 'garage' ], true );
	}

	private static function polygons_area_m2( array $polygons ): float {
		$area = 0.0;
		foreach ( $polygons as $poly ) {
			if ( empty( $poly[0] ) ) {
				continue;
			}
			$poly_area = abs( self::ring_area_m2( $poly[0] ) );
			for ( $i = 1; $i < count( $poly ); $i++ ) {
				$poly_area -= abs( self::ring_area_m2( $poly[ $i ] ) );
			}
			$area += max( 0.0, $poly_area );
		}
		return $area;
	}

	private static function ring_area_m2( array $ring ): float {
		$sum = 0.0;
		for ( $i = 0; $i < count( $ring ) - 1; $i++ ) {
			$sum += (float) $ring[ $i ]['x'] * (float) $ring[ $i + 1 ]['y'] - (float) $ring[ $i + 1 ]['x'] * (float) $ring[ $i ]['y'];
		}
		return $sum / 2.0;
	}

	private static function building_key( array $props, array $geom ): string {
		$object_key = sanitize_text_field( (string) ( $props['object_key'] ?? '' ) );
		if ( $object_key !== '' ) {
			return $object_key;
		}
		$osm_type = sanitize_key( (string) ( $props['wscosm_osm_el_type'] ?? '' ) );
		$osm_id = absint( $props['wscosm_osm_id'] ?? 0 );
		if ( $osm_type !== '' && $osm_id > 0 ) {
			return $osm_type . ':' . $osm_id;
		}
		return 'building_' . substr( md5( wp_json_encode( $geom ) ), 0, 12 );
	}

	private static function median( array $values ): ?float {
		$values = array_values(
			array_filter(
				array_map( 'floatval', $values ),
				static function ( float $v ): bool {
					return is_finite( $v );
				}
			)
		);
		$count = count( $values );
		if ( $count === 0 ) {
			return null;
		}
		sort( $values, SORT_NUMERIC );
		$mid = intdiv( $count, 2 );
		if ( $count % 2 === 1 ) {
			return round( $values[ $mid ], 2 );
		}
		return round( ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2.0, 2 );
	}

	private static function trace_event( array &$events, string $event, array $data = [] ): void {
		$row = [
			't'     => gmdate( 'c' ),
			'event' => $event,
			'data'  => $data,
			'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 2 ),
		];
		$events[] = $row;
		if ( count( $events ) > 80 ) {
			array_shift( $events );
		}
		if ( class_exists( 'WSCOSM_Log' ) ) {
			WSCOSM_Log::info( 'territory_job', $event, $data, isset( $data['city_id'] ) ? (int) $data['city_id'] : null );
		}
		if ( class_exists( 'WSCOSM_Feature_Store' ) ) {
			WSCOSM_Feature_Store::agent_ndjson_log( 'TERRITORY', 'territory-job.php', $event, $data );
		}
	}

	private static function public_config_snapshot( array $config ): array {
		return [
			'preset' => (string) ( $config['preset'] ?? '' ),
			'cell_size_m' => (float) ( $config['cell_size_m'] ?? 0 ),
			'max_distance_m' => (float) ( $config['max_distance_m'] ?? 0 ),
			'max_grid_cells' => (int) ( $config['max_grid_cells'] ?? 0 ),
			'use_line_of_sight' => ! empty( $config['use_line_of_sight'] ),
			'use_footways' => ! empty( $config['use_footways'] ),
		];
	}

	private static function input_counts( array $inputs ): array {
		return [
			'buildings' => count( $inputs['buildings'] ?? [] ),
			'hard_roads' => count( $inputs['roads'] ?? [] ),
			'soft_roads' => count( $inputs['soft_roads'] ?? [] ),
			'railways' => count( $inputs['railways'] ?? [] ),
			'waters' => count( $inputs['waters'] ?? [] ),
			'barriers' => count( $inputs['barriers'] ?? [] ),
			'hard_areas' => count( $inputs['areas'] ?? [] ),
			'soft_areas' => count( $inputs['soft_areas'] ?? [] ),
		];
	}

	private static function percentile( array $values, float $p ): ?float {
		$values = array_values(
			array_filter(
				array_map( 'floatval', $values ),
				static function ( float $v ): bool {
					return is_finite( $v );
				}
			)
		);
		if ( empty( $values ) ) {
			return null;
		}
		sort( $values, SORT_NUMERIC );
		$rank = max( 0, min( count( $values ) - 1, (int) ceil( $p * count( $values ) ) - 1 ) );
		return round( $values[ $rank ], 2 );
	}

	private static function elapsed_ms( float $started ): float {
		return round( ( microtime( true ) - $started ) * 1000.0, 2 );
	}

	private static function bbox_area_km2( array $bbox ): float {
		$s = (float) ( $bbox['s'] ?? 0.0 );
		$w = (float) ( $bbox['w'] ?? 0.0 );
		$n = (float) ( $bbox['n'] ?? 0.0 );
		$e = (float) ( $bbox['e'] ?? 0.0 );
		if ( $s >= $n || $w >= $e ) {
			return 0.0;
		}
		$mid_lat = ( $s + $n ) / 2.0;
		$height = ( $n - $s ) * 111.32;
		$width = ( $e - $w ) * 111.32 * max( 0.15, abs( cos( deg2rad( $mid_lat ) ) ) );
		return max( 0.0, $height * $width );
	}

	private static function osm_quality_level( array $inputs, float $assigned_ratio, float $rejected_los_ratio, float $building_type_coverage_ratio ): string {
		if ( count( $inputs['buildings'] ) === 0 ) {
			return 'very_low';
		}
		$score = 0;
		$score += count( $inputs['buildings'] ) > 0 ? 2 : 0;
		$score += ( count( $inputs['roads'] ) + count( $inputs['barriers'] ) + count( $inputs['railways'] ) + count( $inputs['waters'] ) ) > 0 ? 2 : 0;
		$score += $building_type_coverage_ratio >= 0.35 ? 1 : 0;
		$score += $assigned_ratio >= 0.25 ? 1 : 0;
		$score -= $rejected_los_ratio > 0.35 ? 1 : 0;
		if ( $score >= 5 ) {
			return 'high';
		}
		if ( $score >= 3 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function quality_warnings( array $inputs, array $grid, array $allocation, array $territories ): array {
		$warnings = [];
		if ( count( $inputs['buildings'] ) === 0 ) {
			$warnings[] = 'no_buildings';
		}
		if ( count( $inputs['roads'] ) === 0 && count( $inputs['barriers'] ) === 0 ) {
			$warnings[] = 'few_osm_boundaries';
		}
		if ( ! empty( $inputs['soft_roads'] ) ) {
			$warnings[] = 'soft_roads_not_used_as_hard_barriers';
		}
		if ( ! empty( $inputs['soft_areas'] ) ) {
			$warnings[] = 'soft_area_obstacles_not_subtracted';
		}
		$free = max( 1, (int) $grid['free_count'] );
		$assigned_ratio = (int) $allocation['assigned_count'] / $free;
		if ( $assigned_ratio < 0.15 && count( $inputs['buildings'] ) > 0 ) {
			$warnings[] = 'low_assigned_area';
		}
		if ( ! empty( $allocation['line_of_sight_rejected'] ) ) {
			$warnings[] = 'line_of_sight_trimmed';
		}
		if ( $assigned_ratio > 0 && ( (int) $allocation['line_of_sight_rejected'] / $free ) > 0.35 ) {
			$warnings[] = 'high_line_of_sight_rejection_ratio';
		}
		if ( empty( $territories ) && count( $inputs['buildings'] ) > 0 ) {
			$warnings[] = 'no_valid_territories';
		}
		return array_values( array_unique( $warnings ) );
	}

	private static function status_key( string $job_id ): string {
		return 'wscosm_territory_status_' . sanitize_key( $job_id );
	}

	private static function payload_key( string $job_id ): string {
		return 'wscosm_territory_payload_' . sanitize_key( $job_id );
	}

	private static function result_key( string $job_id ): string {
		return 'wscosm_territory_result_' . sanitize_key( $job_id );
	}

	private static function set_status( string $job_id, array $status ): void {
		set_transient( self::status_key( $job_id ), $status, self::TRANSIENT_TTL );
	}

	private static function set_payload( string $job_id, array $payload ): void {
		set_transient( self::payload_key( $job_id ), $payload, self::TRANSIENT_TTL );
	}

	private static function get_payload( string $job_id ): ?array {
		$payload = get_transient( self::payload_key( $job_id ) );
		return is_array( $payload ) ? $payload : null;
	}

	private static function set_result( string $job_id, array $result ): void {
		$path = self::worker_storage_dir() . '/territory-result-' . sanitize_key( $job_id ) . '.geojson';
		$json = wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( is_string( $json ) && file_put_contents( $path, $json ) !== false ) {
			set_transient(
				self::result_key( $job_id ),
				[
					'_file' => $path,
					'type' => 'FeatureCollection',
					'features_count' => count( $result['features'] ?? [] ),
					'stats' => is_array( $result['stats'] ?? null ) ? $result['stats'] : [],
				],
				self::TRANSIENT_TTL
			);
			return;
		}
		set_transient( self::result_key( $job_id ), $result, self::TRANSIENT_TTL );
	}
}
