<?php
/**
 * Карта города: обогащение WorldStat_UI::map и легенда.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_City_Map {

	/** @var bool Легенда уже выведена под основной картой. */
	private static bool $legend_printed = false;

	/**
	 * Только стили легенды (Leaflet подключает мини-карта платформы).
	 */
	public static function enqueue_assets(): void {
		if ( ! class_exists( 'WSCities_CPT' ) || ! is_singular( WSCities_CPT::SLUG ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$lat = (float) get_post_meta( $post_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $post_id, 'wscity_lng', true );
		if ( ! $lat || ! $lng ) {
			return;
		}

		wp_enqueue_style(
			'wscosm-city-map',
			WSCOSM_URL . 'assets/css/city-osm-map.css',
			[],
			WSCOSM_VERSION
		);
	}

	/**
	 * Полигоны придомовых + маркеры OSM для центра карты города.
	 *
	 * @param array<string,mixed> $opts Опции WorldStat_UI::map() (lat/lng центра).
	 * @return array<string,mixed>
	 */
	public static function enrich_map_opts_for_city( int $city_id, array $opts ): array {
		if ( $city_id <= 0 || ! class_exists( 'WSCities_CPT' ) ) {
			return $opts;
		}

		$clat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$clng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		if ( ! $clat || ! $clng ) {
			return $opts;
		}

		if ( class_exists( 'WSErgo_Data' ) ) {
			$n = WSErgo_Data::count_city_building_yards( $city_id );
			if ( $n > 0 ) {
				$threshold = (int) apply_filters( 'wsergo_lazy_yards_threshold', 200 );
				$geo_url   = '';
				$features  = [];
				if ( $n >= $threshold ) {
					$geo_url = WSErgo_Data::get_city_yards_geojson_rest_url( $city_id );
				} else {
					$features = WSErgo_Data::get_city_building_polygons( $city_id );
				}
				if ( $geo_url !== '' || ! empty( $features ) ) {
					$opts['polygon_layers']   = isset( $opts['polygon_layers'] ) && is_array( $opts['polygon_layers'] ) ? $opts['polygon_layers'] : [];
					$opts['polygon_layers'][] = [
						'label'         => __( 'Придомовые (здания)', 'worldstat-courtyard-osm' ),
						'color_scale'   => [ '#dc2626', '#facc15', '#16a34a' ],
						'value_key'     => 'index',
						'value_min'     => 0,
						'value_max'     => 100,
						'stroke'        => '#1e293b',
						'stroke_weight' => 0.6,
						'fill_opacity'  => 0.5,
						'empty_color'   => '#cbd5e1',
						'features'      => $features,
						'geojson_url'   => $geo_url,
					];
				}
			}
		}

		if ( class_exists( 'WSCOSM_Overpass' ) && class_exists( 'WSCOSM_Feature_Store' ) ) {
			$bbox = WSCOSM_Overpass::bbox_from_center( $clat, $clng, WSCOSM_Overpass::default_radius_km() );
			$fc   = WSCOSM_Feature_Store::get_feature_collection_for_bbox( $city_id, $bbox );
			if ( ! empty( $fc['features'] ) && is_array( $fc['features'] ) ) {
				$osm_markers = self::osm_features_to_minimap_markers( $fc['features'], 450 );
				if ( ! empty( $osm_markers ) ) {
					$opts['markers'] = array_merge( $opts['markers'] ?? [], $osm_markers );
				}
			}
		}

		$opts['layer_control'] = true;
		unset( $opts['_wscosm_embed_city_id'] );

		return $opts;
	}

	/**
	 * Фильтр мини-карты: страница города или встраивание по _wscosm_embed_city_id.
	 *
	 * @param array<string,mixed> $opts Опции WorldStat_UI::map().
	 * @return array<string,mixed>
	 */
	public static function filter_worldstat_ui_map_opts( array $opts ): array {
		$embed = (int) ( $opts['_wscosm_embed_city_id'] ?? 0 );
		if ( $embed > 0 && class_exists( 'WSCities_CPT' ) && get_post_type( $embed ) === WSCities_CPT::SLUG ) {
			unset( $opts['_wscosm_embed_city_id'] );
			return self::enrich_map_opts_for_city( $embed, $opts );
		}

		if ( ! class_exists( 'WSCities_CPT' ) || ! is_singular( WSCities_CPT::SLUG ) ) {
			return $opts;
		}

		$post_id = get_queried_object_id();
		if ( $post_id <= 0 || get_post_type( $post_id ) !== WSCities_CPT::SLUG ) {
			return $opts;
		}

		$clat = (float) get_post_meta( $post_id, 'wscity_lat', true );
		$clng = (float) get_post_meta( $post_id, 'wscity_lng', true );
		if ( ! $clat || ! $clng ) {
			return $opts;
		}

		if ( abs( (float) ( $opts['lat'] ?? 0 ) - $clat ) > 0.2 || abs( (float) ( $opts['lng'] ?? 0 ) - $clng ) > 0.2 ) {
			return $opts;
		}

		return self::enrich_map_opts_for_city( $post_id, $opts );
	}

	/**
	 * GeoJSON features → маркеры мини-карты (точки и середина линий путей).
	 *
	 * @param array<int,array<string,mixed>> $features Элементы Feature.
	 * @return array<int,array<string,mixed>>
	 */
	private static function osm_features_to_minimap_markers( array $features, int $limit ): array {
		$out   = [];
		$limit = max( 50, min( 600, $limit ) );

		foreach ( $features as $feat ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! is_array( $feat ) || ( $feat['type'] ?? '' ) !== 'Feature' ) {
				continue;
			}
			$geom = $feat['geometry'] ?? null;
			$props = isset( $feat['properties'] ) && is_array( $feat['properties'] ) ? $feat['properties'] : [];
			$kind  = (string) ( $props['wscosm_kind'] ?? 'other' );
			if ( ! is_array( $geom ) ) {
				continue;
			}
			$gtype = (string) ( $geom['type'] ?? '' );
			$pair  = null;

			if ( $gtype === 'Point' && ! empty( $geom['coordinates'] ) && is_array( $geom['coordinates'] ) ) {
				$lon = (float) ( $geom['coordinates'][0] ?? 0 );
				$la  = (float) ( $geom['coordinates'][1] ?? 0 );
				if ( $la && $lon ) {
					$pair = [ $la, $lon ];
				}
			} elseif ( $gtype === 'LineString' && ! empty( $geom['coordinates'] ) && is_array( $geom['coordinates'] ) ) {
				$coords = $geom['coordinates'];
				$mid    = (int) floor( count( $coords ) / 2 );
				$lon    = (float) ( $coords[ $mid ][0] ?? 0 );
				$la     = (float) ( $coords[ $mid ][1] ?? 0 );
				if ( $la && $lon ) {
					$pair = [ $la, $lon ];
				}
			}

			if ( $pair === null ) {
				continue;
			}

			$labels = [
				'bench'         => __( 'Скамейка (OSM)', 'worldstat-courtyard-osm' ),
				'light'         => __( 'Фонарь (OSM)', 'worldstat-courtyard-osm' ),
				'path'          => __( 'Пешеходный путь (OSM)', 'worldstat-courtyard-osm' ),
				'playground'    => __( 'Площадка (OSM)', 'worldstat-courtyard-osm' ),
				'waste_basket'  => __( 'Урна (OSM)', 'worldstat-courtyard-osm' ),
				'landuse_green' => __( 'Зелёная зона (OSM)', 'worldstat-courtyard-osm' ),
			];
			$title = $labels[ $kind ] ?? __( 'Объект OSM', 'worldstat-courtyard-osm' );
			$popup = self::osm_props_to_popup_text( $props );

			$out[] = [
				'lat'    => $pair[0],
				'lng'    => $pair[1],
				'title'  => $title,
				'popup'  => $popup,
				'color'  => self::osm_marker_color( $kind ),
				'radius' => $kind === 'playground' ? 6 : 5,
			];
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $props Свойства feature.
	 */
	private static function osm_props_to_popup_text( array $props ): string {
		$lines = [];
		$kind  = (string) ( $props['wscosm_kind'] ?? '' );
		if ( $kind !== '' ) {
			$lines[] = __( 'Тип:', 'worldstat-courtyard-osm' ) . ' ' . $kind;
		}
		$name = (string) ( $props['name'] ?? '' );
		if ( $name !== '' ) {
			$lines[] = $name;
		}
		foreach ( $props as $k => $v ) {
			if ( strpos( (string) $k, 'tag_' ) !== 0 || is_array( $v ) ) {
				continue;
			}
			$short = substr( (string) $k, 4 );
			$lines[] = $short . ': ' . (string) $v;
			if ( count( $lines ) >= 10 ) {
				break;
			}
		}
		return implode( ' · ', array_slice( $lines, 0, 10 ) );
	}

	private static function osm_marker_color( string $kind ): string {
		switch ( $kind ) {
			case 'bench':
				return '#92400e';
			case 'light':
				return '#ca8a04';
			case 'path':
				return '#1d4ed8';
			case 'playground':
				return '#db2777';
			case 'waste_basket':
				return '#475569';
			case 'landuse_green':
				return '#15803d';
			default:
				return '#64748b';
		}
	}

	/**
	 * Легенда сразу под основной картой города.
	 *
	 * @param int   $post_id ID города.
	 * @param array $meta    Мета без префикса wscity_.
	 */
	public static function render_legend_after_location_map( int $post_id, array $meta ): void {
		unset( $post_id );
		$lat = (float) ( $meta['lat'] ?? 0 );
		$lng = (float) ( $meta['lng'] ?? 0 );
		if ( ! $lat || ! $lng ) {
			return;
		}

		echo '<div class="wscosm-legend-wrap" style="margin-top:1rem;">';
		echo '<p class="description" style="margin:0 0 0.75rem;">' . esc_html__(
			'На карте выше: придомовые участки из базы сайта (полигоны) и объекты OpenStreetMap (маркеры; линии путей показаны маркером в середине).',
			'worldstat-courtyard-osm'
		) . '</p>';
		self::render_dimension_legend();
		echo '</div>';

		self::$legend_printed = true;
	}

	/**
	 * Резерв: если тема/шаблон города без хука wsp_city_after_location_map — легенда внизу страницы.
	 *
	 * @param int   $post_id ID города.
	 * @param array $meta    Мета без префикса wscity_.
	 */
	public static function render_section( int $post_id, array $meta ): void {
		unset( $post_id );
		if ( self::$legend_printed ) {
			return;
		}
		$lat = (float) ( $meta['lat'] ?? 0 );
		$lng = (float) ( $meta['lng'] ?? 0 );
		if ( ! $lat || ! $lng ) {
			return;
		}

		echo '<div class="wscosm-city-section wsp-container" style="margin-top:2rem;padding-top:2rem;border-top:1px solid var(--wsp-border,#e5e7eb);">';
		echo '<h2 class="wsp-section-title">' . esc_html__( 'Придомовая среда и OpenStreetMap', 'worldstat-courtyard-osm' ) . '</h2>';
		self::render_dimension_legend();
		echo '</div>';
	}

	/**
	 * Легенда: 6 измерений эргономики и подсказки по слоям OSM.
	 */
	public static function render_dimension_legend(): void {
		if ( ! class_exists( 'WSErgo_Model' ) ) {
			return;
		}

		$labels = WSErgo_Model::get_dimension_labels();
		$hints  = self::dimension_osm_hints();

		echo '<div class="wscosm-dim-legend">';
		echo '<h3 class="wsp-section-title" style="font-size:1.05rem;">' . esc_html__(
			'Подсказки для оценки по шести измерениям (методика базового плагина)',
			'worldstat-courtyard-osm'
		) . '</h3>';
		echo '<ul class="wscosm-dim-legend__list">';

		foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
			$title = isset( $labels[ $dim ] ) ? $labels[ $dim ] : $dim;
			$text  = isset( $hints[ $dim ] ) ? $hints[ $dim ] : '';
			echo '<li class="wscosm-dim-legend__item"><strong>' . esc_html( $title ) . '</strong> — ' . esc_html( $text ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * @return array<string,string>
	 */
	private static function dimension_osm_hints(): array {
		return [
			WSErgo_Model::DIM_FUNCTIONALITY => __( 'плотность и связность пешеходных путей (линии footway/path), доступ к зонам отдыха.', 'worldstat-courtyard-osm' ),
			WSErgo_Model::DIM_SAFETY        => __( 'освещение участков (фонари), обзорность зелёных и открытых зон.', 'worldstat-courtyard-osm' ),
			WSErgo_Model::DIM_COMFORT       => __( 'малые формы: скамейки, урны, детские площадки; качество среды поблизости.', 'worldstat-courtyard-osm' ),
			WSErgo_Model::DIM_LIVABILITY    => __( 'сочетание зелёных landuse и инфраструктуры двора (вместе с данными импорта в базе).', 'worldstat-courtyard-osm' ),
			WSErgo_Model::DIM_MASTERABILITY => __( 'читаемость среды: сеть дорожек, ориентиры, логика размещения объектов OSM.', 'worldstat-courtyard-osm' ),
			WSErgo_Model::DIM_MANAGEABILITY => __( 'наличие объектов ухода за средой (урны и т.п.) как косвенный признак поддержания территории.', 'worldstat-courtyard-osm' ),
		];
	}
}
