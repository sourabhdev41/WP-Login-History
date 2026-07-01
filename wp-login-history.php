<?php
/**
 * Plugin Name:       WP Login History
 * Plugin URI:        https://wp.nrwone.in
 * Description:       Lightweight login history tracker. Logs successful and failed logins with IP, browser, device type and optional GeoIP country. Includes a searchable/sortable/paginated admin log viewer, CSV export, manual clearing and automatic retention cleanup.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NRW India
 * Author URI:        https://wp.nrwone.in
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-login-history
 * Domain Path:       /languages
 *
 * @package WP_Login_History
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLH_VERSION', '1.0.0' );
define( 'WPLH_FILE', __FILE__ );
define( 'WPLH_DB_VERSION', '1.0' );

/**
 * Main plugin class. Handles logging, admin UI, cleanup and export.
 */
final class WP_Login_History {

	/**
	 * Singleton instance.
	 *
	 * @var WP_Login_History
	 */
	private static $instance = null;

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wplh_daily_cleanup';

	/**
	 * Get singleton instance.
	 *
	 * @return WP_Login_History
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers all hooks.
	 */
	private function __construct() {
		register_activation_hook( WPLH_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WPLH_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'wp_login', array( $this, 'log_successful_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'log_failed_login' ), 10, 1 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wplh_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_wplh_delete_selected', array( $this, 'handle_delete_selected' ) );
		add_action( 'admin_post_wplh_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( self::CRON_HOOK, array( $this, 'cleanup_old_logs' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPLH_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-login-history', false, dirname( plugin_basename( WPLH_FILE ) ) . '/languages' );
	}

	/**
	 * Get the fully qualified log table name.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'login_history';
	}

	/**
	 * Plugin activation: create table, schedule cron.
	 */
	public function activate() {
		$this->create_table();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}

		if ( false === get_option( 'wplh_retention_days' ) ) {
			add_option( 'wplh_retention_days', 90 );
		}

		if ( false === get_option( 'wplh_delete_data_on_uninstall' ) ) {
			add_option( 'wplh_delete_data_on_uninstall', 0 );
		}
	}

	/**
	 * Plugin deactivation: clear scheduled cron event.
	 */
	public function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Create (or upgrade) the custom log table using dbDelta.
	 */
	private function create_table() {
		global $wpdb;

		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			username VARCHAR(191) NOT NULL DEFAULT '',
			login_time DATETIME NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			browser VARCHAR(100) NOT NULL DEFAULT '',
			device_type VARCHAR(20) NOT NULL DEFAULT '',
			country VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(10) NOT NULL DEFAULT 'success',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY login_time (login_time),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wplh_db_version', WPLH_DB_VERSION );
	}

	/**
	 * Record a successful login.
	 *
	 * @param string  $user_login Username used to log in.
	 * @param WP_User $user       The logged in user object.
	 */
	public function log_successful_login( $user_login, $user ) {
		$this->insert_log(
			array(
				'user_id'  => $user instanceof WP_User ? (int) $user->ID : 0,
				'username' => $user_login,
				'status'   => 'success',
			)
		);
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username Attempted username.
	 */
	public function log_failed_login( $username ) {
		$this->insert_log(
			array(
				'user_id'  => 0,
				'username' => $username,
				'status'   => 'failed',
			)
		);
	}

	/**
	 * Insert a log row into the database.
	 *
	 * @param array $args Log data: user_id, username, status.
	 */
	private function insert_log( $args ) {
		global $wpdb;

		$ip_address  = $this->get_client_ip();
		$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$browser     = $this->parse_browser( $user_agent );
		$device_type = $this->parse_device_type( $user_agent );
		$country     = $this->lookup_country( $ip_address );

		$wpdb->insert(
			$this->table_name(),
			array(
				'user_id'     => absint( $args['user_id'] ),
				'username'    => sanitize_user( $args['username'] ),
				'login_time'  => current_time( 'mysql' ),
				'ip_address'  => $ip_address,
				'browser'     => $browser,
				'device_type' => $device_type,
				'country'     => $country,
				'status'      => 'failed' === $args['status'] ? 'failed' : 'success',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Determine the visitor's IP address, respecting common proxy headers.
	 *
	 * @return string Sanitized, validated IP address (or empty string).
	 */
	private function get_client_ip() {
		$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

			// X-Forwarded-For can contain a comma separated list; take the first entry.
			if ( false !== strpos( $value, ',' ) ) {
				$parts = explode( ',', $value );
				$value = trim( $parts[0] );
			}

			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Basic local user-agent parsing to identify the browser. No external calls.
	 *
	 * @param string $user_agent Raw user agent string.
	 * @return string Human readable browser name.
	 */
	private function parse_browser( $user_agent ) {
		if ( '' === $user_agent ) {
			return __( 'Unknown', 'wp-login-history' );
		}

		$browsers = array(
			'Edg'      => 'Microsoft Edge',
			'OPR'      => 'Opera',
			'Opera'    => 'Opera',
			'Chrome'   => 'Google Chrome',
			'CriOS'    => 'Google Chrome',
			'Firefox'  => 'Mozilla Firefox',
			'FxiOS'    => 'Mozilla Firefox',
			'MSIE'     => 'Internet Explorer',
			'Trident'  => 'Internet Explorer',
			'Safari'   => 'Safari',
		);

		foreach ( $browsers as $needle => $label ) {
			if ( false !== stripos( $user_agent, $needle ) ) {
				// Chrome UA also contains "Safari"; the loop order above resolves this correctly.
				return $label;
			}
		}

		return __( 'Unknown', 'wp-login-history' );
	}

	/**
	 * Basic local user-agent parsing to identify device type. No external calls.
	 *
	 * @param string $user_agent Raw user agent string.
	 * @return string One of 'desktop', 'mobile', 'tablet'.
	 */
	private function parse_device_type( $user_agent ) {
		if ( '' === $user_agent ) {
			return 'desktop';
		}

		if ( preg_match( '/iPad|Tablet|Nexus 7|Nexus 9|Nexus 10|KFAPWI/i', $user_agent ) ) {
			return 'tablet';
		}

		if ( preg_match( '/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry/i', $user_agent ) ) {
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Optional, local-only GeoIP lookup. Returns an empty string if no GeoIP
	 * resource is available on the server. No external API calls are made.
	 *
	 * @param string $ip_address IP address to look up.
	 * @return string Country name, or empty string if unavailable.
	 */
	private function lookup_country( $ip_address ) {
		if ( '' === $ip_address ) {
			return '';
		}

		$country = '';

		// Support the PHP PECL geoip extension, if compiled in.
		if ( function_exists( 'geoip_country_name_by_name' ) ) {
			$result = @geoip_country_name_by_name( $ip_address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $result ) && '' !== $result ) {
				$country = $result;
			}
		} elseif ( function_exists( 'geoip_detect2_get_info_from_ip' ) ) {
			// Support the popular local MaxMind-DB based "GeoIP Detection" plugin, if active.
			$info = geoip_detect2_get_info_from_ip( $ip_address );
			if ( isset( $info->country->name ) && $info->country->name ) {
				$country = $info->country->name;
			}
		}

		/**
		 * Filters the resolved country for a login IP address, allowing
		 * site owners to hook in their own local GeoIP resolver.
		 *
		 * @param string $country    Resolved country name (may be empty).
		 * @param string $ip_address The IP address being resolved.
		 */
		return apply_filters( 'wplh_lookup_country', sanitize_text_field( $country ), $ip_address );
	}

	/**
	 * Delete log entries older than the configured retention period.
	 */
	public function cleanup_old_logs() {
		global $wpdb;

		$days = absint( get_option( 'wplh_retention_days', 90 ) );
		if ( $days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', current_time( 'timestamp' ) ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE login_time < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wplh-settings' ) ),
			esc_html__( 'Settings', 'wp-login-history' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register the admin menu and submenu pages.
	 */
	public function register_admin_menu() {
		$capability = 'manage_options';

		$hook = add_menu_page(
			__( 'Login History', 'wp-login-history' ),
			__( 'Login History', 'wp-login-history' ),
			$capability,
			'wp-login-history',
			array( $this, 'render_history_page' ),
			'dashicons-list-view',
			80
		);

		add_submenu_page(
			'wp-login-history',
			__( 'Login History', 'wp-login-history' ),
			__( 'All Logs', 'wp-login-history' ),
			$capability,
			'wp-login-history',
			array( $this, 'render_history_page' )
		);

		add_submenu_page(
			'wp-login-history',
			__( 'Login History Settings', 'wp-login-history' ),
			__( 'Settings', 'wp-login-history' ),
			$capability,
			'wplh-settings',
			array( $this, 'render_settings_page' )
		);

		add_action( "load-{$hook}", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Add per-page screen option on the list table screen.
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'wp-login-history' ),
				'default' => 20,
				'option'  => 'wplh_logs_per_page',
			)
		);
	}

	/**
	 * Enqueue minimal admin CSS (kept intentionally small; relies on core admin styles).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'wp-login-history' ) ) {
			return;
		}

		$css = '
			.wplh-status-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
			.wplh-status-success { background:#edfaef; color:#00a32a; }
			.wplh-status-failed { background:#fcf0f1; color:#d63638; }
			.wplh-filters { margin: 1em 0; }
			.wplh-filters select, .wplh-filters input[type=date] { margin-right: 6px; }
		';
		wp_register_style( 'wplh-admin', false, array(), WPLH_VERSION );
		wp_enqueue_style( 'wplh-admin' );
		wp_add_inline_style( 'wplh-admin', $css );
	}

	/**
	 * Register plugin settings (retention days, uninstall behaviour).
	 */
	public function register_settings() {
		register_setting(
			'wplh_settings_group',
			'wplh_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => 90,
			)
		);

		register_setting(
			'wplh_settings_group',
			'wplh_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Sanitize the retention days setting.
	 *
	 * @param mixed $value Raw input value.
	 * @return int Sanitized value, minimum 1.
	 */
	public function sanitize_retention_days( $value ) {
		$value = absint( $value );
		return $value < 1 ? 90 : $value;
	}

	/**
	 * Render the Settings admin page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-login-history' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login History Settings', 'wp-login-history' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wplh_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wplh_retention_days"><?php esc_html_e( 'Log Retention Period', 'wp-login-history' ); ?></label>
							</th>
							<td>
								<input name="wplh_retention_days" type="number" min="1" step="1" id="wplh_retention_days" value="<?php echo esc_attr( get_option( 'wplh_retention_days', 90 ) ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Number of days to keep login history records. Older entries are deleted automatically once per day. Default: 90.', 'wp-login-history' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'On Uninstall', 'wp-login-history' ); ?></th>
							<td>
								<label for="wplh_delete_data_on_uninstall">
									<input name="wplh_delete_data_on_uninstall" type="checkbox" id="wplh_delete_data_on_uninstall" value="1" <?php checked( 1, (int) get_option( 'wplh_delete_data_on_uninstall', 0 ) ); ?> />
									<?php esc_html_e( 'Delete all login history data and settings when this plugin is uninstalled.', 'wp-login-history' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the main Login History list table page.
	 */
	public function render_history_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-login-history' ) );
		}

		$list_table = new WPLH_List_Table( $this->table_name() );
		$list_table->process_bulk_action();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Login History', 'wp-login-history' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->maybe_render_admin_notices(); ?>

			<div class="wplh-actions" style="margin: 10px 0;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete ALL login history? This cannot be undone.', 'wp-login-history' ) ); ?>');">
					<?php wp_nonce_field( 'wplh_clear_logs_action', 'wplh_clear_logs_nonce' ); ?>
					<input type="hidden" name="action" value="wplh_clear_logs" />
					<?php submit_button( __( 'Clear All Logs', 'wp-login-history' ), 'delete', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:6px;">
					<?php wp_nonce_field( 'wplh_export_csv_action', 'wplh_export_csv_nonce' ); ?>
					<input type="hidden" name="action" value="wplh_export_csv" />
					<?php foreach ( $this->get_current_filters() as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
					<?php submit_button( __( 'Export CSV', 'wp-login-history' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="wp-login-history" />
				<?php $list_table->display_filters(); ?>
				<?php $list_table->search_box( __( 'Search Username / IP', 'wp-login-history' ), 'wplh-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Collect current GET filters for re-use (e.g. passing into export form).
	 *
	 * @return array
	 */
	private function get_current_filters() {
		$filters = array();

		$map = array( 's', 'wplh_user', 'wplh_status', 'wplh_date_from', 'wplh_date_to', 'orderby', 'order' );
		foreach ( $map as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$filters[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		return $filters;
	}

	/**
	 * Show transient admin notices after actions complete.
	 */
	private function maybe_render_admin_notices() {
		if ( ! isset( $_GET['wplh_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['wplh_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'cleared'  => __( 'Login history cleared successfully.', 'wp-login-history' ),
			'deleted'  => __( 'Selected log entries deleted successfully.', 'wp-login-history' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $notice ] )
			);
		}
	}

	/**
	 * Handle the "Clear All Logs" form submission.
	 */
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'wp-login-history' ) );
		}

		check_admin_referer( 'wplh_clear_logs_action', 'wplh_clear_logs_nonce' );

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table_name()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_safe_redirect( add_query_arg( 'wplh_notice', 'cleared', admin_url( 'admin.php?page=wp-login-history' ) ) );
		exit;
	}

	/**
	 * Handle bulk deletion of selected log rows from the list table.
	 */
	public function handle_delete_selected() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'wp-login-history' ) );
		}

		check_admin_referer( 'wplh_delete_selected_action', 'wplh_delete_selected_nonce' );

		global $wpdb;

		$ids = isset( $_POST['log_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) ) : array();

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table_name()} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'wplh_notice', 'deleted', admin_url( 'admin.php?page=wp-login-history' ) ) );
		exit;
	}

	/**
	 * Handle CSV export of the login history (respecting active filters).
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'wp-login-history' ) );
		}

		check_admin_referer( 'wplh_export_csv_action', 'wplh_export_csv_nonce' );

		global $wpdb;

		$table = $this->table_name();
		list( $where_sql, $where_args ) = WPLH_List_Table::build_where_from_request();

		$sql = "SELECT login_time, username, ip_address, browser, device_type, country, status FROM {$table} {$where_sql} ORDER BY login_time DESC";

		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=login-history-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv(
			$output,
			array(
				__( 'Date & Time', 'wp-login-history' ),
				__( 'Username', 'wp-login-history' ),
				__( 'IP Address', 'wp-login-history' ),
				__( 'Browser', 'wp-login-history' ),
				__( 'Device Type', 'wp-login-history' ),
				__( 'Country', 'wp-login-history' ),
				__( 'Status', 'wp-login-history' ),
			)
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				fputcsv( $output, $row );
			}
		}

		fclose( $output );
		exit;
	}
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table used on the main "Login History" admin page.
 * Extends WP_List_Table so the UI matches native WordPress screens
 * (Users, Posts, etc.) exactly.
 */
class WPLH_List_Table extends WP_List_Table {

	/**
	 * Log table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param string $table Log table name.
	 */
	public function __construct( $table ) {
		$this->table = $table;

		parent::__construct(
			array(
				'singular' => 'login_history',
				'plural'   => 'login_histories',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define the list table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'login_time'  => __( 'Date & Time', 'wp-login-history' ),
			'username'    => __( 'User', 'wp-login-history' ),
			'ip_address'  => __( 'IP Address', 'wp-login-history' ),
			'browser'     => __( 'Browser', 'wp-login-history' ),
			'device_type' => __( 'Device', 'wp-login-history' ),
			'country'     => __( 'Country', 'wp-login-history' ),
			'status'      => __( 'Status', 'wp-login-history' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'login_time' => array( 'login_time', true ),
			'username'   => array( 'username', false ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'wp-login-history' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'login_time':
				return esc_html(
					date_i18n(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						strtotime( $item['login_time'] )
					)
				);
			case 'username':
				return esc_html( $item['username'] ? $item['username'] : __( '(unknown)', 'wp-login-history' ) );
			case 'ip_address':
				return esc_html( $item['ip_address'] ? $item['ip_address'] : '—' );
			case 'browser':
				return esc_html( $item['browser'] ? $item['browser'] : '—' );
			case 'device_type':
				return esc_html( $item['device_type'] ? ucfirst( $item['device_type'] ) : '—' );
			case 'country':
				return esc_html( $item['country'] ? $item['country'] : '—' );
			case 'status':
				$is_success = ( 'success' === $item['status'] );
				return sprintf(
					'<span class="wplh-status-badge %s">%s</span>',
					esc_attr( $is_success ? 'wplh-status-success' : 'wplh-status-failed' ),
					esc_html( $is_success ? __( 'Success', 'wp-login-history' ) : __( 'Failed', 'wp-login-history' ) )
				);
			default:
				return '';
		}
	}

	/**
	 * Message shown when there are no items.
	 */
	public function no_items() {
		esc_html_e( 'No login history found.', 'wp-login-history' );
	}

	/**
	 * Render the custom filter controls (user, status, date range) above the table.
	 */
	public function display_filters() {
		global $wpdb;

		$table = $this->table;

		$users = $wpdb->get_col( "SELECT DISTINCT username FROM {$table} WHERE username != '' ORDER BY username ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$current_user   = isset( $_GET['wplh_user'] ) ? sanitize_text_field( wp_unslash( $_GET['wplh_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_GET['wplh_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wplh_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from      = isset( $_GET['wplh_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['wplh_date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to        = isset( $_GET['wplh_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['wplh_date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wplh-filters">
			<select name="wplh_user">
				<option value=""><?php esc_html_e( 'All Users', 'wp-login-history' ); ?></option>
				<?php foreach ( $users as $user ) : ?>
					<option value="<?php echo esc_attr( $user ); ?>" <?php selected( $current_user, $user ); ?>><?php echo esc_html( $user ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="wplh_status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wp-login-history' ); ?></option>
				<option value="success" <?php selected( $current_status, 'success' ); ?>><?php esc_html_e( 'Success', 'wp-login-history' ); ?></option>
				<option value="failed" <?php selected( $current_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wp-login-history' ); ?></option>
			</select>

			<label for="wplh_date_from" class="screen-reader-text"><?php esc_html_e( 'From date', 'wp-login-history' ); ?></label>
			<input type="date" id="wplh_date_from" name="wplh_date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="<?php esc_attr_e( 'From', 'wp-login-history' ); ?>" />

			<label for="wplh_date_to" class="screen-reader-text"><?php esc_html_e( 'To date', 'wp-login-history' ); ?></label>
			<input type="date" id="wplh_date_to" name="wplh_date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="<?php esc_attr_e( 'To', 'wp-login-history' ); ?>" />

			<?php submit_button( __( 'Filter', 'wp-login-history' ), 'secondary', 'filter_action', false ); ?>

			<?php if ( $current_user || $current_status || $date_from || $date_to ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-login-history' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'wp-login-history' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a WHERE clause and prepared args array from the current request's
	 * search box and filters. Shared between the list table and CSV export.
	 *
	 * @return array Two-element array: [ $where_sql, $args ].
	 */
	public static function build_where_from_request() {
		$where = array();
		$args  = array();

		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search  = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = '(username LIKE %s OR ip_address LIKE %s)';
			$args[]  = '%' . $GLOBALS['wpdb']->esc_like( $search ) . '%';
			$args[]  = '%' . $GLOBALS['wpdb']->esc_like( $search ) . '%';
		}

		if ( ! empty( $_GET['wplh_user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = 'username = %s';
			$args[]  = sanitize_text_field( wp_unslash( $_GET['wplh_user'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! empty( $_GET['wplh_status'] ) && in_array( wp_unslash( $_GET['wplh_status'] ), array( 'success', 'failed' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = 'status = %s';
			$args[]  = sanitize_text_field( wp_unslash( $_GET['wplh_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! empty( $_GET['wplh_date_from'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date = sanitize_text_field( wp_unslash( $_GET['wplh_date_from'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$where[] = 'login_time >= %s';
				$args[]  = $date . ' 00:00:00';
			}
		}

		if ( ! empty( $_GET['wplh_date_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date = sanitize_text_field( wp_unslash( $_GET['wplh_date_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$where[] = 'login_time <= %s';
				$args[]  = $date . ' 23:59:59';
			}
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		return array( $where_sql, $args );
	}

	/**
	 * Process the bulk "Delete" action from the list table by redirecting
	 * the request to admin-post.php with a proper nonce, matching core UX
	 * for actions that must persist across pagination.
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'wp-login-history' ) );
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		global $wpdb;

		$ids = isset( $_REQUEST['log_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['log_ids'] ) ) : array();

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'wplh_notice', 'deleted', admin_url( 'admin.php?page=wp-login-history' ) ) );
		exit;
	}

	/**
	 * Fetch and prepare the rows for the current page/filters/sort order.
	 */
	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = $this->get_items_per_page( 'wplh_logs_per_page', 20 );
		$current_page = $this->get_pagenum();

		list( $where_sql, $args ) = self::build_where_from_request();

		$orderby_allowed = array( 'login_time', 'username', 'status' );
		$orderby         = ( ! empty( $_GET['orderby'] ) && in_array( wp_unslash( $_GET['orderby'] ), $orderby_allowed, true ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['orderby'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'login_time';
		$order            = ( ! empty( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$count_sql = "SELECT COUNT(id) FROM {$this->table} {$where_sql}";
		$data_sql  = "SELECT * FROM {$this->table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$data_args   = $args;
		$data_args[] = $per_page;
		$data_args[] = ( $current_page - 1 ) * $per_page;

		if ( ! empty( $args ) ) {
			$total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total_items = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}
}

/**
 * Uninstall handler. Only fires when the plugin is deleted via the Plugins
 * screen (not on simple deactivation), and only removes data if the site
 * owner explicitly opted in via Settings.
 */
function wplh_uninstall_handler() {
	if ( ! (int) get_option( 'wplh_delete_data_on_uninstall', 0 ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'login_history';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	delete_option( 'wplh_retention_days' );
	delete_option( 'wplh_delete_data_on_uninstall' );
	delete_option( 'wplh_db_version' );
}
register_uninstall_hook( WPLH_FILE, 'wplh_uninstall_handler' );

// Boot the plugin.
WP_Login_History::get_instance();
