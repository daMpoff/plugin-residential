<?php
/**
 * Вкладка страны «Придомовые территории»: выбор города, карта, график.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Country_Tab {

	/**
	 * Контент вкладки (после AJAX подменяет placeholder).
	 *
	 * @param string $iso2 Код страны ISO2.
	 */
	public static function render_tab_shell( string $iso2 ): void {
		$iso2 = strtoupper( sanitize_text_field( $iso2 ) );
		echo '<div class="wscosm-country-tab-shell" data-iso2="' . esc_attr( $iso2 ) . '">';
		echo '<h3 class="wsp-section-title">' . esc_html__( 'Придомовые территории по городу', 'worldstat-courtyard-osm' ) . '</h3>';
		echo '<p class="description" style="margin-bottom:1rem;">' . esc_html__(
			'Выберите город страны — на карте появятся импортированные придомовые участки и объекты OpenStreetMap; ниже — средние оценки по шести измерениям (по данным участков в базе).',
			'worldstat-courtyard-osm'
		) . '</p>';

		if ( ! class_exists( 'WSCities_CPT' ) ) {
			echo '<p class="wsp-muted">' . esc_html__( 'Модуль городов недоступен.', 'worldstat-courtyard-osm' ) . '</p></div>';
			return;
		}

		$cities = WSCities_CPT::get_cities_for_country( $iso2 );
		if ( empty( $cities ) ) {
			echo '<p class="wsp-muted">' . esc_html__( 'Для этой страны в базе нет городов.', 'worldstat-courtyard-osm' ) . '</p></div>';
			return;
		}

		echo '<label class="wscosm-ct-label" for="wscosm-ct-city">' . esc_html__( 'Город', 'worldstat-courtyard-osm' ) . '</label> ';
		echo '<select id="wscosm-ct-city" class="wscosm-ct-city-select" style="min-width:16rem;max-width:100%;">';
		echo '<option value="">' . esc_html__( '— выберите город —', 'worldstat-courtyard-osm' ) . '</option>';
		foreach ( $cities as $c ) {
			echo '<option value="' . esc_attr( (string) (int) $c['id'] ) . '">' . esc_html( (string) ( $c['name'] ?? '' ) ) . '</option>';
		}
		echo '</select>';

		echo '<div id="wscosm-ct-detail" class="wscosm-ct-detail" style="margin-top:1.25rem;"></div>';
		echo '</div>';
	}

	/**
	 * AJAX: данные для выбранного города (карта + график).
	 */
	public static function ajax_city_yards(): void {
		check_ajax_referer( 'wscosm_ct', 'nonce' );

		$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
		$iso2    = isset( $_POST['iso2'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['iso2'] ) ) ) : '';
		if ( $city_id <= 0 || strlen( $iso2 ) !== 2 || ! class_exists( 'WSCities_CPT' ) ) {
			wp_send_json_error( [ 'message' => 'bad_request' ] );
		}

		$post = get_post( $city_id );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG || $post->post_status !== 'publish' ) {
			wp_send_json_error( [ 'message' => 'not_found' ] );
		}

		$city_iso = strtoupper( (string) get_post_meta( $city_id, 'wscity_country_iso2', true ) );
		if ( $city_iso !== $iso2 ) {
			wp_send_json_error( [ 'message' => 'country_mismatch' ] );
		}

		$lat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		if ( ! $lat || ! $lng ) {
			wp_send_json_error( [ 'message' => 'no_coords' ] );
		}

		$yards_count = class_exists( 'WSErgo_Data' ) ? WSErgo_Data::count_city_building_yards( $city_id ) : 0;
		$yards_url   = ( $yards_count > 0 && class_exists( 'WSErgo_Data' ) )
			? WSErgo_Data::get_city_yards_geojson_rest_url( $city_id )
			: '';
		$features_url = add_query_arg(
			'source',
			'local',
			rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/features' )
		);
		$osm_count      = class_exists( 'WSCOSM_Feature_Store' ) ? WSCOSM_Feature_Store::count_for_city( $city_id ) : 0;
		$yard_ergo_url  = rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/yard-ergo-at' );

		$means  = self::get_city_yard_dimension_means( $city_id, 250 );
		$chart  = ! empty( $means ) ? self::build_chart_payload( $means ) : [];

		wp_send_json_success(
			[
				'cityId'      => $city_id,
				'cityName'    => get_the_title( $city_id ),
				'lat'         => $lat,
				'lng'         => $lng,
				'zoom'        => 14,
				'yardsCount'      => $yards_count,
				'osmObjectsCount' => $osm_count,
				'yardsUrl'        => $yards_url,
				'featuresUrl'     => $features_url,
				'yardErgoAtUrl'   => $yard_ergo_url,
				'canScanOsm'      => class_exists( 'WSCOSM_REST' ) ? WSCOSM_REST::can_live_overpass( $city_id ) : false,
				'tileUrl'     => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
				'tileAttrib'  => '&copy; OpenStreetMap &copy; CARTO',
				'chart'       => $chart,
			]
		);
	}

	/**
	 * Средние по шести измерениям для придомовых города.
	 *
	 * @return array<string, float|null>
	 */
	private static function get_city_yard_dimension_means( int $city_id, int $limit ): array {
		if ( ! class_exists( 'WSErgo_Model' ) || ! class_exists( 'WSErgo_CPT' ) || $limit < 1 ) {
			return [];
		}

		$means = [];
		foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
			$means[ $dim ] = null;
		}

		$city_key   = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_CITY_ID : 'wsosm_city_id';
		$entity_key = class_exists( 'WSOSM_Writer' ) ? WSOSM_Writer::META_ENTITY_TYPE : 'wsosm_entity_type';

		$q = new WP_Query(
			[
				'post_type'              => WSErgo_CPT::SLUG_YARD,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_term_meta_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => $city_key,
						'value' => $city_id,
					],
					[
						'key'   => $entity_key,
						'value' => 'building',
					],
				],
			]
		);

		$ids = array_map( 'intval', (array) $q->posts );
		if ( empty( $ids ) ) {
			return $means;
		}

		$sums = array_fill_keys( WSErgo_Model::DIMENSION_KEYS, 0.0 );
		$cnts = array_fill_keys( WSErgo_Model::DIMENSION_KEYS, 0 );

		foreach ( $ids as $pid ) {
			$scores = WSErgo_Model::get_scores_from_post( $pid );
			foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
				$v = isset( $scores[ $dim ] ) ? (float) $scores[ $dim ] : 0.0;
				if ( $v > 0 ) {
					$sums[ $dim ] += $v;
					++$cnts[ $dim ];
				}
			}
		}

		foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
			if ( $cnts[ $dim ] > 0 ) {
				$means[ $dim ] = round( $sums[ $dim ] / $cnts[ $dim ], 2 );
			}
		}

		return $means;
	}

	/**
	 * Конфиг для WSPChart.render (как в chart.php).
	 *
	 * @param array<string, float|null> $means Ключи измерений.
	 * @return array<string, mixed>
	 */
	private static function build_chart_payload( array $means ): array {
		if ( ! class_exists( 'WSErgo_Model' ) ) {
			return [];
		}
		$labels = WSErgo_Model::get_dimension_labels();
		$labs   = [];
		$data   = [];
		foreach ( WSErgo_Model::DIMENSION_KEYS as $dim ) {
			$labs[] = isset( $labels[ $dim ] ) ? $labels[ $dim ] : $dim;
			$v      = $means[ $dim ] ?? null;
			$data[] = ( $v !== null && $v > 0 ) ? (float) $v : 0.0;
		}

		return [
			'type'     => 'bar',
			'title'    => __( 'Средние баллы по придомовым (0–100)', 'worldstat-courtyard-osm' ),
			'labels'   => $labs,
			'datasets' => [
				[
					'label' => __( 'Среднее по участкам', 'worldstat-courtyard-osm' ),
					'data'  => $data,
					'color' => '#15803d',
				],
			],
			'xLabel' => '',
			'yLabel' => '',
		];
	}

	/**
	 * Скрипты страницы страны (Leaflet уже в платформе).
	 */
	public static function enqueue_country_assets(): void {
		if ( ! class_exists( 'WorldStat_Country_CPT' ) || ! is_singular( WorldStat_Country_CPT::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wscosm-city-map',
			WSCOSM_URL . 'assets/css/city-osm-map.css',
			[],
			WSCOSM_VERSION
		);

		wp_enqueue_script(
			'wscosm-country-tab',
			WSCOSM_URL . 'assets/js/country-tab-yards.js',
			[ 'jquery', 'leaflet', 'chartjs', 'worldstat-chart-builder' ],
			WSCOSM_VERSION,
			true
		);

		wp_localize_script(
			'wscosm-country-tab',
			'wscosmCountryTab',
			[
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'wscosm_ct' ),
				'buildingKindOrder'  => class_exists( 'WSCOSM_Overpass' ) ? WSCOSM_Overpass::building_kind_order() : [],
				'buildingKindLabels' => class_exists( 'WSCOSM_Overpass' ) ? WSCOSM_Overpass::building_kind_labels_for_js() : [],
				'scanProgressUrl'    => rest_url( WSCOSM_REST::NS . '/scan-progress' ),
				'i18n'               => [
					'loading'         => __( 'Загрузка…', 'worldstat-courtyard-osm' ),
					'error'           => __( 'Не удалось загрузить данные.', 'worldstat-courtyard-osm' ),
					'yards'           => __( 'Придомовых участков в базе', 'worldstat-courtyard-osm' ),
					'noYards'         => __( 'Нет импортированных придомовых для этого города.', 'worldstat-courtyard-osm' ),
					'osmObjects'      => __( 'Объектов OSM сохранено в базе сайта', 'worldstat-courtyard-osm' ),
					'ergoLoading'     => __( 'Загрузка эргономики…', 'worldstat-courtyard-osm' ),
					'ergoError'       => __( 'Не удалось загрузить эргономику.', 'worldstat-courtyard-osm' ),
					'ergoTitle'       => __( 'Эргономика (придомовый участок)', 'worldstat-courtyard-osm' ),
					'mapTitle'        => __( 'Карта', 'worldstat-courtyard-osm' ),
					'legendBuildings' => __( 'Типы построек (OSM)', 'worldstat-courtyard-osm' ),
					'scanOsm'         => __( 'Сканировать', 'worldstat-courtyard-osm' ),
					'scanOsmHint'     => __( 'Повторно запросить OSM для видимой области карты (без кэша)', 'worldstat-courtyard-osm' ),
					'scanOsmError'    => __(
						'Не удалось загрузить OSM для этого вида. Уменьшите масштаб или приблизьте карту к центру города.',
						'worldstat-courtyard-osm'
					),
					'scanProgressOverpass' => __( 'Запрос к OpenStreetMap…', 'worldstat-courtyard-osm' ),
					'scanProgressSaving'  => __( 'Сохранение в базу', 'worldstat-courtyard-osm' ),
					'scanProgressDone'    => __( 'Готово', 'worldstat-courtyard-osm' ),
					'scanProgressError'   => __( 'Ошибка сохранения', 'worldstat-courtyard-osm' ),
					'scanProgressCounts'  => __( 'Записей в базу', 'worldstat-courtyard-osm' ),
					'layerYards'      => __( 'Придомовые (база сайта)', 'worldstat-courtyard-osm' ),
					'layerBench'      => __( 'Скамейки', 'worldstat-courtyard-osm' ),
					'layerLight'      => __( 'Фонари', 'worldstat-courtyard-osm' ),
					'layerPath'       => __( 'Пешеходные пути', 'worldstat-courtyard-osm' ),
					'layerPlay'       => __( 'Площадки', 'worldstat-courtyard-osm' ),
					'layerBin'        => __( 'Урны', 'worldstat-courtyard-osm' ),
					'layerGreen'      => __( 'Зелёные зоны', 'worldstat-courtyard-osm' ),
					'layerCenter'     => __( 'Центр города', 'worldstat-courtyard-osm' ),
				],
			]
		);
	}
}
