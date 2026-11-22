<?php
/**
 * Plugin settings (single option, autoload off).
 *
 * @package Pressdock
 */

namespace Pressdock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed access to the pdock_settings option.
 */
final class Settings {

	const OPTION = 'pdock_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'disabled_entities'   => array(),
			'allow_local_http'    => 0,
			'snapshot_days'       => 14,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$keys  = array_keys( Registry::all( true ) );

		$enabled  = isset( $input['enabled_entities'] ) && is_array( $input['enabled_entities'] )
			? array_map( 'sanitize_key', $input['enabled_entities'] )
			: array();
		$disabled = array_values( array_diff( $keys, $enabled ) );

		$days = isset( $input['snapshot_days'] ) ? absint( $input['snapshot_days'] ) : 14;
		if ( $days < 1 || $days > 365 ) {
			$days = 14;
		}

		return array(
			'disabled_entities'   => $disabled,
			'allow_local_http'    => empty( $input['allow_local_http'] ) ? 0 : 1,
			'snapshot_days'       => $days,
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
	}
}
