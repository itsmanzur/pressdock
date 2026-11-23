<?php
/**
 * Schema-driven sanitizing and validation.
 *
 * @package Pressdock
 */

namespace Pressdock\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans incoming data against an adapter schema and validates it.
 *
 * Besides the basic types, two structured types exist:
 *  - "list":   array of strings. Options: max_items, item_max_length.
 *  - "object": nested array described by "properties" (a sub-schema).
 * Strings and text accept "max_length" (silent truncation, like most WordPress sanitizers).
 * A field flagged "readonly" can never be written. A field flagged "create_only" can be
 * written when the record is created, never changed afterwards.
 */
final class Validator {

	/**
	 * Allowed field types.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array( 'string', 'int', 'float', 'bool', 'date', 'datetime', 'enum', 'text', 'html', 'relation', 'media', 'list', 'object' );
	}

	/**
	 * Keep only known, writable fields and sanitize each by type.
	 *
	 * @param array $schema Adapter schema.
	 * @param array $data   Raw data.
	 * @return array Clean data. Values that fail type checks are kept as null so validate() can report them.
	 */
	public static function clean( array $schema, array $data ) {
		$out = array();
		foreach ( $schema as $name => $field ) {
			if ( ! array_key_exists( $name, $data ) || ! empty( $field['readonly'] ) ) {
				continue;
			}
			$out[ $name ] = self::clean_value( isset( $field['type'] ) ? $field['type'] : 'string', $data[ $name ], $field );
		}
		return $out;
	}

	/**
	 * Reject writes to read-only fields (and to create_only fields on update).
	 *
	 * On update a read-only field is tolerated when it carries the value the record already
	 * has, so a client may send a whole record back without tripping the check.
	 *
	 * @param array      $schema   Adapter schema.
	 * @param array      $raw      Raw incoming data.
	 * @param array|null $current  Current record (updates) or null (creates).
	 * @param bool       $creating True for creates.
	 * @return WP_Error|true
	 */
	public static function readonly_violations( array $schema, array $raw, $current, $creating ) {
		$error = new WP_Error();
		foreach ( $schema as $name => $field ) {
			if ( ! array_key_exists( $name, $raw ) ) {
				continue;
			}
			$readonly    = ! empty( $field['readonly'] );
			$create_only = ! empty( $field['create_only'] );
			if ( ! $readonly && ! $create_only ) {
				continue;
			}
			if ( $creating && ! $readonly ) {
				continue;
			}
			if ( ! $creating && is_array( $current ) && array_key_exists( $name, $current ) && self::same( $current[ $name ], $raw[ $name ] ) ) {
				continue;
			}
			$error->add(
				'pdock_readonly_field',
				$readonly
					? sprintf( 'Field "%s" is read-only. It is computed by the plugin and cannot be written.', $name )
					: sprintf( 'Field "%s" can only be set when the record is created.', $name ),
				array( 'field' => $name )
			);
		}
		return $error->has_errors() ? $error : true;
	}

	/**
	 * Loose equality that works for scalars and arrays.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( $a ) === wp_json_encode( $b );
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Sanitize one value.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @param array  $field Field definition.
	 * @return mixed
	 */
	private static function clean_value( $type, $value, array $field ) {
		if ( null === $value ) {
			return null;
		}
		switch ( $type ) {
			case 'int':
			case 'media':
				return is_numeric( $value ) ? (int) $value : null;
			case 'relation':
				if ( is_numeric( $value ) ) {
					return (int) $value;
				}
				return is_string( $value ) && self::is_uuid( $value ) ? strtolower( $value ) : null;
			case 'float':
				return is_numeric( $value ) ? (float) $value : null;
			case 'bool':
				return is_bool( $value ) ? $value : ( in_array( $value, array( 1, '1', 'true' ), true ) ? true : ( in_array( $value, array( 0, '0', 'false' ), true ) ? false : null ) );
			case 'date':
				if ( '' === $value ) {
					return '';
				}
				return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && self::valid_date( $value ) ? $value : null;
			case 'datetime':
				return '' === $value ? '' : self::clean_datetime( $value );
			case 'enum':
				$options = isset( $field['options'] ) ? array_map( 'strval', (array) $field['options'] ) : array();
				return is_scalar( $value ) && in_array( (string) $value, $options, true ) ? (string) $value : null;
			case 'text':
				return is_scalar( $value ) ? self::truncate( sanitize_textarea_field( (string) $value ), $field ) : null;
			case 'html':
				return is_scalar( $value ) ? wp_kses_post( (string) $value ) : null;
			case 'list':
				return self::clean_list( $value, $field );
			case 'object':
				if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
					return null;
				}
				return self::clean( isset( $field['properties'] ) ? (array) $field['properties'] : array(), $value );
			case 'string':
			default:
				return is_scalar( $value ) ? self::truncate( sanitize_text_field( (string) $value ), $field ) : null;
		}
	}

	/**
	 * Apply max_length.
	 *
	 * @param string $value Value.
	 * @param array  $field Field definition.
	 * @return string
	 */
	private static function truncate( $value, array $field ) {
		if ( ! empty( $field['max_length'] ) ) {
			return mb_substr( $value, 0, (int) $field['max_length'] );
		}
		return $value;
	}

	/**
	 * Sanitize a list of strings. Empty items are dropped.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Field definition.
	 * @return array|null
	 */
	private static function clean_list( $value, array $field ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$item_max = ! empty( $field['item_max_length'] ) ? (int) $field['item_max_length'] : 500;
		$max      = ! empty( $field['max_items'] ) ? (int) $field['max_items'] : 200;
		$out      = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				return null;
			}
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = mb_substr( $item, 0, $item_max );
			}
		}
		return array_slice( $out, 0, $max );
	}

	/**
	 * Whether a string looks like a UUID (any version).
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Calendar validity of Y-m-d.
	 *
	 * @param string $value Date string.
	 * @return bool
	 */
	private static function valid_date( $value ) {
		$parts = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $parts[1], $parts[2], $parts[0] );
	}

	/**
	 * Accept "Y-m-d H:i:s" or ISO-8601 and return "Y-m-d H:i:s" in GMT.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function clean_datetime( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value . ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ? '' : ' UTC' ) );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Check required fields and null-after-clean values.
	 *
	 * @param array $schema  Adapter schema.
	 * @param array $raw     Raw incoming data (to tell "missing" from "invalid").
	 * @param array $clean   Output of clean().
	 * @param bool  $partial True for updates: required fields only matter when present.
	 * @return WP_Error|true
	 */
	public static function validate( array $schema, array $raw, array $clean, $partial ) {
		$error = new WP_Error();
		foreach ( $schema as $name => $field ) {
			if ( ! empty( $field['readonly'] ) ) {
				continue;
			}
			$present = array_key_exists( $name, $raw );
			if ( ! $present ) {
				if ( ! $partial && ! empty( $field['required'] ) ) {
					$error->add( 'pdock_required', sprintf( 'Field "%s" is required.', $name ), array( 'field' => $name ) );
				}
				continue;
			}
			if ( null === $raw[ $name ] ) {
				if ( ! empty( $field['required'] ) ) {
					$error->add( 'pdock_required', sprintf( 'Field "%s" is required.', $name ), array( 'field' => $name ) );
				}
				continue;
			}
			if ( ! array_key_exists( $name, $clean ) || null === $clean[ $name ] ) {
				$error->add( 'pdock_invalid_field', sprintf( 'Field "%s" has an invalid value.', $name ), array( 'field' => $name ) );
				continue;
			}
			if ( ! empty( $field['required'] ) && is_string( $clean[ $name ] ) && '' === trim( $clean[ $name ] ) ) {
				$error->add( 'pdock_required', sprintf( 'Field "%s" is required.', $name ), array( 'field' => $name ) );
				continue;
			}
			if ( isset( $field['type'] ) && 'object' === $field['type'] && ! empty( $field['properties'] ) && is_array( $raw[ $name ] ) ) {
				$sub = self::validate( $field['properties'], $raw[ $name ], $clean[ $name ], true );
				if ( is_wp_error( $sub ) ) {
					foreach ( $sub->get_error_codes() as $code ) {
						$data      = $sub->get_error_data( $code );
						$sub_field = is_array( $data ) && isset( $data['field'] ) ? $data['field'] : '';
						$error->add( $code, $sub->get_error_message( $code ), array( 'field' => $name . '.' . $sub_field ) );
					}
				}
			}
		}
		return $error->has_errors() ? $error : true;
	}
}
