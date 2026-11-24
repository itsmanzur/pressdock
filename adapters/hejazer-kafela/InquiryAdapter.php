<?php
/**
 * Hejazer Kafela inquiries (entity "kafela_inquiry").
 *
 * Storage: custom table {prefix}pothik_inquiries. Object id: the table's auto-increment id.
 * Writes go through Kafela's InquiryRepository where it offers a method (insert, delete),
 * never through the public form handler (no nonce, honeypot, rate limit, e-mail, redirect).
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

namespace Pressdock\Adapters\Kafela;

use Pressdock\Adapters\AbstractTableAdapter;
use Pressdock\Context;
use Pressdock\Contracts\RestorableAdapter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends the bundled custom-table base class.
 */
final class InquiryAdapter extends AbstractTableAdapter implements RestorableAdapter {

	/**
	 * Arguments (label, group, enabled, can).
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Ids captured before a bulk action runs (Kafela's bulk hook carries no ids).
	 *
	 * @var array array( 'op' => string, 'ids' => int[] )
	 */
	private $bulk = array(
		'op'  => '',
		'ids' => array(),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->args = Support::args(
			'kafela_inquiry',
			array(
				'label'   => __( 'Inquiries', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela', 'pressdock' ),
				'enabled' => true,
			)
		);
	}

	/**
	 * Whether this adapter should be registered.
	 *
	 * @return bool
	 */
	public function enabled() {
		return ! empty( $this->args['enabled'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_inquiry';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return (string) $this->args['label'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function group() {
		return (string) $this->args['group'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function table() {
		return 'pothik_inquiries';
	}

	/**
	 * Kafela's own permission model (Access::can).
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		return Support::can( 'inquiries', $action, $this->args );
	}

	/**
	 * Field definitions. "column" names the table column; the default is the field name.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'uuid'          => array(
				'type'     => 'string',
				'label'    => __( 'ID', 'pressdock' ),
				'readonly' => true,
			),
			'created_gmt'   => array(
				'type'        => 'datetime',
				'label'       => __( 'Date (GMT)', 'pressdock' ),
				'create_only' => true,
			),
			'name'          => array(
				'type'       => 'string',
				'label'      => __( 'Name', 'pressdock' ),
				'required'   => true,
				'max_length' => 80,
			),
			'phone'         => array(
				'type'       => 'string',
				'label'      => __( 'Phone', 'pressdock' ),
				'required'   => true,
				'max_length' => 40,
			),
			'whatsapp'      => array(
				'type'       => 'string',
				'label'      => __( 'WhatsApp', 'pressdock' ),
				'max_length' => 40,
			),
			'city'          => array(
				'type'       => 'string',
				'label'      => __( 'City', 'pressdock' ),
				'max_length' => 40,
			),
			'share'         => array(
				'type'    => 'enum',
				'label'   => __( 'Share', 'pressdock' ),
				'options' => array( 'quad', 'triple', 'double', 'single' ),
			),
			'adults'        => array(
				'type'  => 'int',
				'label' => __( 'Adults', 'pressdock' ),
				'min'   => 1,
				'max'   => 30,
			),
			'children'      => array(
				'type'  => 'int',
				'label' => __( 'Children', 'pressdock' ),
				'min'   => 0,
				'max'   => 20,
			),
			'infants'       => array(
				'type'  => 'int',
				'label' => __( 'Infants', 'pressdock' ),
				'min'   => 0,
				'max'   => 10,
			),
			'trip'          => array(
				'type'    => 'enum',
				'label'   => __( 'Trip Type', 'pressdock' ),
				'options' => array( 'group', 'private' ),
			),
			'note'          => array(
				'type'       => 'text',
				'label'      => __( 'Note', 'pressdock' ),
				'max_length' => 300,
			),
			'package_id'    => array(
				'type'        => 'relation',
				'target'      => 'kafela_package',
				'label'       => __( 'Package ID', 'pressdock' ),
				'column'      => 'package_id',
				'description' => 'Package object id or uuid. 0 clears it. Stored as the Kafela package string id.',
			),
			'package_slug'  => array(
				'type'     => 'string',
				'label'    => __( 'Package ID (Kafela string)', 'pressdock' ),
				'column'   => 'package_id',
				'readonly' => true,
			),
			'package_title' => array(
				'type'     => 'string',
				'label'    => __( 'Package Title', 'pressdock' ),
				'readonly' => true,
			),
			'status'        => array(
				'type'     => 'enum',
				'label'    => __( 'Status', 'pressdock' ),
				'options'  => array( 'new', 'waiting', 'replied' ),
				'readonly' => true,
			),
			'replied'       => array(
				'type'  => 'bool',
				'label' => __( 'Replied', 'pressdock' ),
			),
			'demo'          => array(
				'type'     => 'bool',
				'label'    => __( 'Demo', 'pressdock' ),
				'readonly' => true,
			),
			'ref'           => array(
				'type'       => 'string',
				'label'      => __( 'Ref', 'pressdock' ),
				'max_length' => 10,
			),
		);
	}

	/**
	 * Use the inquiry uuid as the Pressdock record uuid for rows created outside the sync API.
	 *
	 * @param int $id Row id.
	 * @return string|null
	 */
	public function preferred_uuid( $id ) {
		global $wpdb;
		$table = $this->full_table();
		$uuid  = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM `{$table}` WHERE id = %d", (int) $id ) );
		return is_string( $uuid ) && '' !== $uuid ? $uuid : null;
	}

	/**
	 * Row to record: the relation becomes an object id, the raw string stays readable.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	protected function build( array $row ) {
		$out                 = parent::build( $row );
		$slug                = isset( $row['package_id'] ) ? (string) $row['package_id'] : '';
		$out['package_id']   = PackageIds::id_for( $slug, false );
		$out['package_slug'] = $slug;
		return $out;
	}

	/**
	 * Canonical English keys: Kafela accepts Bangla labels for share and trip (I18n::ALIASES).
	 *
	 * @param array    $data Raw data.
	 * @param int|null $id   Object id for updates.
	 * @return array
	 */
	public function normalize( array $data, $id = null ) {
		unset( $id );
		foreach ( array( 'share', 'trip' ) as $group ) {
			if ( isset( $data[ $group ] ) && is_string( $data[ $group ] ) ) {
				$data[ $group ] = \Pothik\I18n::canonical( $group, sanitize_text_field( $data[ $group ] ) );
			}
		}
		if ( isset( $data['ref'] ) && is_string( $data['ref'] ) ) {
			$data['ref'] = strtoupper( trim( $data['ref'] ) );
		}
		return $data;
	}

	/**
	 * Business rules.
	 *
	 * @param array    $data Clean data.
	 * @param int|null $id   Object id for updates.
	 * @return WP_Error|true
	 */
	public function validate( array $data, $id = null ) {
		global $wpdb;
		$error = new WP_Error();

		// Kafela does not enforce a unique uuid (plain KEY), so it is enforced here.
		$uuid = Context::current_uuid();
		if ( null === $id && is_string( $uuid ) && '' !== $uuid ) {
			$table    = $this->full_table();
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE uuid = %s LIMIT 1", $uuid ) );
			if ( $existing > 0 ) {
				return Support::unique_error( 'An inquiry with this uuid already exists.', 'uuid', $existing );
			}
		}

		foreach ( array(
			'adults'   => array( 1, 30 ),
			'children' => array( 0, 20 ),
			'infants'  => array( 0, 10 ),
		) as $name => $range ) {
			if ( isset( $data[ $name ] ) && ! Support::in_range( $data[ $name ], $range[0], $range[1] ) ) {
				$error->add( 'pdock_invalid_field', sprintf( 'Field "%1$s" must be between %2$d and %3$d.', $name, $range[0], $range[1] ), array( 'field' => $name ) );
			}
		}
		if ( isset( $data['ref'] ) && '' !== $data['ref'] && \Pothik\Referral::sanitize( $data['ref'] ) !== $data['ref'] ) {
			$error->add( 'pdock_invalid_field', 'Field "ref" must be 2-10 letters or digits.', array( 'field' => 'ref' ) );
		}
		if ( isset( $data['package_id'] ) && (int) $data['package_id'] > 0 && null === $this->package_row( (int) $data['package_id'] ) ) {
			$error->add( 'pdock_unresolved_relation', 'Field "package_id" points to a package that does not exist.', array( 'field' => 'package_id' ) );
		}
		return $error->has_errors() ? $error : true;
	}

	/**
	 * Kafela package row for an integer package id.
	 *
	 * @param int $package_id Integer id from PackageIds.
	 * @return array|null
	 */
	private function package_row( $package_id ) {
		$slug = PackageIds::slug_for( $package_id );
		if ( '' === $slug ) {
			return null;
		}
		Support::reset_package_memo();
		return \Pothik\PackageStore::find( $slug );
	}

	/**
	 * Slug and snapshot title for an integer package id (0 = no package).
	 *
	 * @param int $package_id Integer id.
	 * @return array array( slug, title )
	 */
	private function package_columns( $package_id ) {
		$row = (int) $package_id > 0 ? $this->package_row( (int) $package_id ) : null;
		if ( null === $row ) {
			return array( '', '' );
		}
		return array( (string) $row['id'], mb_substr( (string) ( isset( $row['title'] ) ? $row['title'] : '' ), 0, 120 ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When Kafela refuses the insert.
	 */
	public function create( array $data ) {
		global $wpdb;
		list( $slug, $title ) = $this->package_columns( isset( $data['package_id'] ) ? $data['package_id'] : 0 );

		$uuid    = Context::current_uuid();
		$replied = ! empty( $data['replied'] );
		$row     = array(
			'uuid'          => is_string( $uuid ) && '' !== $uuid ? $uuid : wp_generate_uuid4(),
			'name'          => isset( $data['name'] ) ? $data['name'] : '',
			'phone'         => isset( $data['phone'] ) ? $data['phone'] : '',
			'whatsapp'      => isset( $data['whatsapp'] ) ? $data['whatsapp'] : '',
			'city'          => isset( $data['city'] ) ? $data['city'] : '',
			'share'         => isset( $data['share'] ) ? $data['share'] : 'quad',
			'adults'        => isset( $data['adults'] ) ? $data['adults'] : 1,
			'children'      => isset( $data['children'] ) ? $data['children'] : 0,
			'infants'       => isset( $data['infants'] ) ? $data['infants'] : 0,
			'trip'          => isset( $data['trip'] ) ? $data['trip'] : 'group',
			'note'          => isset( $data['note'] ) ? $data['note'] : '',
			'package_id'    => $slug,
			'package_title' => $title,
			'status'        => $replied ? 'replied' : 'new',
			'replied'       => $replied ? 1 : 0,
			'demo'          => 0,
			'ref'           => isset( $data['ref'] ) ? $data['ref'] : '',
		);
		if ( ! empty( $data['created_gmt'] ) ) {
			$row['created_gmt'] = $data['created_gmt'];
		}

		$inserted = \Pothik\InquiryRepository::insert( $row ); // Also clears Kafela's "waiting" counter cache.
		if ( '' === $inserted ) {
			throw new \RuntimeException( 'Kafela could not save the inquiry.' );
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $this->full_table() . '` WHERE uuid = %s ORDER BY id DESC LIMIT 1', $inserted ) );

		Support::fire( 'kafela_inquiry', 'created', $inserted, $row );
		return $id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When the row is missing or the update fails.
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$current = $this->get( $id );
		if ( null === $current ) {
			throw new \RuntimeException( 'Inquiry not found.' );
		}

		$values  = array();
		$formats = array();
		foreach ( array( 'name', 'phone', 'whatsapp', 'city', 'share', 'trip', 'note' ) as $name ) {
			if ( array_key_exists( $name, $data ) ) {
				$values[ $name ] = $data[ $name ];
				$formats[]       = '%s';
			}
		}
		foreach ( array( 'adults', 'children', 'infants' ) as $name ) {
			if ( array_key_exists( $name, $data ) ) {
				$values[ $name ] = (int) $data[ $name ];
				$formats[]       = '%d';
			}
		}
		if ( array_key_exists( 'ref', $data ) ) {
			$values['ref'] = '' === $data['ref'] ? null : $data['ref'];
			$formats[]     = '%s';
		}
		if ( array_key_exists( 'package_id', $data ) && (int) $data['package_id'] !== (int) $current['package_id'] ) {
			list( $slug, $title )    = $this->package_columns( $data['package_id'] );
			$values['package_id']    = $slug;
			$values['package_title'] = $title; // Snapshot, refreshed only when the package changes.
			$formats[]               = '%s';
			$formats[]               = '%s';
		}
		$toggled = array_key_exists( 'replied', $data ) && (bool) $data['replied'] !== (bool) $current['replied'];
		if ( $toggled ) {
			$values['replied'] = $data['replied'] ? 1 : 0;
			$values['status']  = $data['replied'] ? 'replied' : 'waiting'; // Same mirror InquiryRepository::set_replied() writes.
			$formats[]         = '%d';
			$formats[]         = '%s';
		}

		if ( array() !== $values ) {
			$ok = $wpdb->update( $this->full_table(), $values, array( 'id' => (int) $id ), $formats, array( '%d' ) );
			if ( false === $ok ) {
				throw new \RuntimeException( 'Database update failed.' );
			}
			\Pothik\InquiryRepository::clear_waiting_cache();
		}
		if ( $toggled ) {
			Support::fire( 'kafela_inquiry', 'reply_toggled', (string) $current['uuid'], (bool) $data['replied'] );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $id, $force ) {
		unset( $force );
		$current = $this->get( $id );
		if ( null === $current ) {
			return;
		}
		$announce = (bool) apply_filters( 'pdock_kafela_fire_hooks', true, 'kafela_inquiry', 'deleted' );
		\Pothik\InquiryRepository::delete_by_id( (string) $current['uuid'], $announce );
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore( $id, array $before ) {
		global $wpdb;
		if ( null !== $this->get( $id ) ) {
			return true;
		}
		$slug = isset( $before['package_slug'] ) ? (string) $before['package_slug'] : PackageIds::slug_for( isset( $before['package_id'] ) ? $before['package_id'] : 0 );
		$row  = array(
			'id'            => (int) $id,
			'uuid'          => isset( $before['uuid'] ) ? (string) $before['uuid'] : wp_generate_uuid4(),
			'created_gmt'   => ! empty( $before['created_gmt'] ) ? (string) $before['created_gmt'] : gmdate( 'Y-m-d H:i:s' ),
			'name'          => isset( $before['name'] ) ? (string) $before['name'] : '',
			'phone'         => isset( $before['phone'] ) ? (string) $before['phone'] : '',
			'whatsapp'      => isset( $before['whatsapp'] ) ? (string) $before['whatsapp'] : '',
			'city'          => isset( $before['city'] ) ? (string) $before['city'] : '',
			'share'         => isset( $before['share'] ) ? (string) $before['share'] : 'quad',
			'adults'        => isset( $before['adults'] ) ? (int) $before['adults'] : 1,
			'children'      => isset( $before['children'] ) ? (int) $before['children'] : 0,
			'infants'       => isset( $before['infants'] ) ? (int) $before['infants'] : 0,
			'trip'          => isset( $before['trip'] ) ? (string) $before['trip'] : 'group',
			'note'          => isset( $before['note'] ) ? (string) $before['note'] : '',
			'package_id'    => $slug,
			'package_title' => isset( $before['package_title'] ) ? (string) $before['package_title'] : '',
			'status'        => isset( $before['status'] ) ? (string) $before['status'] : 'new',
			'replied'       => ! empty( $before['replied'] ) ? 1 : 0,
			'demo'          => ! empty( $before['demo'] ) ? 1 : 0,
			'ref'           => ! empty( $before['ref'] ) ? (string) $before['ref'] : null,
		);
		$ok   = $wpdb->insert( $this->full_table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ) );
		\Pothik\InquiryRepository::clear_waiting_cache();
		return false !== $ok;
	}

	/**
	 * Hook Kafela's admin and public handlers so their changes reach the change log.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$by_id = function ( $id ) {
			$row = \Pothik\InquiryRepository::find( (string) $id );
			return $row ? (int) $row['db_id'] : 0;
		};

		add_action(
			'pothik_inquiry_created',
			static function ( $uuid ) use ( $by_id ) {
				Support::track( 'kafela_inquiry', $by_id( $uuid ) );
			}
		);
		add_action(
			'pothik_inquiry_reply_toggled',
			static function ( $id ) use ( $by_id ) {
				Support::track( 'kafela_inquiry', $by_id( $id ) );
			}
		);
		add_action(
			'pothik_inquiry_deleted',
			static function ( $id, $before ) {
				$row_id = is_array( $before ) && isset( $before['id'] ) ? (int) $before['id'] : 0;
				Support::track( 'kafela_inquiry', $row_id, 'delete' );
			},
			10,
			2
		);

		// Bulk reply/delete: Kafela fires one hook without ids, so read the posted ids first.
		add_action(
			'admin_post_pothik_bulk_inquiries',
			function () use ( $by_id ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$posted     = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
				$this->bulk = array(
					'op'  => isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'ids' => array_values( array_filter( array_map( $by_id, array_map( 'sanitize_key', $posted ) ) ) ),
				);
			},
			1
		);
		add_action(
			'pothik_inquiries_bulk',
			function ( $op ) {
				foreach ( $this->bulk['ids'] as $row_id ) {
					$exists = null !== $this->get( $row_id );
					if ( 'delete' === $op && ! $exists ) {
						Support::track( 'kafela_inquiry', $row_id, 'delete' );
					} elseif ( 'reply' === $op && $exists ) {
						Support::track( 'kafela_inquiry', $row_id );
					}
				}
				$this->bulk = array(
					'op'  => '',
					'ids' => array(),
				);
			}
		);

		add_action(
			'pothik_demo_data',
			function ( $state ) {
				global $wpdb;
				if ( 'removed' === $state ) {
					Support::reconcile( 'kafela_inquiry' );
					return;
				}
				$table = $this->full_table();
				foreach ( (array) $wpdb->get_col( "SELECT id FROM `{$table}` WHERE demo = 1" ) as $row_id ) {
					Support::track( 'kafela_inquiry', (int) $row_id );
				}
			}
		);
	}
}
