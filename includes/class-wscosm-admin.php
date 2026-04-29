<?php
/**
 * Админка: просмотр логов Courtyard OSM.
 *
 * @package WorldStatCourtyardOSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSCOSM_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_init', [ self::class, 'handle_clear_logs' ] );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'Courtyard OSM — логи', 'worldstat-courtyard-osm' ),
			__( 'Courtyard OSM', 'worldstat-courtyard-osm' ),
			'manage_options',
			'wscosm-logs',
			[ self::class, 'render_logs_page' ]
		);
	}

	public static function handle_clear_logs(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['wscosm_clear_logs'] ) || empty( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wscosm_clear_logs' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wscosm_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
		wp_safe_redirect( add_query_arg( 'cleared', '1', admin_url( 'tools.php?page=wscosm-logs' ) ) );
		exit;
	}

	public static function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wscosm_log';
		$obj   = $wpdb->prefix . 'wscosm_osm_object';

		$per_page = 100;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_gmt, level, scope, message, context, city_id FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$objects_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$obj}" );

		echo '<div class="wrap"><h1>' . esc_html__( 'Courtyard OSM — логи и хранилище', 'worldstat-courtyard-osm' ) . '</h1>';

		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Журнал очищен.', 'worldstat-courtyard-osm' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Ошибки Overpass, REST и сохранения объектов пишутся в таблицу БД; уровни error и warning дублируются в PHP error_log при включённом фильтре.', 'worldstat-courtyard-osm' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Сохранённых объектов OSM (для эргономики):', 'worldstat-courtyard-osm' ) . '</strong> ' . esc_html( (string) $objects_total ) . '</p>';

		echo '<form method="post" style="margin:1rem 0;" onsubmit="return confirm(\'' . esc_js( __( 'Очистить все записи лога?', 'worldstat-courtyard-osm' ) ) . '\');">';
		wp_nonce_field( 'wscosm_clear_logs' );
		echo '<input type="hidden" name="wscosm_clear_logs" value="1" />';
		submit_button( __( 'Очистить журнал', 'worldstat-courtyard-osm' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>' . esc_html__( 'Время (UTC)', 'worldstat-courtyard-osm' ) . '</th><th>' . esc_html__( 'Уровень', 'worldstat-courtyard-osm' ) . '</th>';
		echo '<th>' . esc_html__( 'Область', 'worldstat-courtyard-osm' ) . '</th><th>' . esc_html__( 'Город', 'worldstat-courtyard-osm' ) . '</th><th>' . esc_html__( 'Сообщение', 'worldstat-courtyard-osm' ) . '</th><th>' . esc_html__( 'Контекст', 'worldstat-courtyard-osm' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Записей нет.', 'worldstat-courtyard-osm' ) . '</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				$ctx = isset( $r['context'] ) && $r['context'] !== '' ? $r['context'] : '';
				if ( strlen( $ctx ) > 500 ) {
					$ctx = substr( $ctx, 0, 500 ) . '…';
				}
				echo '<tr>';
				echo '<td>' . esc_html( (string) (int) $r['id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['created_gmt'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['level'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['scope'] ) . '</td>';
				echo '<td>' . esc_html( $r['city_id'] !== null ? (string) (int) $r['city_id'] : '—' ) . '</td>';
				echo '<td>' . esc_html( wp_strip_all_tags( (string) $r['message'] ) ) . '</td>';
				echo '<td><code style="white-space:pre-wrap;word-break:break-all;">' . esc_html( $ctx ) . '</code></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		$pages = (int) ceil( max( 1, $total ) / $per_page );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= min( $pages, 20 ); $i++ ) {
				$url = add_query_arg( 'paged', $i, admin_url( 'tools.php?page=wscosm-logs' ) );
				if ( $i === $page ) {
					echo ' <strong>' . esc_html( (string) $i ) . '</strong>';
				} else {
					echo ' <a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>';
				}
			}
			if ( $pages > 20 ) {
				echo ' …';
			}
			echo '</p>';
		}

		echo '</div>';
	}
}
