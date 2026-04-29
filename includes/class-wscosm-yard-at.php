<?php
/**
 * Поиск придомового участка (wsp_yard) по точке на карте — для попапа эргономики.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Yard_At {

	private static function meta_city_id(): string {
		return class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
	}

	private static function meta_entity_type(): string {
		return class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ENTITY_TYPE : 'wsosm_entity_type';
	}

	private static function meta_status(): string {
		return class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_STATUS : 'wsosm_status';
	}

	private static function meta_address(): string {
		return class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ADDRESS_FULL : 'wsosm_address_full';
	}

	/**
	 * ID придомового (здание), в полигоне которого лежит точка; иначе null.
	 */
	public static function find_yard_id_at( int $city_id, float $lat, float $lng ): ?int {
		if ( $city_id <= 0 || ! class_exists( 'WSErgo_CPT' ) || ! class_exists( 'WSErgo_Data' ) ) {
			return null;
		}
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		$delta = (float) apply_filters( 'wscosm_yard_search_delta_deg', 0.03 );
		$delta = max( 0.005, min( 0.15, $delta ) );

		$q = new WP_Query(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => 'publish',
				'posts_per_page'         => 120,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_term_meta_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => self::meta_city_id(),
						'value' => $city_id,
					],
					[
						'key'   => self::meta_entity_type(),
						'value' => 'building',
					],
					[
						'key'     => WSErgo_CPT::META_LAT,
						'value'   => [ $lat - $delta, $lat + $delta ],
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					],
					[
						'key'     => WSErgo_CPT::META_LNG,
						'value'   => [ $lng - $delta, $lng + $delta ],
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					],
				],
			]
		);

		$ids = array_map( 'intval', (array) $q->posts );
		foreach ( $ids as $pid ) {
			$geojson = (string) get_post_meta( $pid, WSErgo_CPT::META_GEOJSON, true );
			if ( $geojson === '' ) {
				continue;
			}
			$g = json_decode( $geojson, true );
			if ( ! is_array( $g ) ) {
				continue;
			}
			$geometry = null;
			if ( isset( $g['type'], $g['coordinates'] ) && is_string( $g['type'] ) ) {
				$geometry = [ 'type' => $g['type'], 'coordinates' => $g['coordinates'] ];
			} elseif ( isset( $g['geometry']['type'], $g['geometry']['coordinates'] ) ) {
				$geometry = [
					'type'        => (string) $g['geometry']['type'],
					'coordinates' => $g['geometry']['coordinates'],
				];
			}
			if ( $geometry === null || ! class_exists( 'WSCOSM_Geo' ) ) {
				continue;
			}
			if ( WSCOSM_Geo::point_in_geometry( $lat, $lng, $geometry ) ) {
				return $pid;
			}
		}

		return null;
	}

	/**
	 * HTML блока эргономики для попапа (как у полигонов придомовых).
	 */
	public static function yard_popup_fragment( int $yard_id ): string {
		if ( ! class_exists( 'WSErgo_Data' ) || ! class_exists( 'WSErgo_CPT' ) ) {
			return '';
		}
		$addr   = (string) get_post_meta( $yard_id, self::meta_address(), true );
		$title  = $addr !== '' ? $addr : (string) get_the_title( $yard_id );
		$status = (string) get_post_meta( $yard_id, self::meta_status(), true );
		return WSErgo_Data::format_yard_polygon_popup_html( $yard_id, $title, $status );
	}
}
