<?php
/**
 * Геометрия для привязки точки к полигону (GeoJSON).
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Geo {

	/**
	 * Расстояние по сфере (метры), точки в градусах широты/долготы.
	 */
	public static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth = 6371000.0;
		$φ1 = deg2rad( $lat1 );
		$φ2 = deg2rad( $lat2 );
		$dφ = deg2rad( $lat2 - $lat1 );
		$dλ = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dφ / 2 ) * sin( $dφ / 2 ) + cos( $φ1 ) * cos( $φ2 ) * sin( $dλ / 2 ) * sin( $dλ / 2 );
		$c    = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $earth * $c;
	}

	/**
	 * Центроид из координат GeoJSON (среднее по всем вершинам).
	 *
	 * @param array<int|float|string, mixed> $coords Узел coordinates.
	 * @return array{0:float,1:float}|null [ lon, lat ]
	 */
	public static function coords_centroid( $coords ): ?array {
		$sum_lon = 0.0;
		$sum_lat = 0.0;
		$n       = 0;
		self::walk_coords_accum( $coords, $sum_lon, $sum_lat, $n );
		if ( $n <= 0 ) {
			return null;
		}
		return [ $sum_lon / $n, $sum_lat / $n ];
	}

	/**
	 * @param mixed $coords
	 */
	private static function walk_coords_accum( $coords, float &$sum_lon, float &$sum_lat, int &$n ): void {
		if ( ! is_array( $coords ) ) {
			return;
		}
		if ( isset( $coords[0] ) && is_numeric( $coords[0] ) && isset( $coords[1] ) && is_numeric( $coords[1] ) && ! is_array( $coords[0] ) ) {
			$sum_lon += (float) $coords[0];
			$sum_lat += (float) $coords[1];
			++$n;
			return;
		}
		foreach ( $coords as $c ) {
			self::walk_coords_accum( $c, $sum_lon, $sum_lat, $n );
		}
	}

	/**
	 * Площадь полигона (в гектарах), упрощённо плоская аппроксимация у референсной широты.
	 *
	 * @param array<int, array{0:float,1:float}> $ring Замкнутое кольцо [lon,lat].
	 */
	public static function ring_area_hectares( array $ring, float $ref_lat ): float {
		$n = count( $ring );
		if ( $n < 3 ) {
			return 0.0;
		}
		$lat0 = $ref_lat;
		$r    = 6371000.0;
		$acc  = 0.0;
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$x1 = deg2rad( (float) ( $ring[ $i ][0] ?? 0 ) - (float) ( $ring[0][0] ?? 0 ) ) * cos( deg2rad( $lat0 ) ) * $r;
			$y1 = deg2rad( (float) ( $ring[ $i ][1] ?? 0 ) - (float) ( $ring[0][1] ?? 0 ) ) * $r;
			$j  = $i + 1;
			$x2 = deg2rad( (float) ( $ring[ $j ][0] ?? 0 ) - (float) ( $ring[0][0] ?? 0 ) ) * cos( deg2rad( $lat0 ) ) * $r;
			$y2 = deg2rad( (float) ( $ring[ $j ][1] ?? 0 ) - (float) ( $ring[0][1] ?? 0 ) ) * $r;
			$acc += $x1 * $y2 - $x2 * $y1;
		}
		return abs( $acc / 2.0 ) / 10000.0;
	}

	/**
	 * Площадь первого кольца полигона GeoJSON (га).
	 */
	public static function polygon_geometry_area_hectares( array $geometry ): float {
		$t = (string) ( $geometry['type'] ?? '' );
		if ( $t === 'Polygon' ) {
			$coords = $geometry['coordinates'] ?? [];
			if ( ! is_array( $coords ) || ! isset( $coords[0] ) || ! is_array( $coords[0] ) ) {
				return 0.0;
			}
			$ref_lat = (float) ( $coords[0][0][1] ?? 0 );
			return self::ring_area_hectares( $coords[0], $ref_lat );
		}
		if ( $t === 'MultiPolygon' ) {
			$polys = $geometry['coordinates'] ?? [];
			if ( ! is_array( $polys ) ) {
				return 0.0;
			}
			$s = 0.0;
			foreach ( $polys as $poly ) {
				if ( ! is_array( $poly ) || ! isset( $poly[0] ) ) {
					continue;
				}
				$ref_lat = (float) ( $poly[0][0][1] ?? 0 );
				$s      += self::ring_area_hectares( $poly[0], $ref_lat );
			}
			return $s;
		}
		return 0.0;
	}

	/**
	 * Репрезентативная точка геометрии [lat,lng].
	 *
	 * @param array<string,mixed> $geometry GeoJSON geometry.
	 * @return array{0:float,1:float}|null
	 */
	public static function geometry_representative_latlng( array $geometry ): ?array {
		$c = self::coords_centroid( $geometry['coordinates'] ?? [] );
		if ( $c === null ) {
			return null;
		}
		return [ (float) $c[1], (float) $c[0] ];
	}

	/**
	 * Минимальное расстояние от точки до геометрии (м). Внутри полигона — 0.
	 *
	 * @param array<string,mixed> $geometry GeoJSON geometry.
	 */
	public static function min_distance_point_to_geometry_m( float $lat, float $lng, array $geometry ): float {
		$t = (string) ( $geometry['type'] ?? '' );
		if ( $t === 'Point' ) {
			$p = $geometry['coordinates'] ?? [];
			if ( ! is_array( $p ) || count( $p ) < 2 ) {
				return PHP_INT_MAX / 1000;
			}
			return self::haversine_m( $lat, $lng, (float) $p[1], (float) $p[0] );
		}
		if ( $t === 'MultiPoint' ) {
			$pts = $geometry['coordinates'] ?? [];
			if ( ! is_array( $pts ) ) {
				return PHP_INT_MAX / 1000;
			}
			$min = PHP_INT_MAX / 1000;
			foreach ( $pts as $p ) {
				if ( ! is_array( $p ) || count( $p ) < 2 ) {
					continue;
				}
				$min = min( $min, self::haversine_m( $lat, $lng, (float) $p[1], (float) $p[0] ) );
			}
			return $min;
		}
		if ( $t === 'LineString' ) {
			return self::min_dist_point_linestring_m( $lat, $lng, (array) ( $geometry['coordinates'] ?? [] ) );
		}
		if ( $t === 'MultiLineString' ) {
			$lines = $geometry['coordinates'] ?? [];
			if ( ! is_array( $lines ) ) {
				return PHP_INT_MAX / 1000;
			}
			$min = PHP_INT_MAX / 1000;
			foreach ( $lines as $line ) {
				$min = min( $min, self::min_dist_point_linestring_m( $lat, $lng, (array) $line ) );
			}
			return $min;
		}
		if ( $t === 'Polygon' ) {
			return self::min_dist_point_polygon_m( $lat, $lng, (array) ( $geometry['coordinates'] ?? [] ) );
		}
		if ( $t === 'MultiPolygon' ) {
			$polys = $geometry['coordinates'] ?? [];
			if ( ! is_array( $polys ) ) {
				return PHP_INT_MAX / 1000;
			}
			$min = PHP_INT_MAX / 1000;
			foreach ( $polys as $poly ) {
				$min = min( $min, self::min_dist_point_polygon_m( $lat, $lng, (array) $poly ) );
			}
			return $min;
		}
		return PHP_INT_MAX / 1000;
	}

	/**
	 * @param array<int, array{0:float,1:float}> $coords
	 */
	private static function min_dist_point_linestring_m( float $lat, float $lng, array $coords ): float {
		$m = count( $coords );
		if ( $m < 2 ) {
			return PHP_INT_MAX / 1000;
		}
		$min = PHP_INT_MAX / 1000;
		for ( $i = 0; $i < $m - 1; $i++ ) {
			$a = $coords[ $i ];
			$b = $coords[ $i + 1 ];
			if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) < 2 || count( $b ) < 2 ) {
				continue;
			}
			$min = min(
				$min,
				self::dist_point_segment_m(
					$lat,
					$lng,
					(float) $a[1],
					(float) $a[0],
					(float) $b[1],
					(float) $b[0]
				)
			);
		}
		return $min;
	}

	/**
	 * @param array<int, mixed> $rings Первое кольцо — внешний контур.
	 */
	private static function min_dist_point_polygon_m( float $lat, float $lng, array $rings ): float {
		if ( ! isset( $rings[0] ) || ! is_array( $rings[0] ) ) {
			return PHP_INT_MAX / 1000;
		}
		$outer_raw = $rings[0];
		$outer     = [];
		foreach ( $outer_raw as $pt ) {
			if ( is_array( $pt ) && isset( $pt[0], $pt[1] ) ) {
				$outer[] = [ (float) $pt[0], (float) $pt[1] ];
			}
		}
		if ( count( $outer ) < 3 ) {
			return PHP_INT_MAX / 1000;
		}
		if ( self::point_in_geometry( $lat, $lng, [ 'type' => 'Polygon', 'coordinates' => $rings ] ) ) {
			return 0.0;
		}
		return self::min_dist_point_linestring_m( $lat, $lng, $outer );
	}

	/**
	 * Расстояние точки до отрезка (м) через локальную плоскую проекцию около середины отрезка.
	 */
	private static function dist_point_segment_m(
		float $lat,
		float $lng,
		float $lat_a,
		float $lng_a,
		float $lat_b,
		float $lng_b
	): float {
		$lat0 = ( $lat_a + $lat_b + $lat ) / 3.0;
		$lng0 = ( $lng_a + $lng_b + $lng ) / 3.0;
		$r    = 6371000.0;
		$px   = $r * deg2rad( $lng - $lng0 ) * cos( deg2rad( $lat0 ) );
		$py   = $r * deg2rad( $lat - $lat0 );
		$ax   = $r * deg2rad( $lng_a - $lng0 ) * cos( deg2rad( $lat0 ) );
		$ay   = $r * deg2rad( $lat_a - $lat0 );
		$bx   = $r * deg2rad( $lng_b - $lng0 ) * cos( deg2rad( $lat0 ) );
		$by   = $r * deg2rad( $lat_b - $lat0 );
		$vx   = $bx - $ax;
		$vy   = $by - $ay;
		$wx   = $px - $ax;
		$wy   = $py - $ay;
		$c2   = $vx * $vx + $vy * $vy;
		if ( $c2 < 1e-12 ) {
			return hypot( $px - $ax, $py - $ay );
		}
		$t = ( $wx * $vx + $wy * $vy ) / $c2;
		$t = max( 0.0, min( 1.0, $t ) );
		$qx  = $ax + $t * $vx;
		$qy  = $ay + $t * $vy;
		return hypot( $px - $qx, $py - $qy );
	}

	/**
	 * Точка [lon, lat] внутри кольца GeoJSON (замкнутое, первая точка = последняя).
	 *
	 * @param array<int, array{0:float,1:float}> $ring
	 */
	public static function point_in_ring( float $lat, float $lng, array $ring ): bool {
		$n      = count( $ring );
		$inside = false;
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			$xi = (float) ( $ring[ $i ][0] ?? 0 );
			$yi = (float) ( $ring[ $i ][1] ?? 0 );
			$xj = (float) ( $ring[ $j ][0] ?? 0 );
			$yj = (float) ( $ring[ $j ][1] ?? 0 );
			$den = ( $yj - $yi );
			if ( abs( $den ) < 1e-12 ) {
				$den = $den >= 0 ? 1e-12 : -1e-12;
			}
			if ( ( $yi > $lat ) !== ( $yj > $lat ) && $lng < ( $xj - $xi ) * ( $lat - $yi ) / $den + $xi ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	/**
	 * @param array<string,mixed> $geometry GeoJSON geometry.
	 */
	public static function point_in_geometry( float $lat, float $lng, array $geometry ): bool {
		$t = (string) ( $geometry['type'] ?? '' );
		if ( $t === 'Polygon' ) {
			$coords = $geometry['coordinates'] ?? [];
			if ( ! is_array( $coords ) || ! isset( $coords[0] ) || ! is_array( $coords[0] ) ) {
				return false;
			}
			$outer = $coords[0];
			if ( ! self::point_in_ring( $lat, $lng, $outer ) ) {
				return false;
			}
			for ( $h = 1, $hc = count( $coords ); $h < $hc; $h++ ) {
				if ( is_array( $coords[ $h ] ) && self::point_in_ring( $lat, $lng, $coords[ $h ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $t === 'MultiPolygon' ) {
			$polys = $geometry['coordinates'] ?? [];
			if ( ! is_array( $polys ) ) {
				return false;
			}
			foreach ( $polys as $poly ) {
				if ( ! is_array( $poly ) ) {
					continue;
				}
				$g = [ 'type' => 'Polygon', 'coordinates' => $poly ];
				if ( self::point_in_geometry( $lat, $lng, $g ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
