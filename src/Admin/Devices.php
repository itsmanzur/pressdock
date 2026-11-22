<?php
/**
 * "Connected devices" log: last pull and push per application password.
 *
 * @package Pressdock
 */

namespace Pressdock\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored in the pdock_devices option (autoload off). No IP addresses are kept.
 */
final class Devices {

	const OPTION = 'pdock_devices';
	const MAX    = 50;

	/**
	 * Record a request from the current user.
	 *
	 * @param string $kind pull|push.
	 * @return void
	 */
	public static function log( $kind ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		$name = __( 'Browser session', 'pressdock' );
		$key  = 'session-' . $user_id;
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( $uuid ) {
				$key  = $uuid;
				$item = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
				$name = is_array( $item ) && ! empty( $item['name'] ) ? $item['name'] : __( 'Application Password', 'pressdock' );
			}
		}

		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();

		$entry = isset( $list[ $key ] ) && is_array( $list[ $key ] ) ? $list[ $key ] : array(
			'last_pull' => '',
			'last_push' => '',
		);

		$entry['name']    = sanitize_text_field( $name );
		$entry['user_id'] = $user_id;
		$entry[ 'pull' === $kind ? 'last_pull' : 'last_push' ] = gmdate( 'Y-m-d H:i:s' );
		$list[ $key ] = $entry;

		if ( count( $list ) > self::MAX ) {
			uasort(
				$list,
				static function ( $a, $b ) {
					return strcmp( max( $b['last_pull'], $b['last_push'] ), max( $a['last_pull'], $a['last_push'] ) );
				}
			);
			$list = array_slice( $list, 0, self::MAX, true );
		}

		update_option( self::OPTION, $list, false );
	}

	/**
	 * All devices.
	 *
	 * @return array
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}
}
