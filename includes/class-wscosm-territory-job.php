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
					'cell_size_m'        => 2.0,
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
			]
		);

		try {
			$result = self::compute(
				(int) $payload['city_id'],
				(array) $payload['bbox'],
				(array) $payload['config'],
				static function ( string $phase, int $current, int $total, string $message = '' ) use ( $job_id ): void {
					self::set_status(
						$job_id,
						[
							'job_id'  => $job_id,
							'status'  => 'running',
							'phase'   => $phase,
							'current' => $current,
							'total'   => max( 1, $total ),
							'message' => $message,
						]
					);
				}
			);
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
		return is_array( $result ) ? $result : [ 'type' => 'FeatureCollection', 'features' => [] ];
	}

	private static function compute( int $city_id, array $bbox, array $config, callable $progress ): array {
		$fc = class_exists( 'WSCOSM_Feature_Store' )
			? WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox, 50000 )
			: [ 'type' => 'FeatureCollection', 'features' => [] ];
		$features = is_array( $fc['features'] ?? null ) ? $fc['features'] : [];
		$origin_lat = ( (float) $bbox['s'] + (float) $bbox['n'] ) / 2.0;
		$inputs = self::collect_inputs( $features, $origin_lat, $config );
		$progress( 'grid', 0, 1, 'Building free grid' );
		$grid = self::build_grid( $bbox, $origin_lat, $inputs, $config );
		$progress( 'allocation', 0, max( 1, $grid['free_count'] ), 'Allocating cells' );
		$allocation = self::allocate_cells( $grid, $inputs, $config, $progress );
		$progress( 'polygonize', 0, max( 1, count( $allocation['by_building'] ) ), 'Polygonizing' );
		$territories = self::polygonize( $allocation['by_building'], $grid, $origin_lat, $config, $inputs['buildings'], $inputs, $allocation['meta'] );
		$warnings = self::quality_warnings( $inputs, $grid, $allocation, $territories );
		$stats = [
			'method'          => self::METHOD,
			'source_preset'   => (string) $config['preset'],
			'buildings'       => count( $inputs['buildings'] ),
			'roads'           => count( $inputs['roads'] ),
			'railways'        => count( $inputs['railways'] ),
			'waters'          => count( $inputs['waters'] ),
			'barriers'        => count( $inputs['barriers'] ),
			'area_obstacles'  => count( $inputs['areas'] ),
			'obstacles'       => count( $inputs['buildings'] ) + count( $inputs['roads'] ) + count( $inputs['railways'] ) + count( $inputs['waters'] ) + count( $inputs['barriers'] ) + count( $inputs['areas'] ),
			'grid_cells'      => $grid['nx'] * $grid['ny'],
			'free_cells'      => $grid['free_count'],
			'assigned_cells'  => $allocation['assigned_count'],
			'unassigned_cells'=> max( 0, (int) $grid['free_count'] - (int) $allocation['assigned_count'] ),
			'line_of_sight_rejected_cells' => (int) $allocation['line_of_sight_rejected'],
			'territories'     => count( $territories ),
			'cell_size_m'     => $grid['cell'],
			'max_distance_m'  => (float) $config['max_distance_m'],
			'distance_median' => self::median( $allocation['distances'] ),
			'warnings'        => $warnings,
		];
		return [
			'type'     => 'FeatureCollection',
			'features' => $territories,
			'stats'    => $stats,
			'debug'    => [
				'stats' => $stats,
			],
		];
	}

	private static function collect_inputs( array $features, float $origin_lat, array $config ): array {
		$inputs = [
			'buildings' => [],
			'roads'    => [],
			'railways' => [],
			'waters'   => [],
			'barriers' => [],
			'areas'    => [],
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
			$road_buffer = self::road_buffer_meters( $props, $config );
			if ( $road_buffer > 0 ) {
				$inputs['roads'][] = [ 'buffer' => $road_buffer, 'segments' => self::projected_segments( $geom, $origin_lat ), 'feature' => $feature ];
				continue;
			}
			if ( $kind === 'railway' || self::prop_tag( $props, 'railway' ) !== '' ) {
				$inputs['railways'][] = [ 'buffer' => 10.0, 'segments' => self::projected_segments( $geom, $origin_lat ), 'feature' => $feature ];
				continue;
			}
			if ( $kind === 'water' || self::prop_tag( $props, 'natural' ) === 'water' || self::prop_tag( $props, 'waterway' ) !== '' ) {
				$inputs['waters'][] = [
					'buffer'   => in_array( (string) ( $geom['type'] ?? '' ), [ 'LineString', 'MultiLineString' ], true ) ? 8.0 : 0.0,
					'segments' => self::projected_segments( $geom, $origin_lat ),
					'polygons' => self::projected_polygons( $geom, $origin_lat ),
					'feature'  => $feature,
				];
				continue;
			}
			if ( $kind === 'barrier' || preg_match( '/^(fence|wall|retaining_wall|hedge)$/', self::prop_tag( $props, 'barrier' ) ) ) {
				$inputs['barriers'][] = [ 'buffer' => 3.0, 'segments' => self::projected_segments( $geom, $origin_lat ), 'feature' => $feature ];
				continue;
			}
			if ( in_array( $kind, [ 'parking', 'landuse_industrial', 'landuse_railway', 'landuse_construction', 'landuse_commercial', 'landuse_retail', 'restricted_area' ], true ) ) {
				$inputs['areas'][] = [ 'polygons' => self::projected_polygons( $geom, $origin_lat ), 'feature' => $feature ];
			}
		}
		return $inputs;
	}

	private static function build_grid( array $bbox, float $origin_lat, array $inputs, array $config ): array {
		$sw = self::project_point( (float) $bbox['w'], (float) $bbox['s'], $origin_lat );
		$ne = self::project_point( (float) $bbox['e'], (float) $bbox['n'], $origin_lat );
		$width = max( 1.0, $ne['x'] - $sw['x'] );
		$height = max( 1.0, $ne['y'] - $sw['y'] );
		$requested = max( 1.0, (float) $config['cell_size_m'] );
		$cell = max( $requested, sqrt( ( $width * $height ) / max( 1, (int) $config['max_grid_cells'] ) ) );
		$nx = max( 1, (int) ceil( $width / $cell ) );
		$ny = max( 1, (int) ceil( $height / $cell ) );
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
		$distances = [];
		for ( $idx = 0; $idx < $total; $idx++ ) {
			if ( $owner[ $idx ] === '' ) {
				continue;
			}
			$owner_key = $owner[ $idx ];
			$building = $building_map[ $owner_key ] ?? null;
			if ( $building && ! empty( $config['use_line_of_sight'] ) ) {
				$p = self::grid_center( $grid, $idx % $nx, intdiv( $idx, $nx ) );
				$blocked_by = self::path_blocked_by( $p, $building, $inputs );
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
			if ( self::point_in_polygons( $p, $building['polygons'] ) ) {
				return true;
			}
		}
		foreach ( [ 'roads', 'railways', 'barriers' ] as $key ) {
			if ( self::min_distance_to_buffered_lines( $p, $inputs[ $key ] ) <= 0 ) {
				return true;
			}
		}
		foreach ( $inputs['waters'] as $water ) {
			if ( ! empty( $water['polygons'] ) && self::point_in_polygons( $p, $water['polygons'] ) ) {
				return true;
			}
			if ( (float) ( $water['buffer'] ?? 0 ) > 0 && self::min_distance_to_buffered_lines( $p, [ $water ] ) <= 0 ) {
				return true;
			}
		}
		foreach ( $inputs['areas'] as $area ) {
			if ( self::point_in_polygons( $p, $area['polygons'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function min_distance_to_buffered_lines( array $p, array $items ): float {
		$best = INF;
		foreach ( $items as $item ) {
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
			if ( self::segment_distance_to_buffered_lines( $a, $b, $inputs[ $key ] ) <= 0.0 ) {
				return $label;
			}
		}
		foreach ( $inputs['waters'] as $water ) {
			if ( ! empty( $water['polygons'] ) && self::line_hits_polygons( $a, $b, $water['polygons'] ) ) {
				return 'water';
			}
			if ( (float) ( $water['buffer'] ?? 0 ) > 0 && self::segment_distance_to_buffered_lines( $a, $b, [ $water ] ) <= 0.0 ) {
				return 'water';
			}
		}
		foreach ( $inputs['areas'] as $area ) {
			if ( self::line_hits_polygons( $a, $b, $area['polygons'] ) ) {
				return 'area_obstacle';
			}
		}
		return '';
	}

	private static function segment_distance_to_buffered_lines( array $a, array $b, array $items ): float {
		$best = INF;
		foreach ( $items as $item ) {
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
		$buffers = isset( $config['road_buffers'] ) && is_array( $config['road_buffers'] )
			? $config['road_buffers']
			: self::default_config()['road_buffers'];
		$hw      = strtolower( self::prop_tag( $props, 'highway' ) );
		$kind    = (string) ( $props['wscosm_kind'] ?? '' );

		if ( $hw !== '' ) {
			if ( empty( $config['use_footways'] ) && preg_match( '/^(footway|path|pedestrian|steps|cycleway)$/', $hw ) ) {
				return 0.0;
			}

			return (float) ( $buffers[ $hw ] ?? $buffers['default'] ?? 8.0 );
		}

		// Stored features may omit tag_highway; classify_element already set wscosm_kind.
		if ( $kind === 'road' ) {
			return (float) max( $buffers['tertiary'] ?? 10.0, $buffers['default'] ?? 8.0 );
		}
		if ( $kind === 'path' ) {
			if ( empty( $config['use_footways'] ) ) {
				return 0.0;
			}

			return (float) ( $buffers['path'] ?? $buffers['pedestrian'] ?? $buffers['service'] ?? 4.0 );
		}

		return 0.0;
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

	private static function quality_warnings( array $inputs, array $grid, array $allocation, array $territories ): array {
		$warnings = [];
		if ( count( $inputs['buildings'] ) === 0 ) {
			$warnings[] = 'no_buildings';
		}
		if ( count( $inputs['roads'] ) === 0 && count( $inputs['barriers'] ) === 0 ) {
			$warnings[] = 'few_osm_boundaries';
		}
		$free = max( 1, (int) $grid['free_count'] );
		$assigned_ratio = (int) $allocation['assigned_count'] / $free;
		if ( $assigned_ratio < 0.15 && count( $inputs['buildings'] ) > 0 ) {
			$warnings[] = 'low_assigned_area';
		}
		if ( ! empty( $allocation['line_of_sight_rejected'] ) ) {
			$warnings[] = 'line_of_sight_trimmed';
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
		set_transient( self::result_key( $job_id ), $result, self::TRANSIENT_TTL );
	}
}
