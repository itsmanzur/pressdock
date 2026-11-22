<?php
/**
 * Hejazer Kafela settings (entity "kafela_settings").
 *
 * Storage: wp_options "pothik_settings" (one array, autoload off). Object id is always 1.
 * The record always exists (Kafela merges defaults), so it cannot be created or deleted, only updated.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kafela's Settings::sanitize() rebuilds EVERY key from its input and resets omitted ones.
 * This adapter therefore always sends it the complete object: current settings merged with the change.
 */
final class SettingsAdapter extends SingletonAdapter {

	const OPTION = 'pothik_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'label'   => __( 'Agency settings', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela', 'pressdock' ),
				'enabled' => true,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function area() {
		return 'settings';
	}

	/**
	 * Settings cannot be created or deleted through sync.
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		if ( 'delete' === $action ) {
			return false;
		}
		return parent::can( $action );
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema() {
		return array(
			'agency'              => array(
				'type'  => 'string',
				'label' => __( 'Agency name · English', 'pressdock' ),
			),
			'agency_bn'           => array(
				'type'  => 'string',
				'label' => __( 'Agency name · বাংলা', 'pressdock' ),
			),
			'phone'               => array(
				'type'  => 'string',
				'label' => __( 'Office phone', 'pressdock' ),
			),
			'whatsapp'            => array(
				'type'        => 'string',
				'label'       => __( 'WhatsApp', 'pressdock' ),
				'description' => 'Digits with country code, no plus sign.',
			),
			'license'             => array(
				'type'  => 'string',
				'label' => __( 'Hajj / Umrah license number', 'pressdock' ),
			),
			'logo_id'             => array(
				'type'  => 'media',
				'label' => __( 'Logo (attachment id)', 'pressdock' ),
			),
			'logo_url'            => array(
				'type'  => 'string',
				'label' => __( 'Logo URL', 'pressdock' ),
			),
			'notification_emails' => array(
				'type'        => 'string',
				'label'       => __( 'Send to', 'pressdock' ),
				'description' => 'Comma separated e-mail addresses that receive new-inquiry mails.',
			),
			'country_code'        => array(
				'type'  => 'string',
				'label' => __( 'Default country code', 'pressdock' ),
			),
			'rate_limit'          => array(
				'type'  => 'int',
				'label' => __( 'Spam guard (submissions per hour)', 'pressdock' ),
				'min'   => 1,
				'max'   => 100,
			),
			'delete_on_uninstall' => array(
				'type'        => 'bool',
				'label'       => __( 'Delete everything on uninstall', 'pressdock' ),
				'readonly'    => true,
				'description' => 'Destructive switch of the owning plugin. Read-only for sync on purpose.',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function read() {
		$all                        = \Pothik\Admin\Settings::get_all();
		$all['logo_id']             = (int) $all['logo_id'];
		$all['rate_limit']          = (int) $all['rate_limit'];
		$all['delete_on_uninstall'] = ! empty( $all['delete_on_uninstall'] );
		return array_intersect_key( $all, $this->schema() );
	}

	/**
	 * The settings record always exists, so a create is always a uniqueness conflict.
	 *
	 * @param array    $data Clean data.
	 * @param int|null $id   Object id for updates.
	 * @return WP_Error|true
	 */
	public function validate( array $data, $id = null ) {
		if ( null === $id ) {
			return Support::unique_error( 'The settings record already exists. Update record 1 instead of creating it.', 'id', self::ID );
		}
		$error = new WP_Error();

		if ( isset( $data['rate_limit'] ) && ! Support::in_range( $data['rate_limit'], 1, 100 ) ) {
			$error->add( 'pdock_invalid_field', 'Field "rate_limit" must be between 1 and 100.', array( 'field' => 'rate_limit' ) );
		}
		if ( isset( $data['country_code'] ) && 1 !== preg_match( '/^\d{1,5}$/', $data['country_code'] ) ) {
			$error->add( 'pdock_invalid_field', 'Field "country_code" must be 1-5 digits.', array( 'field' => 'country_code' ) );
		}
		if ( isset( $data['notification_emails'] ) && '' !== trim( $data['notification_emails'] ) ) {
			$list = array_filter( array_map( 'trim', explode( ',', str_replace( array( "\r", "\n", ';' ), ',', $data['notification_emails'] ) ) ), 'strlen' );
			foreach ( $list as $address ) {
				if ( ! is_email( $address ) ) {
					$error->add( 'pdock_invalid_field', sprintf( '"%s" is not a valid e-mail address.', $address ), array( 'field' => 'notification_emails' ) );
				}
			}
		}
		if ( isset( $data['logo_url'] ) && '' !== $data['logo_url'] && '' === esc_url_raw( $data['logo_url'] ) ) {
			$error->add( 'pdock_invalid_field', 'Field "logo_url" is not a valid URL.', array( 'field' => 'logo_url' ) );
		}
		if ( isset( $data['logo_id'] ) && $data['logo_id'] > 0 && 'attachment' !== get_post_type( $data['logo_id'] ) ) {
			$error->add( 'pdock_unresolved_relation', 'Field "logo_id" is not a media library attachment.', array( 'field' => 'logo_id' ) );
		}
		return $error->has_errors() ? $error : true;
	}

	/**
	 * Never reached through sync (validate() refuses it). Kept for the contract.
	 *
	 * @param array $data Clean data.
	 * @return int
	 */
	public function create( array $data ) {
		$this->update( self::ID, $data );
		return self::ID;
	}

	/**
	 * {@inheritdoc}
	 */
	public function update( $id, array $data ) {
		unset( $id );
		$before = \Pothik\Admin\Settings::get_all();
		$merged = array_merge( $before, array_intersect_key( $data, $this->schema() ) ); // Complete object, see class doc.
		$merged['delete_on_uninstall'] = ! empty( $before['delete_on_uninstall'] );
		$after  = \Pothik\Admin\Settings::sanitize( $merged );
		update_option( self::OPTION, $after, false );
		Support::fire( 'kafela_settings', 'saved', $before, $after );
	}

	/**
	 * Settings are never deleted (can( 'delete' ) is false). Defensive no-op.
	 *
	 * @param int  $id    Object id.
	 * @param bool $force Unused.
	 * @return void
	 */
	public function delete( $id, $force ) {
		unset( $id, $force );
	}

	/**
	 * Hook Kafela's save handler so admin edits reach the change log.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action(
			'pothik_settings_saved',
			static function () {
				Support::track( 'kafela_settings', self::ID );
			}
		);
	}
}
