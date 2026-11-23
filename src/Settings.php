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
			'post_types'          => array(),
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

		if ( isset( $input['enabled_entities'] ) && is_array( $input['enabled_entities'] ) ) {
			$disabled = array_values( array_diff( $keys, array_map( 'sanitize_key', $input['enabled_entities'] ) ) );
		} elseif ( ! isset( $input['_section'] ) && isset( $input['disabled_entities'] ) && is_array( $input['disabled_entities'] ) ) {
			// Already-sanitized shape. WordPress runs this callback a second time when the option is first
			// created (update_option → add_option), so it must be idempotent.
			$disabled = array_values( array_map( 'sanitize_key', $input['disabled_entities'] ) );
		} else {
			// Form posted with every box unchecked.
			$disabled = $keys;
		}

		$days = isset( $input['snapshot_days'] ) ? absint( $input['snapshot_days'] ) : 14;
		if ( $days < 1 || $days > 365 ) {
			$days = 14;
		}

		$out = array(
			'disabled_entities'   => $disabled,
			'allow_local_http'    => empty( $input['allow_local_http'] ) ? 0 : 1,
			'snapshot_days'       => $days,
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);

		// The admin screens save one section at a time; keep the values of the other sections.
		$section = isset( $input['_section'] ) ? sanitize_key( $input['_section'] ) : '';
		if ( 'post_types' === $section || ( '' === $section && isset( $input['post_types'] ) ) ) {
			$out['post_types'] = Adapters\PostTypes::sanitize( isset( $input['post_types'] ) ? $input['post_types'] : array() );
		} else {
			$out['post_types'] = '' === $section ? array() : (array) self::get( 'post_types' );
		}

		if ( 'sources' === $section ) {
			$out = array_merge( self::all(), array( 'disabled_entities' => $disabled ) );
		} elseif ( 'options' === $section ) {
			$out['disabled_entities'] = (array) self::get( 'disabled_entities' );
		} elseif ( 'post_types' === $section ) {
			$out = array_merge( self::all(), array( 'post_types' => $out['post_types'] ) );
		}
		return $out;
	}
}
