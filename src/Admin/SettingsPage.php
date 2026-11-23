<?php
/**
 * Pressdock admin screens: top-level "Pressdock" menu with Dashboard, Data sources, Activity and Settings.
 *
 * @package Pressdock
 */

namespace Pressdock\Admin;

use Pressdock\Registry;
use Pressdock\Rest\Controller;
use Pressdock\Settings;
use Pressdock\Store\Ops;
use Pressdock\Sync\Undo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin screens. All screens need manage_options.
 */
final class SettingsPage {

	const SLUG = 'pressdock';

	/**
	 * Screen slug => tab key.
	 */
	const TABS = array(
		'pressdock'          => 'dashboard',
		'pressdock-sources'  => 'sources',
		'pressdock-activity' => 'activity',
		'pressdock-settings' => 'settings',
	);

	/**
	 * Hook suffixes of our screens (for asset loading).
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Hook the screens.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_legacy_url' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_pdock_undo', array( __CLASS__, 'handle_undo' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PDOCK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Top-level Pressdock menu and its sub-pages.
	 *
	 * @return void
	 */
	public static function menu() {
		$render = array( __CLASS__, 'render' );

		self::$hooks[] = add_menu_page(
			__( 'Pressdock', 'pressdock' ),
			__( 'Pressdock', 'pressdock' ),
			'manage_options',
			self::SLUG,
			$render,
			'dashicons-database',
			81
		);

		$subs = array(
			'pressdock'          => __( 'Dashboard', 'pressdock' ),
			'pressdock-sources'  => __( 'Data sources', 'pressdock' ),
			'pressdock-activity' => __( 'Devices & activity', 'pressdock' ),
			'pressdock-settings' => __( 'Settings', 'pressdock' ),
		);
		foreach ( $subs as $slug => $title ) {
			self::$hooks[] = add_submenu_page( self::SLUG, $title . ' ‹ ' . __( 'Pressdock', 'pressdock' ), $title, 'manage_options', $slug, $render );
		}
	}

	/**
	 * Old bookmark: Tools → Pressdock (tools.php?page=pressdock) now lives under the top-level menu.
	 *
	 * @return void
	 */
	public static function redirect_legacy_url() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		if ( 'tools.php' === $pagenow && isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
	}

	/**
	 * "Dashboard | Settings" links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'pressdock' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=pressdock-settings' ) ) . '">' . esc_html__( 'Settings', 'pressdock' ) . '</a>'
		);
		return $links;
	}

	/**
	 * CSS/JS only on Pressdock screens.
	 *
	 * @param string $hook Current screen hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'pdock-admin', PDOCK_URL . 'assets/admin.css', array(), PDOCK_VERSION );
		wp_enqueue_script( 'pdock-admin', PDOCK_URL . 'assets/admin.js', array(), PDOCK_VERSION, true );
	}

	/**
	 * Register the setting.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'pdock_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Undo a batch from the activity table.
	 *
	 * @return void
	 */
	public static function handle_undo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pressdock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pdock_undo' );

		$batch  = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$result = 1 === preg_match( '/^[A-Za-z0-9-]{8,36}$/', $batch ) ? Undo::run( $batch ) : array(
			'restored' => 0,
			'errors'   => array( 1 ),
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'pressdock-activity',
					'restored' => (int) $result['restored'],
					'failed'   => count( $result['errors'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// --------------------------------------------------------------------- render

	/**
	 * Render the current screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG;
		$tab  = isset( self::TABS[ $page ] ) ? self::TABS[ $page ] : 'dashboard';

		$data = self::collect();
		?>
		<div class="wrap pdock">
			<?php self::render_hero( $data ); ?>
			<hr class="wp-header-end">
			<?php self::render_notices( $data ); ?>
			<?php self::render_tabs( $tab, $data ); ?>
			<?php
			switch ( $tab ) {
				case 'sources':
					self::render_sources( $data );
					break;
				case 'activity':
					self::render_activity( $data );
					break;
				case 'settings':
					self::render_settings( $data );
					break;
				default:
					self::render_dashboard( $data );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Everything the screens show, gathered once.
	 *
	 * @return array
	 */
	private static function collect() {
		$settings = Settings::all();
		$disabled = (array) $settings['disabled_entities'];
		$adapters = Registry::all( true );

		$groups = array();
		foreach ( $adapters as $key => $adapter ) {
			$group = (string) $adapter->group();
			if ( '' === $group ) {
				$group = __( 'Other', 'pressdock' );
			}
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array(
					'entities' => array(),
					'enabled'  => 0,
				);
			}
			$on                             = ! in_array( $key, $disabled, true );
			$groups[ $group ]['entities'][] = array(
				'key'     => $key,
				'adapter' => $adapter,
				'enabled' => $on,
			);
			if ( $on ) {
				++$groups[ $group ]['enabled'];
			}
		}

		$devices = Devices::all();
		$batches = Ops::recent_batches( 100 );
		$week    = time() - 7 * DAY_IN_SECONDS;

		$active_devices = 0;
		foreach ( $devices as $device ) {
			if ( self::ts( max( (string) $device['last_pull'], (string) $device['last_push'] ) ) >= $week ) {
				++$active_devices;
			}
		}
		$week_syncs     = 0;
		$week_changes   = 0;
		$week_conflicts = 0;
		foreach ( $batches as $batch ) {
			if ( self::ts( $batch['created_at'] ) >= $week ) {
				++$week_syncs;
				$week_changes   += (int) $batch['applied'];
				$week_conflicts += (int) $batch['conflicts'];
			}
		}

		$app_passwords = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

		return array(
			'settings'       => $settings,
			'adapters'       => $adapters,
			'groups'         => $groups,
			'enabled_count'  => count( Registry::all() ),
			'devices'        => $devices,
			'active_devices' => $active_devices,
			'batches'        => $batches,
			'week_syncs'     => $week_syncs,
			'week_changes'   => $week_changes,
			'week_conflicts' => $week_conflicts,
			'transport_ok'   => Controller::transport_ok(),
			'https'          => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'app_passwords'  => $app_passwords,
			'api_url'        => rest_url( Controller::NAMESPACE_ ),
		);
	}

	/**
	 * Unix time of a stored UTC "Y-m-d H:i:s" value (0 when empty).
	 *
	 * @param string $utc Stored time.
	 * @return int
	 */
	private static function ts( $utc ) {
		$t = $utc ? strtotime( $utc . ' UTC' ) : false;
		return $t ? (int) $t : 0;
	}

	/**
	 * "5 minutes ago" for a stored UTC time, or an em dash.
	 *
	 * @param string $utc Stored time.
	 * @return string
	 */
	private static function ago( $utc ) {
		$t = self::ts( $utc );
		if ( ! $t ) {
			return '—';
		}
		/* translators: %s: human time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'pressdock' ), human_time_diff( $t, time() ) );
	}

	/**
	 * Local date/time for a stored UTC value (used in tooltips).
	 *
	 * @param string $utc Stored time.
	 * @return string
	 */
	private static function local_time( $utc ) {
		$t = self::ts( $utc );
		return $t ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t ) : '';
	}

	/**
	 * Header band.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_hero( array $data ) {
		$ready = $data['transport_ok'] && $data['app_passwords'] && $data['enabled_count'] > 0;
		?>
		<div class="pdock-hero">
			<div class="pdock-hero__logo" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5.5" rx="7.5" ry="3"/><path d="M4.5 5.5v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/><path d="M4.5 11.5v7c0 1.7 3.4 3 7.5 3"/><path d="m16 16 3 3-3 3"/><path d="M13 19h6"/></svg>
			</div>
			<div>
				<h1><?php esc_html_e( 'Pressdock Connector', 'pressdock' ); ?></h1>
				<p><?php esc_html_e( 'Work on your WordPress plugin data offline in the Pressdock app — then sync it back safely.', 'pressdock' ); ?></p>
			</div>
			<div class="pdock-hero__meta">
				<span class="pdock-pill">
					<span class="dashicons <?php echo esc_attr( $ready ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span>
					<?php echo esc_html( $ready ? __( 'Ready to connect', 'pressdock' ) : __( 'Needs attention', 'pressdock' ) ); ?>
				</span>
				<span class="pdock-pill">
					<?php
					/* translators: %s: plugin version. */
					echo esc_html( sprintf( __( 'v%s', 'pressdock' ), PDOCK_VERSION ) );
					?>
				</span>
				<span class="pdock-pill">
					<?php
					/* translators: %s: API version. */
					echo esc_html( sprintf( __( 'API %s', 'pressdock' ), PDOCK_API_VERSION ) );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Notices (undo result, settings saved).
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_notices( array $data ) {
		unset( $data );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices.
		if ( isset( $_GET['restored'] ) ) {
			$restored = absint( $_GET['restored'] );
			$failed   = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $failed ? 'notice-warning' : 'notice-success' ),
				esc_html(
					sprintf(
						/* translators: 1: restored count, 2: failed count. */
						__( 'Undo finished: %1$d restored, %2$d failed.', 'pressdock' ),
						$restored,
						$failed
					)
				)
			);
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'pressdock' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Tab bar.
	 *
	 * @param string $current Current tab.
	 * @param array  $data    Collected data.
	 * @return void
	 */
	private static function render_tabs( $current, array $data ) {
		$tabs = array(
			'pressdock'          => array( 'dashboard', __( 'Dashboard', 'pressdock' ), 'dashicons-dashboard', null ),
			'pressdock-sources'  => array( 'sources', __( 'Data sources', 'pressdock' ), 'dashicons-database', count( $data['adapters'] ) ),
			'pressdock-activity' => array( 'activity', __( 'Devices & activity', 'pressdock' ), 'dashicons-backup', count( $data['devices'] ) ),
			'pressdock-settings' => array( 'settings', __( 'Settings', 'pressdock' ), 'dashicons-admin-generic', null ),
		);
		echo '<nav class="pdock-tabs" aria-label="' . esc_attr__( 'Pressdock sections', 'pressdock' ) . '">';
		foreach ( $tabs as $slug => $tab ) {
			$active = $tab[0] === $current;
			printf(
				'<a href="%1$s" class="%2$s"%3$s><span class="dashicons %4$s" aria-hidden="true"></span>%5$s%6$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_attr( $active ? 'is-active' : '' ),
				$active ? ' aria-current="page"' : '',
				esc_attr( $tab[2] ),
				esc_html( $tab[1] ),
				null === $tab[3] ? '' : ' <span class="pdock-count">' . esc_html( number_format_i18n( $tab[3] ) ) . '</span>'
			);
		}
		echo '</nav>';
	}

	/**
	 * One stat card.
	 *
	 * @param string $icon  Dashicon class.
	 * @param string $label Label.
	 * @param string $value Big value.
	 * @param string $hint  Small line.
	 * @return void
	 */
	private static function stat( $icon, $label, $value, $hint ) {
		?>
		<div class="pdock-card pdock-stat">
			<div class="pdock-stat__label"><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $label ); ?></div>
			<div class="pdock-stat__value"><?php echo esc_html( $value ); ?></div>
			<div class="pdock-stat__hint"><?php echo esc_html( $hint ); ?></div>
		</div>
		<?php
	}

	/**
	 * Read-only text field with a copy button.
	 *
	 * @param string $id    Element id.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function copy_field( $id, $label, $value ) {
		?>
		<label class="pdock-field-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<div class="pdock-copy">
			<input type="text" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" readonly onclick="this.select()">
			<button type="button" class="button" data-pdock-copy="<?php echo esc_attr( $id ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><?php esc_html_e( 'Copy', 'pressdock' ); ?></button>
			<span class="pdock-toast" role="status"><?php esc_html_e( 'Copied', 'pressdock' ); ?></span>
		</div>
		<?php
	}

	/**
	 * First letter of a group name for its icon tile.
	 *
	 * @param string $name Group name.
	 * @return string
	 */
	private static function initial( $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return '?';
		}
		return function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) );
	}

	/**
	 * Dashboard tab.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_dashboard( array $data ) {
		$total = count( $data['adapters'] );
		?>
		<div class="pdock-grid pdock-grid--stats">
			<?php
			self::stat(
				'dashicons-database',
				__( 'Data sources', 'pressdock' ),
				number_format_i18n( $data['enabled_count'] ),
				/* translators: 1: entity types, 2: plugins. */
				sprintf( _n( '%1$d entity type from %2$d plugin', '%1$d entity types from %2$d plugins', $total, 'pressdock' ), $total, count( $data['groups'] ) )
			);
			self::stat(
				'dashicons-laptop',
				__( 'Devices', 'pressdock' ),
				number_format_i18n( count( $data['devices'] ) ),
				/* translators: %d: devices active in the last 7 days. */
				sprintf( __( '%d active in the last 7 days', 'pressdock' ), $data['active_devices'] )
			);
			self::stat(
				'dashicons-update',
				__( 'Syncs (7 days)', 'pressdock' ),
				number_format_i18n( $data['week_syncs'] ),
				/* translators: %d: applied changes. */
				sprintf( _n( '%d change applied', '%d changes applied', $data['week_changes'], 'pressdock' ), $data['week_changes'] )
			);
			self::stat(
				'dashicons-randomize',
				__( 'Conflicts (7 days)', 'pressdock' ),
				number_format_i18n( $data['week_conflicts'] ),
				$data['week_conflicts'] ? __( 'Resolved in the app, nothing overwritten', 'pressdock' ) : __( 'No edits collided', 'pressdock' )
			);
			?>
		</div>

		<div class="pdock-grid pdock-grid--2">
			<div>
				<div class="pdock-card">
					<div class="pdock-card__head">
						<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						<h2><?php esc_html_e( 'Connect a device', 'pressdock' ); ?></h2>
					</div>
					<div class="pdock-card__body">
						<ol class="pdock-steps">
							<li>
								<strong><?php esc_html_e( 'Create an Application Password', 'pressdock' ); ?></strong>
								<span class="pdock-muted"><?php esc_html_e( 'In your profile, name it after the device (e.g. "Office laptop"). Each device gets its own, so you can revoke one without touching the others.', 'pressdock' ); ?></span>
								<div class="pdock-actions">
									<a class="button button-primary" href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><?php esc_html_e( 'Create Application Password', 'pressdock' ); ?></a>
								</div>
							</li>
							<li>
								<strong><?php esc_html_e( 'Open the Pressdock app', 'pressdock' ); ?></strong>
								<span class="pdock-muted"><?php esc_html_e( 'Desktop app for Windows, macOS and Linux, or the installable web app.', 'pressdock' ); ?></span>
								<div class="pdock-actions">
									<a class="button" href="<?php echo esc_url( 'https://thereadscope.com/pressdock' ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Get the app', 'pressdock' ); ?></a>
								</div>
							</li>
							<li>
								<strong><?php esc_html_e( 'Add this site', 'pressdock' ); ?></strong>
								<span class="pdock-muted"><?php esc_html_e( 'Paste these into "Add site", together with your username and the Application Password.', 'pressdock' ); ?></span>
								<?php
								self::copy_field( 'pdock-site-url', __( 'Site URL', 'pressdock' ), home_url() );
								self::copy_field( 'pdock-username', __( 'Username', 'pressdock' ), wp_get_current_user()->user_login );
								?>
							</li>
						</ol>
					</div>
				</div>

				<div class="pdock-card">
					<div class="pdock-card__head">
						<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
						<h2><?php esc_html_e( 'Connected plugins', 'pressdock' ); ?></h2>
						<a class="pdock-link" href="<?php echo esc_url( admin_url( 'admin.php?page=pressdock-sources' ) ); ?>"><?php esc_html_e( 'Manage', 'pressdock' ); ?> →</a>
					</div>
					<div class="pdock-card__body">
						<?php if ( array() === $data['groups'] ) : ?>
							<div class="pdock-empty"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><?php esc_html_e( 'No plugin data is available yet. Activate a supported plugin or register an adapter.', 'pressdock' ); ?></div>
						<?php else : ?>
							<div class="pdock-sources">
								<?php foreach ( $data['groups'] as $name => $group ) : ?>
									<div class="pdock-source">
										<div class="pdock-source__head">
											<span class="pdock-source__icon" aria-hidden="true"><?php echo esc_html( self::initial( $name ) ); ?></span>
											<div>
												<div class="pdock-source__name"><?php echo esc_html( $name ); ?></div>
												<div class="pdock-source__sub">
													<?php
													echo esc_html(
														sprintf(
															/* translators: 1: enabled count, 2: total count. */
															__( '%1$d of %2$d syncing', 'pressdock' ),
															$group['enabled'],
															count( $group['entities'] )
														)
													);
													?>
												</div>
											</div>
										</div>
										<div class="pdock-chips">
											<?php foreach ( $group['entities'] as $entity ) : ?>
												<span class="pdock-pill <?php echo esc_attr( $entity['enabled'] ? 'pdock-pill--brand' : '' ); ?>"><?php echo esc_html( $entity['adapter']->label() ); ?></span>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div>
				<?php self::render_health( $data ); ?>
				<?php self::render_recent( $data ); ?>
				<?php self::render_developer(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Health checklist.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_health( array $data ) {
		$checks = array();

		if ( $data['https'] ) {
			$checks[] = array( 'ok', __( 'Secure connection (HTTPS)', 'pressdock' ), __( 'Passwords and data travel encrypted.', 'pressdock' ) );
		} elseif ( $data['transport_ok'] ) {
			$checks[] = array( 'warn', __( 'Local HTTP allowed', 'pressdock' ), __( 'Fine for a local test site. Use HTTPS on a live site.', 'pressdock' ) );
		} else {
			$checks[] = array( 'bad', __( 'HTTPS required', 'pressdock' ), __( 'The API refuses plain-HTTP connections. Enable HTTPS, or allow local HTTP in Settings on a development site.', 'pressdock' ) );
		}

		$checks[] = $data['app_passwords']
			? array( 'ok', __( 'Application Passwords available', 'pressdock' ), __( 'Devices sign in without your main password.', 'pressdock' ) )
			: array( 'bad', __( 'Application Passwords unavailable', 'pressdock' ), __( 'They are turned off on this site (often by a security plugin or because HTTPS is missing).', 'pressdock' ) );

		$checks[] = $data['enabled_count'] > 0
			? array(
				'ok',
				/* translators: %d: number of entity types. */
				sprintf( _n( '%d data source enabled', '%d data sources enabled', $data['enabled_count'], 'pressdock' ), $data['enabled_count'] ),
				__( 'WordPress capabilities are still checked on every request.', 'pressdock' ),
			)
			: array( 'warn', __( 'No data source enabled', 'pressdock' ), __( 'Enable at least one in Data sources.', 'pressdock' ) );

		$icons = array(
			'ok'   => 'dashicons-yes-alt',
			'warn' => 'dashicons-warning',
			'bad'  => 'dashicons-dismiss',
		);
		?>
		<div class="pdock-card">
			<div class="pdock-card__head">
				<span class="dashicons dashicons-heart" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Connection health', 'pressdock' ); ?></h2>
			</div>
			<div class="pdock-card__body">
				<ul class="pdock-checks">
					<?php foreach ( $checks as $check ) : ?>
						<li class="is-<?php echo esc_attr( $check[0] ); ?>">
							<span class="dashicons <?php echo esc_attr( $icons[ $check[0] ] ); ?>" aria-hidden="true"></span>
							<span><strong><?php echo esc_html( $check[1] ); ?></strong><span class="pdock-muted pdock-small"><?php echo esc_html( $check[2] ); ?></span></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php self::copy_field( 'pdock-api-url', __( 'API endpoint', 'pressdock' ), $data['api_url'] ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Last few sync batches.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_recent( array $data ) {
		$recent = array_slice( $data['batches'], 0, 5 );
		?>
		<div class="pdock-card">
			<div class="pdock-card__head">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Recent syncs', 'pressdock' ); ?></h2>
				<a class="pdock-link" href="<?php echo esc_url( admin_url( 'admin.php?page=pressdock-activity' ) ); ?>"><?php esc_html_e( 'View all', 'pressdock' ); ?> →</a>
			</div>
			<?php if ( array() === $recent ) : ?>
				<div class="pdock-empty"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'No sync yet. Changes made in the app show up here.', 'pressdock' ); ?></div>
			<?php else : ?>
				<table class="pdock-table">
					<tbody>
					<?php foreach ( $recent as $batch ) : ?>
						<?php $user = get_userdata( (int) $batch['user_id'] ); ?>
						<tr>
							<td>
								<span title="<?php echo esc_attr( self::local_time( $batch['created_at'] ) ); ?>"><?php echo esc_html( self::ago( $batch['created_at'] ) ); ?></span>
								<div class="pdock-muted pdock-small"><?php echo esc_html( $user ? $user->display_name : '—' ); ?></div>
							</td>
							<td class="pdock-num">
								<?php
								/* translators: %d: number of changes. */
								echo esc_html( sprintf( _n( '%d change', '%d changes', (int) $batch['applied'], 'pressdock' ), (int) $batch['applied'] ) );
								?>
								<?php if ( (int) $batch['conflicts'] > 0 ) : ?>
									<div><span class="pdock-pill pdock-pill--warn">
										<?php
										/* translators: %d: number of conflicts. */
										echo esc_html( sprintf( _n( '%d conflict', '%d conflicts', (int) $batch['conflicts'], 'pressdock' ), (int) $batch['conflicts'] ) );
										?>
									</span></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "Connect any plugin" developer card.
	 *
	 * @return void
	 */
	private static function render_developer() {
		$snippet = "add_filter( 'pdock_register_entities', function ( \$adapters ) {\n\t// Implements Pressdock\\Contracts\\EntityAdapter, or extend\n\t// Pressdock\\Adapters\\AbstractPostAdapter / AbstractTableAdapter.\n\t\$adapters[] = new My_Plugin_Pressdock_Adapter();\n\treturn \$adapters;\n} );";
		?>
		<div class="pdock-card">
			<div class="pdock-card__head">
				<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Connect any plugin', 'pressdock' ); ?></h2>
			</div>
			<div class="pdock-card__body">
				<p class="pdock-muted"><?php esc_html_e( 'Pressdock is universal: any plugin can expose its data with a small adapter that describes fields and maps reads and writes. Custom post types and custom tables have ready-made base classes.', 'pressdock' ); ?></p>
				<pre class="pdock-code"><?php echo esc_html( $snippet ); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Data sources tab: every registered entity, grouped by plugin, with an on/off switch.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_sources( array $data ) {
		$opt = Settings::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'pdock_settings_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_section]" value="sources">

			<div class="pdock-card">
				<div class="pdock-card__head">
					<span class="dashicons dashicons-database" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Data sources', 'pressdock' ); ?></h2>
				</div>
				<div class="pdock-card__body">
					<p class="pdock-muted"><?php esc_html_e( 'Choose which data the Pressdock app can see. Switching a source off hides it from every device; WordPress capabilities still decide what each user may read or change.', 'pressdock' ); ?></p>
				</div>
				<?php if ( array() === $data['groups'] ) : ?>
					<div class="pdock-empty"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><?php esc_html_e( 'No entities are registered.', 'pressdock' ); ?></div>
				<?php else : ?>
					<div class="pdock-table-wrap">
						<table class="pdock-table">
							<thead><tr>
								<th style="width:70px"><?php esc_html_e( 'Sync', 'pressdock' ); ?></th>
								<th><?php esc_html_e( 'Data', 'pressdock' ); ?></th>
								<th><?php esc_html_e( 'Key', 'pressdock' ); ?></th>
								<th class="pdock-num"><?php esc_html_e( 'Fields', 'pressdock' ); ?></th>
								<th><?php esc_html_e( 'You can', 'pressdock' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $data['groups'] as $name => $group ) : ?>
								<tr class="pdock-group-row"><td colspan="5">
									<?php echo esc_html( $name ); ?>
									<span class="pdock-muted pdock-small">
										<?php
										/* translators: 1: enabled count, 2: total count. */
										echo esc_html( sprintf( __( '%1$d of %2$d syncing', 'pressdock' ), $group['enabled'], count( $group['entities'] ) ) );
										?>
									</span>
								</td></tr>
								<?php foreach ( $group['entities'] as $entity ) : ?>
									<?php
									$adapter = $entity['adapter'];
									$schema  = (array) $adapter->schema();
									$can     = array_keys( array_filter( Registry::allowed_actions( $adapter ) ) );
									$id      = 'pdock-ent-' . $entity['key'];
									?>
									<tr>
										<td>
											<label class="pdock-switch" for="<?php echo esc_attr( $id ); ?>">
												<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $opt ); ?>[enabled_entities][]" value="<?php echo esc_attr( $entity['key'] ); ?>" <?php checked( $entity['enabled'] ); ?>>
												<span class="pdock-switch__ui"></span>
												<span class="screen-reader-text"><?php echo esc_html( $adapter->label() ); ?></span>
											</label>
										</td>
										<td><strong><?php echo esc_html( $adapter->label() ); ?></strong></td>
										<td><code><?php echo esc_html( $entity['key'] ); ?></code></td>
										<td class="pdock-num"><?php echo esc_html( number_format_i18n( count( $schema ) ) ); ?></td>
										<td>
											<div class="pdock-chips">
												<?php if ( array() === $can ) : ?>
													<span class="pdock-pill pdock-pill--warn"><?php esc_html_e( 'no access', 'pressdock' ); ?></span>
												<?php endif; ?>
												<?php foreach ( $can as $action ) : ?>
													<span class="pdock-pill"><?php echo esc_html( self::action_label( $action ) ); ?></span>
												<?php endforeach; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<div class="pdock-card__foot pdock-savebar">
					<?php submit_button( __( 'Save data sources', 'pressdock' ), 'primary', 'submit', false ); ?>
				</div>
			</div>
		</form>
		<?php
		self::render_developer();
	}

	/**
	 * Translated action name.
	 *
	 * @param string $action read|create|update|delete.
	 * @return string
	 */
	private static function action_label( $action ) {
		$labels = array(
			'read'   => __( 'view', 'pressdock' ),
			'create' => __( 'add', 'pressdock' ),
			'update' => __( 'edit', 'pressdock' ),
			'delete' => __( 'delete', 'pressdock' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * Devices & activity tab.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_activity( array $data ) {
		$batches = array_slice( $data['batches'], 0, 30 );
		?>
		<div class="pdock-card">
			<div class="pdock-card__head">
				<span class="dashicons dashicons-laptop" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Connected devices', 'pressdock' ); ?></h2>
				<a class="pdock-link" href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Manage Application Passwords', 'pressdock' ); ?> →</a>
			</div>
			<?php if ( array() === $data['devices'] ) : ?>
				<div class="pdock-empty"><span class="dashicons dashicons-laptop" aria-hidden="true"></span><?php esc_html_e( 'No device has connected yet.', 'pressdock' ); ?></div>
			<?php else : ?>
				<div class="pdock-table-wrap">
					<table class="pdock-table">
						<thead><tr>
							<th><?php esc_html_e( 'Device', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'User', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'Last download', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'Last upload', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'Status', 'pressdock' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $data['devices'] as $device ) : ?>
							<?php
							$user   = get_userdata( (int) $device['user_id'] );
							$last   = max( (string) $device['last_pull'], (string) $device['last_push'] );
							$active = self::ts( $last ) >= time() - 7 * DAY_IN_SECONDS;
							?>
							<tr>
								<td><strong><?php echo esc_html( $device['name'] ); ?></strong></td>
								<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
								<td title="<?php echo esc_attr( self::local_time( $device['last_pull'] ) ); ?>"><?php echo esc_html( self::ago( $device['last_pull'] ) ); ?></td>
								<td title="<?php echo esc_attr( self::local_time( $device['last_push'] ) ); ?>"><?php echo esc_html( self::ago( $device['last_push'] ) ); ?></td>
								<td>
									<span class="pdock-pill <?php echo esc_attr( $active ? 'pdock-pill--ok' : '' ); ?>"><?php echo esc_html( $active ? __( 'Active', 'pressdock' ) : __( 'Idle', 'pressdock' ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="pdock-card">
			<div class="pdock-card__head">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Sync history', 'pressdock' ); ?></h2>
			</div>
			<div class="pdock-card__body">
				<p class="pdock-muted pdock-small">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: days snapshots are kept. */
							__( 'Each upload from a device is one batch. "Undo" restores every record the batch changed, using snapshots kept for %d days.', 'pressdock' ),
							(int) $data['settings']['snapshot_days']
						)
					);
					?>
				</p>
			</div>
			<?php if ( array() === $batches ) : ?>
				<div class="pdock-empty"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'No sync activity yet.', 'pressdock' ); ?></div>
			<?php else : ?>
				<div class="pdock-table-wrap">
					<table class="pdock-table">
						<thead><tr>
							<th><?php esc_html_e( 'When', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'User', 'pressdock' ); ?></th>
							<th class="pdock-num"><?php esc_html_e( 'Changes', 'pressdock' ); ?></th>
							<th class="pdock-num"><?php esc_html_e( 'Applied', 'pressdock' ); ?></th>
							<th class="pdock-num"><?php esc_html_e( 'Conflicts', 'pressdock' ); ?></th>
							<th><?php esc_html_e( 'Batch', 'pressdock' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $batches as $batch ) : ?>
							<?php $user = get_userdata( (int) $batch['user_id'] ); ?>
							<tr>
								<td title="<?php echo esc_attr( self::local_time( $batch['created_at'] ) ); ?>"><?php echo esc_html( self::ago( $batch['created_at'] ) ); ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
								<td class="pdock-num"><?php echo esc_html( number_format_i18n( (int) $batch['total'] ) ); ?></td>
								<td class="pdock-num"><?php echo esc_html( number_format_i18n( (int) $batch['applied'] ) ); ?></td>
								<td class="pdock-num">
									<?php if ( (int) $batch['conflicts'] > 0 ) : ?>
										<span class="pdock-pill pdock-pill--warn"><?php echo esc_html( number_format_i18n( (int) $batch['conflicts'] ) ); ?></span>
									<?php else : ?>
										0
									<?php endif; ?>
								</td>
								<td><code title="<?php echo esc_attr( $batch['batch_id'] ); ?>"><?php echo esc_html( substr( $batch['batch_id'], 0, 8 ) ); ?></code></td>
								<td class="pdock-num">
									<?php if ( (int) $batch['undoable'] > 0 ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Restore every record this batch changed?', 'pressdock' ) ); ?>');">
											<?php wp_nonce_field( 'pdock_undo' ); ?>
											<input type="hidden" name="action" value="pdock_undo">
											<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch['batch_id'] ); ?>">
											<button type="submit" class="button button-small"><?php esc_html_e( 'Undo', 'pressdock' ); ?></button>
										</form>
									<?php else : ?>
										<span class="pdock-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Settings tab.
	 *
	 * @param array $data Collected data.
	 * @return void
	 */
	private static function render_settings( array $data ) {
		$opt      = Settings::OPTION;
		$settings = $data['settings'];
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'pdock_settings_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_section]" value="options">

			<div class="pdock-card">
				<div class="pdock-card__head">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Connection', 'pressdock' ); ?></h2>
				</div>
				<div class="pdock-setting">
					<div class="pdock-setting__text">
						<strong><?php esc_html_e( 'Allow HTTP on local development hosts', 'pressdock' ); ?></strong>
						<span class="pdock-muted pdock-small"><?php esc_html_e( 'Only for localhost, 127.0.0.1, *.local, *.test and *.localhost. Live sites always need HTTPS.', 'pressdock' ); ?></span>
						<?php if ( ! Controller::is_local_host() ) : ?>
							<div><span class="pdock-pill pdock-pill--warn"><?php esc_html_e( 'This site is not a local host — the option has no effect here.', 'pressdock' ); ?></span></div>
						<?php endif; ?>
					</div>
					<label class="pdock-switch" for="pdock-local-http">
						<input type="checkbox" id="pdock-local-http" name="<?php echo esc_attr( $opt ); ?>[allow_local_http]" value="1" <?php checked( ! empty( $settings['allow_local_http'] ) ); ?>>
						<span class="pdock-switch__ui"></span>
					</label>
				</div>
			</div>

			<div class="pdock-card">
				<div class="pdock-card__head">
					<span class="dashicons dashicons-backup" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Safety & storage', 'pressdock' ); ?></h2>
				</div>
				<div class="pdock-setting">
					<div class="pdock-setting__text">
						<label for="pdock_snapshot_days"><strong><?php esc_html_e( 'Keep undo snapshots for', 'pressdock' ); ?></strong></label>
						<span class="pdock-muted pdock-small"><?php esc_html_e( 'How long a sync batch can be undone. Older snapshots are removed daily.', 'pressdock' ); ?></span>
					</div>
					<div>
						<input id="pdock_snapshot_days" type="number" min="1" max="365" name="<?php echo esc_attr( $opt ); ?>[snapshot_days]" value="<?php echo esc_attr( (string) $settings['snapshot_days'] ); ?>">
						<span class="pdock-muted"><?php esc_html_e( 'days', 'pressdock' ); ?></span>
					</div>
				</div>
				<div class="pdock-setting">
					<div class="pdock-setting__text">
						<strong><?php esc_html_e( 'Delete data on uninstall', 'pressdock' ); ?></strong>
						<span class="pdock-muted pdock-small"><?php esc_html_e( 'Removes all Pressdock tables and settings when the plugin is deleted. Your plugin data itself is never touched.', 'pressdock' ); ?></span>
					</div>
					<label class="pdock-switch" for="pdock-uninstall">
						<input type="checkbox" id="pdock-uninstall" name="<?php echo esc_attr( $opt ); ?>[delete_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>>
						<span class="pdock-switch__ui"></span>
					</label>
				</div>
				<div class="pdock-card__foot pdock-savebar">
					<?php submit_button( __( 'Save settings', 'pressdock' ), 'primary', 'submit', false ); ?>
				</div>
			</div>
		</form>
		<?php
	}
}
