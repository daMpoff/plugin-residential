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
		$lat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		$seed = self::city_seed_meta_by_title( (string) get_the_title( $city_id ) );
		if ( is_array( $seed ) ) {
			$seed_iso = strtoupper( (string) ( $seed['iso2'] ?? '' ) );
			if ( $seed_iso !== '' && ( $city_iso === '' || ( $city_iso !== $seed_iso && $seed_iso === $iso2 ) ) ) {
				$city_iso = $seed_iso;
				update_post_meta( $city_id, 'wscity_country_iso2', $city_iso );
			}
			if ( ! $lat && isset( $seed['lat'] ) ) {
				$lat = (float) $seed['lat'];
				update_post_meta( $city_id, 'wscity_lat', $lat );
			}
			if ( ! $lng && isset( $seed['lng'] ) ) {
				$lng = (float) $seed['lng'];
				update_post_meta( $city_id, 'wscity_lng', $lng );
			}
		}
		if ( $city_iso !== $iso2 ) {
			wp_send_json_error( [ 'message' => 'country_mismatch' ] );
		}

		if ( ! $lat || ! $lng ) {
			wp_send_json_error( [ 'message' => 'no_coords' ] );
		}

		// Do not count yards here: the postmeta query is expensive and blocks the
		// "Loading..." state. The browser derives count/chart data from GeoJSON.
		$yards_count = null;
		$yards_url   = class_exists( 'WSErgo_Data' )
			? WSErgo_Data::get_city_yards_geojson_rest_url( $city_id )
			: '';
		$features_url = add_query_arg(
			'source',
			'local',
			rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/features' )
		);
		$osm_count      = class_exists( 'WSCOSM_Feature_Store' ) ? WSCOSM_Feature_Store::count_for_city( $city_id ) : 0;
		$osm_buildings_count = class_exists( 'WSCOSM_Feature_Store' ) ? WSCOSM_Feature_Store::count_buildings_for_city( $city_id ) : 0;
		$yard_ergo_url  = rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/yard-ergo-at' );
		$building_buffer_url = rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/building-buffer-zone' );
		$generate_buffer_yards_url = rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/generate-buffer-yards' );
		$courtyard_radius_m  = (float) get_option( 'wscosm_courtyard_buffer_radius_m', 35 );
		$courtyard_radius_m  = max( 5.0, min( 200.0, $courtyard_radius_m ) );
		$can_recalc_ergo = class_exists( 'WSCOSM_REST' ) && WSCOSM_REST::can_live_overpass( $city_id );
		$recalc_ergo_url = ( class_exists( 'WSErgo_CPT' ) && class_exists( 'WSErgo_Calculator' ) && $can_recalc_ergo )
			? rest_url( WSCOSM_REST::NS . '/city/' . $city_id . '/recalculate-yards-ergo' )
			: '';

		$chart  = class_exists( 'WSErgo_Model' )
			? self::build_chart_payload( array_fill_keys( WSErgo_Model::DIMENSION_KEYS, null ) )
			: [];

		wp_send_json_success(
			[
				'cityId'      => $city_id,
				'cityName'    => get_the_title( $city_id ),
				'lat'         => $lat,
				'lng'         => $lng,
				'zoom'        => 14,
				'yardsCount'      => $yards_count,
				'osmObjectsCount' => $osm_count,
				'osmBuildingsCount' => $osm_buildings_count,
				'yardsUrl'        => $yards_url,
				'featuresUrl'     => $features_url,
				'yardErgoAtUrl'   => $yard_ergo_url,
				'buildingBufferZoneUrl' => $building_buffer_url,
				'generateBufferYardsUrl' => $generate_buffer_yards_url,
				'courtyardBufferRadiusM' => $courtyard_radius_m,
				'recalculateErgoUrl' => $recalc_ergo_url,
				'canScanOsm'      => class_exists( 'WSCOSM_REST' ) ? WSCOSM_REST::can_live_overpass( $city_id ) : false,
				'hasErgo'         => class_exists( 'WSErgo_CPT' ),
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
			'dimensionKeys' => WSErgo_Model::DIMENSION_KEYS,
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
	 * Seed coordinates/ISO for known city names when metadata is missing.
	 *
	 * @return array{iso2:string,lat:float,lng:float}|null
	 */
	private static function city_seed_meta_by_title( string $title ): ?array {
		$key = self::normalize_city_title_key( $title );
		$map = [
			'bryansk' => [ 'iso2' => 'RU', 'lat' => 53.243562, 'lng' => 34.363407 ],
			'брянск'  => [ 'iso2' => 'RU', 'lat' => 53.243562, 'lng' => 34.363407 ],
		];
		return $map[ $key ] ?? null;
	}

	private static function normalize_city_title_key( string $title ): string {
		$raw = trim( strtolower( remove_accents( wp_strip_all_tags( $title ) ) ) );
		$raw = preg_replace( '/\s+/u', ' ', $raw );
		$raw = preg_replace( '/^(г\\.?|город)\s+/u', '', (string) $raw );
		return trim( (string) $raw );
	}

	/**
	 * Скрипты страницы страны (Leaflet уже в платформе).
	 */
	public static function enqueue_country_assets(): void {
		if ( ! class_exists( 'WorldStat_Country_CPT' ) || ! is_singular( WorldStat_Country_CPT::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

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
				'restNonce'          => wp_create_nonce( 'wp_rest' ),
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
					'openCourtyardZone' => __( 'Открыть придомовую территорию', 'worldstat-courtyard-osm' ),
					'courtyardTitle'    => __( 'Выбранный дом', 'worldstat-courtyard-osm' ),
					'courtyardRadius'   => __( 'Радиус буфера', 'worldstat-courtyard-osm' ),
					'courtyardRecalc'   => __( 'Пересчитать зону', 'worldstat-courtyard-osm' ),
					'courtyardObjects'  => __( 'Объекты в зоне', 'worldstat-courtyard-osm' ),
					'courtyardZoneLayer' => __( 'Буферная придомовая зона', 'worldstat-courtyard-osm' ),
					'courtyardNoBuilding' => __( 'Выберите дом на карте.', 'worldstat-courtyard-osm' ),
					'courtyardNoObjects' => __( 'В этой зоне объекты не найдены.', 'worldstat-courtyard-osm' ),
					'courtyardLoadError' => __( 'Не удалось построить придомовую зону.', 'worldstat-courtyard-osm' ),
					'layerYards'      => __( 'Придомовые (база сайта)', 'worldstat-courtyard-osm' ),
					'layerBench'      => __( 'Скамейки', 'worldstat-courtyard-osm' ),
					'layerLight'      => __( 'Фонари', 'worldstat-courtyard-osm' ),
					'layerPath'       => __( 'Пешеходные пути', 'worldstat-courtyard-osm' ),
					'layerPlay'       => __( 'Площадки', 'worldstat-courtyard-osm' ),
					'layerBin'        => __( 'Урны', 'worldstat-courtyard-osm' ),
					'layerGreen'      => __( 'Зелёные зоны', 'worldstat-courtyard-osm' ),
					'layerCenter'     => __( 'Центр города', 'worldstat-courtyard-osm' ),
					'generateBufferYards' => __( 'Создать придомовые зоны', 'worldstat-courtyard-osm' ),
					'generateBufferYardsWorking' => __( 'Генерация придомовых зон…', 'worldstat-courtyard-osm' ),
					'generateBufferYardsDone' => __( 'Сохранено зон', 'worldstat-courtyard-osm' ),
					'generateBufferYardsError' => __( 'Не удалось создать придомовые зоны.', 'worldstat-courtyard-osm' ),
					'bufferYardsProgressPrepare' => __( 'Подготовка…', 'worldstat-courtyard-osm' ),
					'bufferYardsProgressBuffer' => __( 'Построение буферов', 'worldstat-courtyard-osm' ),
					'bufferYardsProgressSaving' => __( 'Сохранение придомовых участков в базу', 'worldstat-courtyard-osm' ),
					'bufferYardsProgressDone' => __( 'Готово', 'worldstat-courtyard-osm' ),
					'bufferYardsProgressCounts' => __( '%1$d из %2$d', 'worldstat-courtyard-osm' ),
					'recalcErgo'      => __( 'Расчёт эргономики', 'worldstat-courtyard-osm' ),
					'recalcErgoWorking' => __( 'Пересчёт эргономики…', 'worldstat-courtyard-osm' ),
					'recalcErgoDone'  => __( 'Пересчитано участков', 'worldstat-courtyard-osm' ),
					'recalcErgoError' => __( 'Не удалось выполнить пересчёт эргономики.', 'worldstat-courtyard-osm' ),
					'recalcErgoWithAxes' => __( 'С ненулевыми осями (0–100)', 'worldstat-courtyard-osm' ),
					'recalcErgoDuration' => __( 'Длительность', 'worldstat-courtyard-osm' ),
					'recalcErgoNoAxesHint' => __(
						'Если оси пустые: для авто-расчёта по OSM в базе города должны быть сохранённые объекты (сканирование карты), id показателей должны совпадать с поддерживаемыми (расстояние до парковки/дороги/площадок, плотность фонарей в га двора, ширина path при tag width и др.). Гидранты и часть норм пока не извлекаются из текущего импорта OSM.',
						'worldstat-courtyard-osm'
					),
				],
			]
		);
	}
}
