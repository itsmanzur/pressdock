<?php
/**
 * Shared helpers for the Hejazer Kafela adapters.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

use Pressdock\Context;
use Pressdock\Registry;
use Pressdock\Store\Records;
use Pressdock\Tracker;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, stateless helpers. Nothing here touches a Hejazer Kafela file.
 */
final class Support {

	/**
	 * Which Kafela events the adapters re-fire when a record is written through the sync API.
	 * Filter: pdock_kafela_fire_hooks( bool $fire, string $entity, string $event ).
	 *
	 * Inquiry "created" stays off: add-ons treat it as "a visitor just submitted the form"
	 * (staff notifications). Every other event only feeds Kafela Pro's activity log and lead cleanup.
	 *
	 * @var array
	 */
	const HOOK_DEFAULTS = array(
		'kafela_package:created'          => true,
		'kafela_package:saved'            => true,
		'kafela_package:deleted'          => true,
		'kafela_inquiry:created'          => false,
		'kafela_inquiry:reply_toggled'    => true,
		'kafela_inquiry:deleted'          => true,
		'kafela_receipt:saved'            => true,
		'kafela_settings:saved'           => true,
	);

	/**
	 * Adapter arguments after the pdock_kafela_adapter_args filter.
	 *
	 * Keys: label, group, enabled (false = do not register this adapter, so Kafela can ship its own),
	 * can (optional callable( $action ) => bool replacing the permission check).
	 *
	 * @param string $entity   Entity key.
	 * @param array  $defaults Default arguments.
	 * @return array
	 */
	public static function args( $entity, array $defaults ) {
		/**
		 * Filter the arguments of a bundled Hejazer Kafela adapter.
		 *
		 * @param array  $args   label, group, enabled, can.
		 * @param string $entity Entity key.
		 */
		$args = apply_filters( 'pdock_kafela_adapter_args', $defaults, $entity );
		return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
	}

	/**
	 * Permission check through Kafela's own access layer.
	 *
	 * @param string $area   packages|inquiries|receipt|settings.
	 * @param string $action read|create|update|delete.
	 * @param array  $args   Adapter arguments.
	 * @return bool
	 */
	public static function can( $area, $action, array $args ) {
		if ( isset( $args['can'] ) && is_callable( $args['can'] ) ) {
			return (bool) call_user_func( $args['can'], $action );
		}
		if ( ! class_exists( '\Pothik\Access' ) ) {
			return current_user_can( 'manage_options' );
		}
		return \Pothik\Access::can( $area, 'read' === $action ? 'view' : 'edit' );
	}

	/**
	 * Permission check through Hejazer Kafela Pro's access matrix.
	 *
	 * Staff limited to some groups are refused: a pull has no per-group filter, so letting them in
	 * would show every group.
	 *
	 * @param string $area   Pro area (ledger, receipt, ...).
	 * @param string $action read|create|update|delete.
	 * @param array  $args   Adapter arguments.
	 * @return bool
	 */
	public static function can_pro( $area, $action, array $args ) {
		if ( isset( $args['can'] ) && is_callable( $args['can'] ) ) {
			return (bool) call_user_func( $args['can'], $action );
		}
		if ( ! class_exists( '\PothikPro\Access' ) ) {
			return current_user_can( 'manage_options' );
		}
		if ( 'all' !== \PothikPro\Access::scope( get_current_user_id() ) ) {
			return false;
		}
		return \PothikPro\Access::can( $area, 'read' === $action ? 'view' : 'edit' );
	}

	/**
	 * Re-fire a Kafela action for a sync write, unless filtered off.
	 *
	 * @param string $entity Entity key.
	 * @param string $event  Event name (created, saved, deleted, reply_toggled).
	 * @param mixed  ...$args Hook arguments.
	 * @return void
	 */
	public static function fire( $entity, $event, ...$args ) {
		$default = isset( self::HOOK_DEFAULTS[ $entity . ':' . $event ] ) ? self::HOOK_DEFAULTS[ $entity . ':' . $event ] : false;
		/** This filter is documented in adapters/hejazer-kafela/README.md. */
		if ( ! apply_filters( 'pdock_kafela_fire_hooks', $default, $entity, $event ) ) {
			return;
		}
		$hooks = array(
			'kafela_package'  => array(
				'created' => 'pothik_package_created',
				'saved'   => 'pothik_package_saved',
				'deleted' => 'pothik_package_deleted',
			),
			'kafela_inquiry'  => array(
				'created'       => 'pothik_inquiry_created',
				'reply_toggled' => 'pothik_inquiry_reply_toggled',
				'deleted'       => 'pothik_inquiry_deleted',
			),
			'kafela_receipt'  => array( 'saved' => 'pothik_receipt_saved' ),
			'kafela_settings' => array( 'saved' => 'pothik_settings_saved' ),
		);
		if ( isset( $hooks[ $entity ][ $event ] ) ) {
			do_action_ref_array( $hooks[ $entity ][ $event ], $args );
		}
	}

	/**
	 * Uniqueness violation, reported to the client like a conflict.
	 *
	 * @param string $message     Message.
	 * @param string $field       Field holding the duplicate value.
	 * @param int    $existing_id Object id that already owns the value.
	 * @return WP_Error
	 */
	public static function unique_error( $message, $field, $existing_id ) {
		return new WP_Error(
			'pdock_unique',
			$message,
			array(
				'unique'      => true,
				'field'       => $field,
				'existing_id' => (int) $existing_id,
			)
		);
	}

	/**
	 * Tracker call for changes made by Kafela's own screens. Sync writes are tracked by the push processor.
	 *
	 * @param string $entity Entity key.
	 * @param int    $id     Object id.
	 * @param string $action upsert|delete.
	 * @return void
	 */
	public static function track( $entity, $id, $action = 'upsert' ) {
		if ( Context::is_syncing() || (int) $id < 1 ) {
			return;
		}
		Tracker::touch( $entity, (int) $id, $action );
	}

	/**
	 * After "remove demo data": tombstone every tracked record that no longer exists.
	 *
	 * @param string $entity Entity key.
	 * @return void
	 */
	public static function reconcile( $entity ) {
		$adapters = Registry::all( true );
		if ( ! isset( $adapters[ $entity ] ) ) {
			return;
		}
		foreach ( Records::live_ids( $entity ) as $id ) {
			if ( null === $adapters[ $entity ]->get( $id ) ) {
				self::track( $entity, $id, 'delete' );
			}
		}
	}

	/**
	 * Kafela's PackageStore keeps a static copy of the rows. Drop it so the next read sees the option.
	 *
	 * @return void
	 */
	public static function reset_package_memo() {
		static $property = null;
		if ( null === $property ) {
			try {
				$property = new \ReflectionProperty( '\Pothik\PackageStore', 'memo' );
				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}
			} catch ( \ReflectionException $e ) {
				$property = false;
			}
		}
		if ( $property ) {
			$property->setValue( null, null );
		}
	}

	/**
	 * Block customer/staff mail while a sync operation runs. Registered once.
	 *
	 * @return void
	 */
	public static function guard_mail() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		// Kafela's own notification filter: false means "send nothing".
		add_filter(
			'pothik_notify_email',
			static function ( $mail ) {
				return Context::is_syncing() ? false : $mail;
			},
			1
		);
		// Last line of defence for any add-on that mails from a hook we re-fire.
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) {
				return Context::is_syncing() ? false : $short_circuit;
			},
			1
		);
	}

	/**
	 * Number check with an inclusive range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return bool
	 */
	public static function in_range( $value, $min, $max ) {
		return is_int( $value ) && $value >= $min && $value <= $max;
	}
}
