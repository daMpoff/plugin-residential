<?php
/**
 * Overpass API: запрос и преобразование в GeoJSON.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Overpass {

	public const QUERY_VERSION = 6;

	/**
	 * URL интерпретатора (можно переопределить фильтром).
	 */
	public static function interpreter_url(): string {
		return (string) apply_filters( 'wscosm_overpass_interpreter_url', 'https://overpass-api.de/api/interpreter' );
	}

	/**
	 * Максимальный полуразмер bbox в км (ограничение нагрузки).
	 */
	public static function max_radius_km(): float {
		return (float) apply_filters( 'wscosm_max_radius_km', 5.0 );
	}

	/**
	 * Радиус выборки по умолчанию (км).
	 */
	public static function default_radius_km(): float {
		$v = get_option( 'wscosm_radius_km', 1.2 );
		return min( self::max_radius_km(), max( 0.3, (float) $v ) );
	}

	/**
	 * Bbox south, west, north, east в градусах.
	 *
	 * @return array{s:float,w:float,n:float,e:float}
	 */
	public static function bbox_from_center( float $lat, float $lng, float $radius_km ): array {
		$radius_km = min( self::max_radius_km(), max( 0.2, $radius_km ) );
		$dlat      = $radius_km / 111.0;
		$cos       = cos( deg2rad( $lat ) );
		$cos       = max( 0.15, min( 1.0, $cos ) );
		$dlng      = $radius_km / ( 111.0 * $cos );
		return [
			's' => $lat - $dlat,
			'w' => $lng - $dlng,
			'n' => $lat + $dlat,
			'e' => $lng + $dlng,
		];
	}

	/**
	 * Расстояние между точками на сфере (км).
	 */
	public static function haversine_km( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$earth_km = 6371.0;
		$d_lat    = deg2rad( $lat2 - $lat1 );
		$d_lon    = deg2rad( $lon2 - $lon1 );
		$a        = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lon / 2 ) * sin( $d_lon / 2 );
		$c        = 2 * atan2( sqrt( $a ), sqrt( max( 0.0, 1 - $a ) ) );
		return $earth_km * $c;
	}

	/**
	 * Bbox с карты: проверка, ужатие до max_radius и допустимое смещение от центра города.
	 *
	 * @param array{s?:mixed,w?:mixed,n?:mixed,e?:mixed} $in south/west/north/east.
	 * @return array{s:float,w:float,n:float,e:float}|WP_Error
	 */
	public static function normalize_client_bbox( float $city_lat, float $city_lng, array $in ) {
		$s = isset( $in['s'] ) ? (float) $in['s'] : 0.0;
		$w = isset( $in['w'] ) ? (float) $in['w'] : 0.0;
		$n = isset( $in['n'] ) ? (float) $in['n'] : 0.0;
		$e = isset( $in['e'] ) ? (float) $in['e'] : 0.0;
		if ( $s >= $n || $w >= $e ) {
			return new WP_Error( 'wscosm_bad_bbox', 'Invalid bbox.', [ 'status' => 400 ] );
		}
		$mid_lat = ( $s + $n ) / 2.0;
		$mid_lng = ( $w + $e ) / 2.0;
		$max_r   = self::max_radius_km();
		$offset  = (float) apply_filters( 'wscosm_scan_max_center_offset_km', max( 25.0, $max_r * 4.0 ) );
		$dist    = self::haversine_km( $city_lat, $city_lng, $mid_lat, $mid_lng );
		if ( $dist > $offset ) {
			return new WP_Error(
				'wscosm_bbox_too_far',
				'Viewport is too far from the city center.',
				[ 'status' => 400 ]
			);
		}
		$dlat_max = ( 2.0 * $max_r ) / 111.0;
		$cos      = cos( deg2rad( $mid_lat ) );
		$cos      = max( 0.15, min( 1.0, $cos ) );
		$dlng_max = ( 2.0 * $max_r ) / ( 111.0 * $cos );
		$span_lat = $n - $s;
		$span_lng = $e - $w;
		if ( $span_lat > $dlat_max || $span_lng > $dlng_max ) {
			$scale_lat = $dlat_max / $span_lat;
			$scale_lng = $dlng_max / $span_lng;
			$scale     = min( 1.0, $scale_lat, $scale_lng );
			$h_lat     = ( $span_lat * $scale ) / 2.0;
			$h_lng     = ( $span_lng * $scale ) / 2.0;
			$s         = $mid_lat - $h_lat;
			$n         = $mid_lat + $h_lat;
			$w         = $mid_lng - $h_lng;
			$e         = $mid_lng + $h_lng;
		}
		$s = max( -85.0, min( 85.0, $s ) );
		$n = max( -85.0, min( 85.0, $n ) );
		if ( $s >= $n ) {
			return new WP_Error( 'wscosm_bad_bbox', 'Invalid bbox after clamp.', [ 'status' => 400 ] );
		}
		return [
			's' => $s,
			'w' => $w,
			'n' => $n,
			'e' => $e,
		];
	}

	/**
	 * Собирает Overpass QL.
	 */
	public static function build_query( array $bbox ): string {
		$s = $bbox['s'];
		$w = $bbox['w'];
		$n = $bbox['n'];
		$e = $bbox['e'];
		// Все подзапросы в одном bbox; out geom для линий/полигонов.
		return <<<OQ
[out:json][timeout:60];
(
  node["amenity"="bench"]({$s},{$w},{$n},{$e});
  node["amenity"="waste_basket"]({$s},{$w},{$n},{$e});
  node["highway"="street_lamp"]({$s},{$w},{$n},{$e});
  node["leisure"="playground"]({$s},{$w},{$n},{$e});
  nwr["building"]({$s},{$w},{$n},{$e});
  nwr["building:part"]({$s},{$w},{$n},{$e});
  way["highway"]({$s},{$w},{$n},{$e});
  way["railway"]({$s},{$w},{$n},{$e});
  way["natural"="water"]({$s},{$w},{$n},{$e});
  relation["natural"="water"]({$s},{$w},{$n},{$e});
  way["waterway"]({$s},{$w},{$n},{$e});
  way["barrier"~"^(fence|wall)$"]({$s},{$w},{$n},{$e});
  way["amenity"="parking"]({$s},{$w},{$n},{$e});
  way["landuse"~"^(industrial|railway)$"]({$s},{$w},{$n},{$e});
  relation["landuse"~"^(industrial|railway)$"]({$s},{$w},{$n},{$e});
  way["leisure"="playground"]({$s},{$w},{$n},{$e});
  way["landuse"~"^(grass|meadow|recreation_ground)$"]({$s},{$w},{$n},{$e});
);
out geom;
OQ;
	}

	/**
	 * Запрос к Overpass, декодирование JSON.
	 *
	 * @return array{elements:array}|WP_Error
	 */
	public static function fetch_overpass_json( string $ql ) {
		$url  = self::interpreter_url();
		$resp = wp_remote_post(
			$url,
			[
				'timeout' => 50,
				'body'    => [ 'data' => $ql ],
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wscosm_overpass_http',
				'Overpass HTTP error',
				[ 'status' => $code, 'body' => $body ]
			);
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wscosm_overpass_json', 'Invalid Overpass JSON' );
		}
		return $data;
	}

	/**
	 * Порядок категорий зданий в переключателе слоёв и легенде.
	 *
	 * @return array<int,string>
	 */
	public static function building_kind_order(): array {
		return [
			'bldg_yes',
			'bldg_residential',
			'bldg_commercial',
			'bldg_civic',
			'bldg_cultural',
			'bldg_industrial',
			'bldg_office',
			'bldg_religious',
			'bldg_garage',
			'bldg_agricultural',
			'bldg_transport',
			'bldg_health',
			'bldg_education',
			'bldg_hotel',
			'bldg_sport',
			'bldg_minor',
			'bldg_part',
			'bldg_other',
		];
	}

	/**
	 * Подписи категорий зданий для JS (локализация).
	 *
	 * @return array<string,string>
	 */
	public static function building_kind_labels_for_js(): array {
		return [
			'bldg_yes'         => __( 'Здания: без уточнения типа (yes)', 'worldstat-courtyard-osm' ),
			'bldg_residential' => __( 'Здания: жилая застройка', 'worldstat-courtyard-osm' ),
			'bldg_commercial'  => __( 'Здания: торговля и услуги', 'worldstat-courtyard-osm' ),
			'bldg_civic'       => __( 'Здания: администрация и общество', 'worldstat-courtyard-osm' ),
			'bldg_cultural'    => __( 'Здания: культура и досуг', 'worldstat-courtyard-osm' ),
			'bldg_industrial'  => __( 'Здания: промышленность и склады', 'worldstat-courtyard-osm' ),
			'bldg_office'      => __( 'Здания: офисы', 'worldstat-courtyard-osm' ),
			'bldg_religious'   => __( 'Здания: культовые', 'worldstat-courtyard-osm' ),
			'bldg_garage'      => __( 'Здания: гаражи и навесы', 'worldstat-courtyard-osm' ),
			'bldg_agricultural' => __( 'Здания: сельхоз и теплицы', 'worldstat-courtyard-osm' ),
			'bldg_transport'   => __( 'Здания: транспорт', 'worldstat-courtyard-osm' ),
			'bldg_health'      => __( 'Здания: здравоохранение', 'worldstat-courtyard-osm' ),
			'bldg_education'   => __( 'Здания: образование', 'worldstat-courtyard-osm' ),
			'bldg_hotel'       => __( 'Здания: гостиницы и хостелы', 'worldstat-courtyard-osm' ),
			'bldg_sport'       => __( 'Здания: спорт', 'worldstat-courtyard-osm' ),
			'bldg_minor'       => __( 'Здания: хозпостройки, руины, стройка', 'worldstat-courtyard-osm' ),
			'bldg_part'        => __( 'Части зданий (building:part)', 'worldstat-courtyard-osm' ),
			'bldg_other'       => __( 'Здания: прочие типы', 'worldstat-courtyard-osm' ),
		];
	}

	/**
	 * Значение тега building=* → категория bldg_*.
	 *
	 * @param array<string,mixed> $tags
	 */
	public static function building_kind_from_tags( array $tags ): string {
		$bp = isset( $tags['building:part'] ) ? strtolower( trim( (string) $tags['building:part'] ) ) : '';
		if ( $bp !== '' && $bp !== 'no' ) {
			return 'bldg_part';
		}
		$b = isset( $tags['building'] ) ? strtolower( trim( (string) $tags['building'] ) ) : '';
		if ( $b === '' || $b === 'no' ) {
			return '';
		}
		return self::map_building_tag_value_to_kind( $b );
	}

	/**
	 * @return string Одна из констант bldg_*.
	 */
	private static function map_building_tag_value_to_kind( string $b ): string {
		static $map = null;
		if ( $map === null ) {
			$map = [
				'yes'                  => 'bldg_yes',
				'true'                 => 'bldg_yes',
				'house'                => 'bldg_residential',
				'detached'             => 'bldg_residential',
				'residential'          => 'bldg_residential',
				'apartments'           => 'bldg_residential',
				'terrace'              => 'bldg_residential',
				'terraced_house'       => 'bldg_residential',
				'dormitory'            => 'bldg_residential',
				'bungalow'             => 'bldg_residential',
				'static_caravan'       => 'bldg_residential',
				'cabin'                => 'bldg_residential',
				'semidetached_house'   => 'bldg_residential',
				'houseboat'            => 'bldg_residential',
				'treehouse'            => 'bldg_residential',
				'triplex'              => 'bldg_residential',
				'quadplex'             => 'bldg_residential',
				'commercial'           => 'bldg_commercial',
				'retail'               => 'bldg_commercial',
				'supermarket'          => 'bldg_commercial',
				'shop'                 => 'bldg_commercial',
				'kiosk'                => 'bldg_commercial',
				'marketplace'          => 'bldg_commercial',
				'mall'                 => 'bldg_commercial',
				'civic'                => 'bldg_civic',
				'public'               => 'bldg_civic',
				'government'           => 'bldg_civic',
				'townhall'             => 'bldg_civic',
				'fire_station'         => 'bldg_civic',
				'courthouse'           => 'bldg_civic',
				'community_centre'     => 'bldg_civic',
				'embassy'              => 'bldg_civic',
				'library'              => 'bldg_civic',
				'museum'               => 'bldg_cultural',
				'theatre'              => 'bldg_cultural',
				'theater'              => 'bldg_cultural',
				'cinema'               => 'bldg_cultural',
				'arts_centre'          => 'bldg_cultural',
				'industrial'           => 'bldg_industrial',
				'warehouse'            => 'bldg_industrial',
				'factory'              => 'bldg_industrial',
				'manufacture'          => 'bldg_industrial',
				'plant'                => 'bldg_industrial',
				'digester'             => 'bldg_industrial',
				'works'                => 'bldg_industrial',
				'hangar'               => 'bldg_industrial',
				'office'               => 'bldg_office',
				'church'               => 'bldg_religious',
				'cathedral'            => 'bldg_religious',
				'chapel'               => 'bldg_religious',
				'mosque'               => 'bldg_religious',
				'temple'               => 'bldg_religious',
				'shrine'               => 'bldg_religious',
				'monastery'            => 'bldg_religious',
				'synagogue'            => 'bldg_religious',
				'religious'            => 'bldg_religious',
				'garage'               => 'bldg_garage',
				'garages'              => 'bldg_garage',
				'carport'              => 'bldg_garage',
				'farm'                 => 'bldg_agricultural',
				'barn'                 => 'bldg_agricultural',
				'cowshed'              => 'bldg_agricultural',
				'stable'               => 'bldg_agricultural',
				'farm_auxiliary'       => 'bldg_agricultural',
				'silo'                 => 'bldg_agricultural',
				'greenhouse'           => 'bldg_agricultural',
				'agricultural'         => 'bldg_agricultural',
				'train_station'        => 'bldg_transport',
				'station'              => 'bldg_transport',
				'transportation'       => 'bldg_transport',
				'bridge'               => 'bldg_transport',
				'hospital'             => 'bldg_health',
				'clinic'               => 'bldg_health',
				'school'               => 'bldg_education',
				'kindergarten'         => 'bldg_education',
				'university'           => 'bldg_education',
				'college'              => 'bldg_education',
				'hotel'                => 'bldg_hotel',
				'motel'                => 'bldg_hotel',
				'guest_house'          => 'bldg_hotel',
				'hostel'               => 'bldg_hotel',
				'stadium'              => 'bldg_sport',
				'sports_centre'        => 'bldg_sport',
				'sports_hall'          => 'bldg_sport',
				'shed'                 => 'bldg_minor',
				'hut'                  => 'bldg_minor',
				'roof'                 => 'bldg_minor',
				'construction'         => 'bldg_minor',
				'ruins'                => 'bldg_minor',
				'ruin'                 => 'bldg_minor',
				'abandoned'            => 'bldg_minor',
				'collapsed'            => 'bldg_minor',
			];
		}
		return isset( $map[ $b ] ) ? $map[ $b ] : 'bldg_other';
	}

	/**
	 * Классификация элемента → kind для стиля на карте.
	 *
	 * @param array<string,mixed> $el
	 */
	public static function classify_element( array $el ): string {
		$tags = isset( $el['tags'] ) && is_array( $el['tags'] ) ? $el['tags'] : [];
		$t    = $el['type'] ?? '';
		if ( $t === 'node' ) {
			if ( ( $tags['amenity'] ?? '' ) === 'bench' ) {
				return 'bench';
			}
			if ( ( $tags['amenity'] ?? '' ) === 'waste_basket' ) {
				return 'waste_basket';
			}
			if ( ( $tags['highway'] ?? '' ) === 'street_lamp' ) {
				return 'light';
			}
			if ( ( $tags['leisure'] ?? '' ) === 'playground' ) {
				return 'playground';
			}
			$bk = self::building_kind_from_tags( $tags );
			if ( $bk !== '' ) {
				return $bk;
			}
		}
		if ( $t === 'relation' ) {
			$bk = self::building_kind_from_tags( $tags );
			if ( $bk !== '' ) {
				return $bk;
			}
			if ( ( $tags['natural'] ?? '' ) === 'water' ) {
				return 'water';
			}
			$lu = (string) ( $tags['landuse'] ?? '' );
			if ( $lu === 'industrial' ) {
				return 'landuse_industrial';
			}
			if ( $lu === 'railway' ) {
				return 'landuse_railway';
			}
		}
		if ( $t === 'way' ) {
			$bk = self::building_kind_from_tags( $tags );
			if ( $bk !== '' ) {
				return $bk;
			}
			$hw = (string) ( $tags['highway'] ?? '' );
			if ( $hw !== '' ) {
				if ( preg_match( '/^(footway|path|pedestrian|steps|cycleway)$/', $hw ) ) {
					return 'path';
				}
				return 'road';
			}
			if ( (string) ( $tags['railway'] ?? '' ) !== '' ) {
				return 'railway';
			}
			if ( ( $tags['natural'] ?? '' ) === 'water' || (string) ( $tags['waterway'] ?? '' ) !== '' ) {
				return 'water';
			}
			$barrier = (string) ( $tags['barrier'] ?? '' );
			if ( $barrier !== '' && preg_match( '/^(fence|wall)$/', $barrier ) ) {
				return 'barrier';
			}
			if ( ( $tags['amenity'] ?? '' ) === 'parking' ) {
				return 'parking';
			}
			if ( ( $tags['leisure'] ?? '' ) === 'playground' ) {
				return 'playground';
			}
			$lu = (string) ( $tags['landuse'] ?? '' );
			if ( $lu === 'industrial' ) {
				return 'landuse_industrial';
			}
			if ( $lu === 'railway' ) {
				return 'landuse_railway';
			}
			if ( $lu !== '' && preg_match( '/^(grass|meadow|recreation_ground)$/', $lu ) ) {
				return 'landuse_green';
			}
		}
		return 'other';
	}

	/**
	 * Преобразование Overpass JSON → GeoJSON FeatureCollection.
	 *
	 * @param array<string,mixed> $data
	 * @return array{type:string,features:array}
	 */
	public static function overpass_to_geojson( array $data ): array {
		$features = [];
		$elements  = isset( $data['elements'] ) && is_array( $data['elements'] ) ? $data['elements'] : [];

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$kind = self::classify_element( $el );
			if ( $kind === 'other' ) {
				continue;
			}
			$tags = isset( $el['tags'] ) && is_array( $el['tags'] ) ? $el['tags'] : [];
			$geom = self::element_to_geometry( $el );
			if ( $geom === null ) {
				continue;
			}
			$features[] = [
				'type'       => 'Feature',
				'geometry'   => $geom,
				'properties' => array_merge(
					[
						'wscosm_kind'        => $kind,
						'name'               => (string) ( $tags['name'] ?? $tags['ref'] ?? '' ),
						'wscosm_osm_el_type' => (string) ( $el['type'] ?? '' ),
						'wscosm_osm_id'      => isset( $el['id'] ) ? (int) $el['id'] : 0,
					],
					self::flatten_tags_for_popup( $tags )
				),
			];
		}

		$features = self::cap_building_features( $features );

		return [
			'type'     => 'FeatureCollection',
			'features' => $features,
		];
	}

	/**
	 * Ограничение числа зданий OSM (плотная застройка перегружает Overpass и карту).
	 *
	 * @param array<int,array<string,mixed>> $features Список Feature.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cap_building_features( array $features ): array {
		$max = (int) apply_filters( 'wscosm_max_osm_buildings', 20000 );
		if ( $max <= 0 ) {
			$max = 50000;
		}
		$max = max( 500, min( 50000, $max ) );
		$out = [];
		$n_b = 0;
		foreach ( $features as $f ) {
			$kind = '';
			if ( isset( $f['properties'] ) && is_array( $f['properties'] ) ) {
				$kind = (string) ( $f['properties']['wscosm_kind'] ?? '' );
			}
			if ( strncmp( $kind, 'bldg_', 5 ) === 0 ) {
				if ( $n_b >= $max ) {
					continue;
				}
				++$n_b;
			}
			$out[] = $f;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $tags
	 * @return array<string,string>
	 */
	private static function flatten_tags_for_popup( array $tags ): array {
		$out = [];
		foreach ( $tags as $k => $v ) {
			if ( is_string( $k ) && ( is_string( $v ) || is_numeric( $v ) ) ) {
				$out[ 'tag_' . $k ] = (string) $v;
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $geom Узлы Overpass geometry.
	 * @return array<int,array{0:float,1:float}>
	 */
	private static function geometry_nodes_to_lonlat( array $geom ): array {
		$coords = [];
		foreach ( $geom as $pt ) {
			if ( ! is_array( $pt ) ) {
				continue;
			}
			$la = isset( $pt['lat'] ) ? (float) $pt['lat'] : null;
			$lo = isset( $pt['lon'] ) ? (float) $pt['lon'] : null;
			if ( $la === null || $lo === null ) {
				continue;
			}
			$coords[] = [ $lo, $la ];
		}
		return $coords;
	}

	/**
	 * Замыкает кольцо для полигона здания / зелёной зоны.
	 *
	 * @param array<int,array{0:float,1:float}> $coords
	 * @return array<int,array{0:float,1:float}>
	 */
	private static function close_coords_ring_if_needed( array $coords ): array {
		if ( count( $coords ) < 3 ) {
			return $coords;
		}
		$first = $coords[0];
		$last  = $coords[ count( $coords ) - 1 ];
		if ( $first[0] !== $last[0] || $first[1] !== $last[1] ) {
			$coords[] = $first;
		}
		return $coords;
	}

	/**
	 * @param array<string,mixed> $el relation из Overpass (out geom).
	 */
	private static function relation_to_geometry( array $el ): ?array {
		if ( ( $el['type'] ?? '' ) !== 'relation' ) {
			return null;
		}
		$members = $el['members'] ?? [];
		if ( ! is_array( $members ) ) {
			return null;
		}
		$outer_rings = [];
		foreach ( $members as $m ) {
			if ( ! is_array( $m ) || ( $m['type'] ?? '' ) !== 'way' ) {
				continue;
			}
			$role = strtolower( (string) ( $m['role'] ?? '' ) );
			if ( $role === 'inner' ) {
				continue;
			}
			$g = $m['geometry'] ?? null;
			if ( ! is_array( $g ) || count( $g ) < 2 ) {
				continue;
			}
			$coords = self::geometry_nodes_to_lonlat( $g );
			$coords = self::close_coords_ring_if_needed( $coords );
			if ( count( $coords ) >= 4 ) {
				$f = $coords[0];
				$l = $coords[ count( $coords ) - 1 ];
				if ( $f[0] === $l[0] && $f[1] === $l[1] ) {
					$outer_rings[] = $coords;
				}
			}
		}
		if ( empty( $outer_rings ) ) {
			return null;
		}
		if ( count( $outer_rings ) === 1 ) {
			return [
				'type'        => 'Polygon',
				'coordinates' => [ $outer_rings[0] ],
			];
		}
		$polys = [];
		foreach ( $outer_rings as $ring ) {
			$polys[] = [ $ring ];
		}
		return [
			'type'        => 'MultiPolygon',
			'coordinates' => $polys,
		];
	}

	private static function element_to_geometry( array $el ): ?array {
		$type = $el['type'] ?? '';
		if ( $type === 'node' ) {
			$lat = isset( $el['lat'] ) ? (float) $el['lat'] : 0.0;
			$lon = isset( $el['lon'] ) ? (float) $el['lon'] : 0.0;
			if ( ! $lat && ! $lon ) {
				return null;
			}
			return [
				'type'        => 'Point',
				'coordinates' => [ $lon, $lat ],
			];
		}
		if ( $type === 'relation' ) {
			return self::relation_to_geometry( $el );
		}
		if ( $type === 'way' && ! empty( $el['geometry'] ) && is_array( $el['geometry'] ) ) {
			$coords = self::geometry_nodes_to_lonlat( $el['geometry'] );
			if ( count( $coords ) < 2 ) {
				return null;
			}
			$kind = self::classify_element( $el );
			$as_poly = (
				$kind === 'landuse_green'
				|| $kind === 'playground'
				|| $kind === 'water'
				|| $kind === 'parking'
				|| $kind === 'landuse_industrial'
				|| $kind === 'landuse_railway'
				|| strncmp( $kind, 'bldg_', 5 ) === 0
			);
			if ( $as_poly ) {
				$coords = self::close_coords_ring_if_needed( $coords );
				$first  = $coords[0];
				$last   = $coords[ count( $coords ) - 1 ];
				if ( $first[0] === $last[0] && $first[1] === $last[1] && count( $coords ) >= 4 ) {
					return [
						'type'        => 'Polygon',
						'coordinates' => [ $coords ],
					];
				}
			}
			return [
				'type'        => 'LineString',
				'coordinates' => $coords,
			];
		}
		return null;
	}

	/**
	 * Полный цикл: bbox → Overpass → GeoJSON (кэш transients отключён).
	 *
	 * @param array $bbox Bounding box south/west/north/east.
	 * @return array{type:string,features:array}|WP_Error
	 */
	public static function get_features_for_bbox( array $bbox ) {
		$ql   = self::build_query( $bbox );
		$data = self::fetch_overpass_json( $ql );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::overpass_to_geojson( $data );
	}
}
