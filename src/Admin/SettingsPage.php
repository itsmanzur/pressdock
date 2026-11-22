<?php
/**
 * Tools → Pressdock settings page.
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
 * Entities toggle, transport and retention settings, connected devices, activity with Undo.
 */
final class SettingsPage {

	const SLUG = 'pressdock';

	/**
	 * Hook the page.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_pdock_undo', array( __CLASS__, 'handle_undo' ) );
	}

	/**
	 * Add Tools → Pressdock.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'Pressdock', 'pressdock' ),
			__( 'Pressdock', 'pressdock' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
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
					'page'     => self::SLUG,
					'restored' => (int) $result['restored'],
					'failed'   => count( $result['errors'] ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$disabled = (array) $settings['disabled_entities'];
		$adapters = Registry::all( true );
		$devices  = Devices::all();
		$batches  = Ops::recent_batches( 20 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$restored = isset( $_GET['restored'] ) ? absint( $_GET['restored'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pressdock Connector', 'pressdock' ); ?></h1>

			<?php if ( null !== $restored ) : ?>
				<div class="notice notice-info is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: restored count, 2: failed count. */
							__( 'Undo finished: %1$d restored, %2$d failed.', 'pressdock' ),
							$restored,
							$failed
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! Controller::transport_ok() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'This site is not served over HTTPS, so the Pressdock API refuses connections. Use HTTPS, or enable "Allow HTTP on local development hosts" below on a local site.', 'pressdock' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:780px">
				<h2><?php esc_html_e( 'How to connect', 'pressdock' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Go to Users → Profile and create an Application Password (name it "Pressdock").', 'pressdock' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: link to the desktop app download page. */
							esc_html__( 'Open the Pressdock desktop app (%s).', 'pressdock' ),
							'<a href="' . esc_url( 'https://thereadscope.com/pressdock' ) . '" target="_blank" rel="noopener noreferrer">thereadscope.com/pressdock</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Paste the site URL, your username and the Application Password.', 'pressdock' ); ?></li>
				</ol>
				<p><strong><?php esc_html_e( 'Site URL:', 'pressdock' ); ?></strong> <code><?php echo esc_html( home_url() ); ?></code></p>
				<p><?php echo esc_html( rest_url( Controller::NAMESPACE_ ) ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'pdock_settings_group' ); ?>
				<h2><?php esc_html_e( 'Entities', 'pressdock' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only enabled entities are visible to the desktop app. WordPress capabilities are still checked for every request.', 'pressdock' ); ?></p>
				<table class="widefat striped" style="max-width:780px">
					<thead><tr>
						<th style="width:70px"><?php esc_html_e( 'Enabled', 'pressdock' ); ?></th>
						<th><?php esc_html_e( 'Entity', 'pressdock' ); ?></th>
						<th><?php esc_html_e( 'Group', 'pressdock' ); ?></th>
						<th><?php esc_html_e( 'Key', 'pressdock' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( array() === $adapters ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No entities are registered.', 'pressdock' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $adapters as $key => $adapter ) : ?>
						<tr>
							<td><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[enabled_entities][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! in_array( $key, $disabled, true ) ); ?>></td>
							<td><?php echo esc_html( $adapter->label() ); ?></td>
							<td><?php echo esc_html( $adapter->group() ); ?></td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Options', 'pressdock' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Local development', 'pressdock' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[allow_local_http]" value="1" <?php checked( ! empty( $settings['allow_local_http'] ) ); ?>>
							<?php esc_html_e( 'Allow HTTP on local development hosts (localhost, 127.0.0.1, *.local, *.test)', 'pressdock' ); ?>
						</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdock_snapshot_days"><?php esc_html_e( 'Undo snapshots', 'pressdock' ); ?></label></th>
						<td>
							<input id="pdock_snapshot_days" type="number" min="1" max="365" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[snapshot_days]" value="<?php echo esc_attr( (string) $settings['snapshot_days'] ); ?>">
							<?php esc_html_e( 'days to keep', 'pressdock' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'pressdock' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>>
							<?php esc_html_e( 'Delete all Pressdock tables and settings when the plugin is deleted', 'pressdock' ); ?>
						</label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connected devices', 'pressdock' ); ?></h2>
			<table class="widefat striped" style="max-width:780px">
				<thead><tr>
					<th><?php esc_html_e( 'Application Password', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'User', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Last pull (UTC)', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Last push (UTC)', 'pressdock' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( array() === $devices ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No device has connected yet.', 'pressdock' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $devices as $device ) : ?>
					<?php $user = get_userdata( (int) $device['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $device['name'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_login : '—' ); ?></td>
						<td><?php echo esc_html( $device['last_pull'] ? $device['last_pull'] : '—' ); ?></td>
						<td><?php echo esc_html( $device['last_push'] ? $device['last_push'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent sync activity', 'pressdock' ); ?></h2>
			<table class="widefat striped" style="max-width:780px">
				<thead><tr>
					<th><?php esc_html_e( 'Batch', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Time (UTC)', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Ops', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Applied', 'pressdock' ); ?></th>
					<th><?php esc_html_e( 'Conflicts', 'pressdock' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( array() === $batches ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No sync activity yet.', 'pressdock' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $batches as $batch ) : ?>
					<tr>
						<td><code><?php echo esc_html( $batch['batch_id'] ); ?></code></td>
						<td><?php echo esc_html( $batch['created_at'] ); ?></td>
						<td><?php echo esc_html( $batch['total'] ); ?></td>
						<td><?php echo esc_html( $batch['applied'] ); ?></td>
						<td><?php echo esc_html( $batch['conflicts'] ); ?></td>
						<td>
							<?php if ( (int) $batch['undoable'] > 0 ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'pdock_undo' ); ?>
									<input type="hidden" name="action" value="pdock_undo">
									<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch['batch_id'] ); ?>">
									<?php submit_button( __( 'Undo', 'pressdock' ), 'small', 'submit', false ); ?>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
