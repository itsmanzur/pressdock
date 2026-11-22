<?php
/**
 * Plugin bootstrap.
 *
 * @package Pressdock
 */

namespace Pressdock;

use Pressdock\Admin\SettingsPage;
use Pressdock\Rest\Controller;
use Pressdock\Store\Ops;
use Pressdock\Store\Snapshots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires hooks. Runs on plugins_loaded.
 */
final class Plugin {

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public static function boot() {
		Installer::maybe_migrate();

		foreach ( (array) glob( PDOCK_DIR . 'adapters/*/bootstrap.php' ) as $file ) {
			require_once $file;
		}

		add_action( 'init', array( __CLASS__, 'register_adapter_hooks' ), 99 );
		add_action( 'rest_api_init', array( Controller::class, 'register_routes' ) );
		add_action( Installer::CRON_HOOK, array( __CLASS__, 'prune' ) );
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'allow_app_passwords_locally' ) );

		if ( is_admin() ) {
			SettingsPage::init();
		}
	}

	/**
	 * Let adapters hook WordPress so admin edits reach the change log.
	 *
	 * @return void
	 */
	public static function register_adapter_hooks() {
		foreach ( Registry::all( true ) as $adapter ) {
			if ( method_exists( $adapter, 'register_hooks' ) ) {
				$adapter->register_hooks();
			}
		}
	}

	/**
	 * Application Passwords normally need HTTPS. When the admin allowed local HTTP and the
	 * site is on a local development host, enable them there too.
	 *
	 * @param bool $available Core decision.
	 * @return bool
	 */
	public static function allow_app_passwords_locally( $available ) {
		if ( ! $available && Settings::get( 'allow_local_http' ) && Controller::is_local_host() ) {
			return true;
		}
		return $available;
	}

	/**
	 * Daily cron: remove old idempotency rows (30 days) and snapshots (setting, 14 days by default).
	 *
	 * @return void
	 */
	public static function prune() {
		Ops::prune( 30 );
		$days = (int) Settings::get( 'snapshot_days' );
		Snapshots::prune( $days > 0 ? $days : 14 );
	}
}
