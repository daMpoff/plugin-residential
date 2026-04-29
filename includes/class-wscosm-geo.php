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
