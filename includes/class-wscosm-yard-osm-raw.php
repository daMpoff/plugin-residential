<?php
/**
 * Заполнение сырых показателей эргономики (wsergo_raw_*) из объектов OSM города.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Yard_Osm_Raw {

	private const NEAR_M = 120.0;

	/**
	 * Индекс: wscosm_kind => список GeoJSON features.
	 *
	 * @param array<int, mixed> $features
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function build_kind_index( array $features ): array {
		$by = [];
		foreach ( $features as $feat ) {
			if ( ! is_array( $feat ) || ( $feat['type'] ?? '' ) !== 'Feature' ) {
				continue;
			}
			$props = isset( $feat['properties'] ) && is_array( $feat['properties'] ) ? $feat['properties'] : [];
			$kind  = isset( $props['wscosm_kind'] ) ? WSCOSM_Feature_Store::sanitize_kind( (string) $props['wscosm_kind'] ) : '';
			if ( $kind === '' ) {
				continue;
			}
			if ( ! isset( $by[ $kind ] ) ) {
				$by[ $kind ] = [];
			}
			$by[ $kind ][] = $feat;
		}
		return $by;
	}

	/**
	 * @return array<string, mixed>|null GeoJSON geometry
	 */
	public static function yard_geometry_from_post( int $yard_id ): ?array {
		if ( ! class_exists( 'WSErgo_CPT' ) ) {
			return null;
		}
		$geojson = (string) get_post_meta( $yard_id, WSErgo_CPT::META_GEOJSON, true );
		if ( $geojson === '' ) {
			return null;
		}
		$g = json_decode( $geojson, true );
		if ( ! is_array( $g ) ) {
			return null;
		}
		if ( isset( $g['type'], $g['coordinates'] ) && is_string( $g['type'] ) ) {
			return [ 'type' => (string) $g['type'], 'coordinates' => $g['coordinates'] ];
		}
		if ( isset( $g['geometry']['type'], $g['geometry']['coordinates'] ) ) {
			return [
				'type'        => (string) $g['geometry']['type'],
				'coordinates' => $g['geometry']['coordinates'],
			];
		}
		return null;
	}

	/**
	 * Записать рассчитанные по OSM сырые значения для показателей из справочника.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $kind_index
	 * @return int Сколько полей wsergo_raw_* обновлено.
	 */
	public static function sync_yard_raw_from_osm( int $yard_id, array $kind_index ): int {
		if ( ! class_exists( 'WSErgo_Indicators' ) || ! class_exists( 'WSErgo_CPT' ) ) {
			return 0;
		}
		$geometry = self::yard_geometry_from_post( $yard_id );
		if ( $geometry === null ) {
			return 0;
		}
		$rep = WSCOSM_Geo::geometry_representative_latlng( $geometry );
		if ( $rep === null ) {
			return 0;
		}
		$lat_c = $rep[0];
		$lng_c = $rep[1];

		$ctx = [
			'yard_id'   => $yard_id,
			'geometry'  => $geometry,
			'centroid'  => [ $lat_c, $lng_c ],
			'area_ha'   => WSCOSM_Geo::polygon_geometry_area_hectares( $geometry ),
			'kind_index'=> $kind_index,
		];

		$written = 0;
		foreach ( WSErgo_Indicators::get_definitions() as $def ) {
			$id = isset( $def['id'] ) ? sanitize_key( (string) $def['id'] ) : '';
			if ( $id === '' ) {
				continue;
			}
			$val = self::compute_raw_for_indicator_id( $id, $ctx );
			if ( $val !== null && is_finite( $val ) ) {
				update_post_meta( $yard_id, WSErgo_Indicators::meta_key_for_raw( $id ), round( (float) $val, 4 ) );
				++$written;
			}
		}

		return $written;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function compute_raw_for_indicator_id( string $id, array $ctx ): ?float {
		$aliases = [
			'building_to_parking_dist_m' => 'centroid_to_parking_m',
			'parking_to_window_m'        => 'centroid_to_parking_m',
			'sidewalk_width_m'           => 'sidewalk_width_osm_m',
			'sidewalk_min_width_m'       => 'sidewalk_width_osm_m',
			'sidewalk_isolated_from_road'=> 'ped_path_separated_osm',
			'playground_safe_distance'   => 'playground_safe_distance_osm',
		];
		$key = $aliases[ $id ] ?? $id;

		switch ( $key ) {
			case 'centroid_to_parking_m':
				return self::min_dist_centroid_to_kind( $ctx, 'parking' );
			case 'playground_to_road_dist_m':
				return self::playground_to_road_dist( $ctx );
			case 'playground_to_parking_dist_m':
				return self::playground_to_parking_dist( $ctx );
			case 'container_to_playground_dist_m':
			case 'bin_area_to_window_m':
				return self::container_playground_or_bin_window( $ctx, $key );
			case 'street_lamp_density_per_ha':
				return self::lamps_per_ha( $ctx );
			case 'sidewalk_width_osm_m':
				return self::min_path_width_near( $ctx );
			case 'ped_path_separated':
			case 'ped_path_separated_osm':
				return self::ped_path_separated_heuristic( $ctx );
			case 'playground_safe_distance_osm':
				return self::playground_safe_score( $ctx );
			case 'zones_diversity_0_10':
				return self::zones_diversity( $ctx );
			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function min_dist_centroid_to_kind( array $ctx, string $kind ): ?float {
		$lat = $ctx['centroid'][0];
		$lng = $ctx['centroid'][1];
		$min = self::min_dist_point_to_kind_features( $lat, $lng, $ctx['kind_index'], $kind );
		return $min < 1e12 ? round( $min, 2 ) : null;
	}

	/**
	 * @param array<string, array<int, array<string, mixed>>> $kind_index
	 */
	private static function min_dist_point_to_kind_features( float $lat, float $lng, array $kind_index, string $kind ): float {
		$m = 1e15;
		foreach ( $kind_index[ $kind ] ?? [] as $feat ) {
			$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( $geom === null ) {
				continue;
			}
			$d = WSCOSM_Geo::min_distance_point_to_geometry_m( $lat, $lng, $geom );
			if ( $d < $m ) {
				$m = $d;
			}
		}
		return $m;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function closest_feature_geometry( array $ctx, string $kind ): ?array {
		$lat = $ctx['centroid'][0];
		$lng = $ctx['centroid'][1];
		$best = null;
		$best_d = 1e15;
		foreach ( $ctx['kind_index'][ $kind ] ?? [] as $feat ) {
			$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( $geom === null ) {
				continue;
			}
			$d = WSCOSM_Geo::min_distance_point_to_geometry_m( $lat, $lng, $geom );
			if ( $d < $best_d ) {
				$best_d = $d;
				$best   = $geom;
			}
		}
		return $best;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function playground_to_road_dist( array $ctx ): ?float {
		$play = self::closest_feature_geometry( $ctx, 'playground' );
		if ( $play === null ) {
			return null;
		}
		$rep = WSCOSM_Geo::geometry_representative_latlng( $play );
		if ( $rep === null ) {
			return null;
		}
		$d = self::min_dist_point_to_kind_features( $rep[0], $rep[1], $ctx['kind_index'], 'road' );
		return $d < 1e12 ? round( $d, 2 ) : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function playground_to_parking_dist( array $ctx ): ?float {
		$play = self::closest_feature_geometry( $ctx, 'playground' );
		if ( $play === null ) {
			return null;
		}
		$rep = WSCOSM_Geo::geometry_representative_latlng( $play );
		if ( $rep === null ) {
			return null;
		}
		$d = self::min_dist_point_to_kind_features( $rep[0], $rep[1], $ctx['kind_index'], 'parking' );
		return $d < 1e12 ? round( $d, 2 ) : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function container_playground_or_bin_window( array $ctx, string $key ): ?float {
		$lat_c = $ctx['centroid'][0];
		$lng_c = $ctx['centroid'][1];

		if ( $key === 'bin_area_to_window_m' ) {
			$m = self::min_dist_point_to_kind_features( $lat_c, $lng_c, $ctx['kind_index'], 'waste_basket' );
			return $m < 1e12 ? round( $m, 2 ) : null;
		}

		$m = 1e15;
		foreach ( $ctx['kind_index']['waste_basket'] ?? [] as $bf ) {
			$g = isset( $bf['geometry'] ) && is_array( $bf['geometry'] ) ? $bf['geometry'] : null;
			if ( $g === null ) {
				continue;
			}
			$rp = WSCOSM_Geo::geometry_representative_latlng( $g );
			if ( $rp === null ) {
				continue;
			}
			if ( WSCOSM_Geo::haversine_m( $lat_c, $lng_c, $rp[0], $rp[1] ) > self::NEAR_M * 2 ) {
				continue;
			}
			foreach ( $ctx['kind_index']['playground'] ?? [] as $pf ) {
				$pg = isset( $pf['geometry'] ) && is_array( $pf['geometry'] ) ? $pf['geometry'] : null;
				if ( $pg === null ) {
					continue;
				}
				$d = WSCOSM_Geo::min_distance_point_to_geometry_m( $rp[0], $rp[1], $pg );
				if ( $d < $m ) {
					$m = $d;
				}
			}
		}
		return $m < 1e12 ? round( $m, 2 ) : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function lamps_per_ha( array $ctx ): ?float {
		$a = (float) $ctx['area_ha'];
		if ( $a < 1e-6 ) {
			return null;
		}
		$geom_y = $ctx['geometry'];
		$n      = 0;
		foreach ( $ctx['kind_index']['light'] ?? [] as $feat ) {
			$g = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( $g === null || ( $g['type'] ?? '' ) !== 'Point' ) {
				continue;
			}
			$p = $g['coordinates'] ?? [];
			if ( ! is_array( $p ) || count( $p ) < 2 ) {
				continue;
			}
			$lat = (float) $p[1];
			$lng = (float) $p[0];
			if ( WSCOSM_Geo::point_in_geometry( $lat, $lng, $geom_y ) ) {
				++$n;
			}
		}
		return round( $n / $a, 4 );
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function min_path_width_near( array $ctx ): ?float {
		$lat_c = $ctx['centroid'][0];
		$lng_c = $ctx['centroid'][1];
		$min_w = null;
		foreach ( $ctx['kind_index']['path'] ?? [] as $feat ) {
			$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( $geom === null ) {
				continue;
			}
			if ( WSCOSM_Geo::min_distance_point_to_geometry_m( $lat_c, $lng_c, $geom ) > self::NEAR_M ) {
				continue;
			}
			$props = isset( $feat['properties'] ) && is_array( $feat['properties'] ) ? $feat['properties'] : [];
			$wraw  = isset( $props['tag_width'] ) ? (string) $props['tag_width'] : '';
			if ( $wraw === '' ) {
				continue;
			}
			if ( ! preg_match( '/^(\d+(?:[.,]\d+)?)/', $wraw, $m ) ) {
				continue;
			}
			$w = (float) str_replace( ',', '.', $m[1] );
			if ( $w <= 0 ) {
				continue;
			}
			$min_w = $min_w === null ? $w : min( $min_w, $w );
		}
		return $min_w !== null ? round( $min_w, 2 ) : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function ped_path_separated_heuristic( array $ctx ): ?float {
		foreach ( $ctx['kind_index']['path'] ?? [] as $feat ) {
			$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
			if ( $geom === null ) {
				continue;
			}
			$lat_c = $ctx['centroid'][0];
			$lng_c = $ctx['centroid'][1];
			if ( WSCOSM_Geo::min_distance_point_to_geometry_m( $lat_c, $lng_c, $geom ) > self::NEAR_M ) {
				continue;
			}
			$sep = self::min_separation_path_to_roads( $geom, $ctx['kind_index'] );
			if ( $sep !== null && $sep >= 3.5 ) {
				return 1.0;
			}
		}
		return 0.0;
	}

	/**
	 * @param array<string, mixed>                  $geom
	 * @param array<string, array<int, mixed>> $kind_index
	 */
	private static function min_separation_path_to_roads( array $geom, array $kind_index ): ?float {
		$c = WSCOSM_Geo::coords_centroid( $geom['coordinates'] ?? [] );
		if ( $c === null ) {
			return null;
		}
		$lat = (float) $c[1];
		$lng = (float) $c[0];
		$d   = self::min_dist_point_to_kind_features( $lat, $lng, $kind_index, 'road' );
		return $d < 1e12 ? $d : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function playground_safe_score( array $ctx ): ?float {
		$d = self::playground_to_road_dist( $ctx );
		if ( $d === null ) {
			return null;
		}
		$s = 10.0 * min( 1.0, max( 0.0, $d / 30.0 ) );
		return round( $s, 2 );
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function zones_diversity( array $ctx ): ?float {
		$lat_c = $ctx['centroid'][0];
		$lng_c = $ctx['centroid'][1];
		$marks = [
			'path'           => false,
			'playground'     => false,
			'parking'        => false,
			'landuse_green'  => false,
			'bench'          => false,
			'light'          => false,
			'waste_basket'   => false,
			'water'          => false,
		];
		foreach ( $marks as $kind => $_f ) {
			foreach ( $ctx['kind_index'][ $kind ] ?? [] as $feat ) {
				$geom = isset( $feat['geometry'] ) && is_array( $feat['geometry'] ) ? $feat['geometry'] : null;
				if ( $geom === null ) {
					continue;
				}
				if ( WSCOSM_Geo::min_distance_point_to_geometry_m( $lat_c, $lng_c, $geom ) <= self::NEAR_M ) {
					$marks[ $kind ] = true;
					break;
				}
			}
		}
		$cnt = 0;
		foreach ( $marks as $v ) {
			if ( $v ) {
				++$cnt;
			}
		}
		if ( $cnt === 0 ) {
			return null;
		}
		return min( 10.0, round( $cnt * 10.0 / 8.0, 2 ) );
	}
}
