<?php
/**
 * Hejazer Kafela packages (entity "kafela_package").
 *
 * Storage: ONE wp_options row, "pothik_packages" (list of package arrays, autoload off).
 * Object id: integer from PackageIds (Kafela's own id is the 8 character string in field "package_id").
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;
use Pressdock\Contracts\RestorableAdapter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Packages never touch Kafela's private put(): the option is written the same way Kafela writes it
 * (a re-indexed list, autoload false) and Kafela's in-memory copy is dropped afterwards.
 */
final class PackageAdapter implements EntityAdapter, EnumerableAdapter, RestorableAdapter {

	const OPTION = 'pothik_packages';

	/**
	 * Price tiers, in Kafela's order.
	 *
	 * @var string[]
	 */
	const PRICE_KEYS = array( 'quad', 'triple', 'double', 'single', 'child_bed', 'child_nobed', 'infant' );

	/**
	 * Keys that may carry a Bangla overlay (PackageStore::merge_language).
	 *
	 * @var string[]
	 */
	const BN_TEXT = array( 'agency', 'kicker', 'title', 'route', 'group_note', 'makkah_hotel', 'makkah_similar', 'makkah_stars', 'madinah_hotel', 'madinah_similar', 'madinah_stars', 'food_note', 'docs', 'cancel' );

	/**
	 * Bangla overlay keys that hold a list.
	 *
	 * @var string[]
	 */
	const BN_LISTS = array( 'inclusions', 'exclusions', 'itinerary' );

	/**
	 * Arguments (label, group, enabled, can).
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->args = Support::args(
			'kafela_package',
			array(
				'label'   => __( 'Umrah packages', 'pressdock' ),
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
		return 'kafela_package';
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
	public function capability( $action ) {
		unset( $action );
		return 'manage_options';
	}

	/**
	 * Kafela's own permission model (Access::can).
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		return Support::can( 'packages', $action, $this->args );
	}

	/**
	 * Schema. Storage notes: every field is a key of the package row in option "pothik_packages".
	 *
	 * @return array
	 */
	public function schema() {
		$text = static function ( $label, $max, $extra = array() ) {
			return array_merge(
				array(
					'type'       => 'string',
					'label'      => $label,
					'max_length' => $max,
				),
				$extra
			);
		};
		$int  = static function ( $label, $max = 0 ) {
			return array(
				'type'  => 'int',
				'label' => $label,
				'min'   => 0,
				'max'   => $max,
			);
		};
		$list = static function ( $label ) {
			return array(
				'type'            => 'list',
				'label'           => $label,
				'max_items'       => 45,
				'item_max_length' => 180,
			);
		};

		$bn = array();
		foreach ( self::BN_TEXT as $name ) {
			$bn[ $name ] = array(
				'type'       => 'string',
				'max_length' => 'cancel' === $name ? 400 : 200,
			);
		}
		foreach ( self::BN_LISTS as $name ) {
			$bn[ $name ] = $list( $name );
		}

		$prices = array();
		foreach ( self::PRICE_KEYS as $tier ) {
			$prices[ $tier ] = array(
				'type'  => 'int',
				'label' => $tier,
				'min'   => 0,
			);
		}

		return array(
			'package_id'      => array(
				'type'        => 'string',
				'label'       => __( 'Package id', 'pressdock' ),
				'create_only' => true,
				'max_length'  => 50,
				'description' => 'Kafela package id (a-z, 0-9, _ and -). Generated when omitted. Never "sample".',
			),
			'position'        => array(
				'type'     => 'int',
				'label'    => __( 'Position', 'pressdock' ),
				'readonly' => true,
			),
			'status'          => array(
				'type'    => 'enum',
				'label'   => __( 'Published', 'pressdock' ),
				'options' => array( 'draft', 'published' ),
			),
			'title'           => $text( __( 'Package name', 'pressdock' ), 120, array( 'required' => true ) ),
			'kicker'          => $text( __( 'Eyebrow', 'pressdock' ), 80 ),
			'route'           => $text( __( 'Flight direction', 'pressdock' ), 80 ),
			'group_note'      => $text( __( 'Group note', 'pressdock' ), 80 ),
			'days'            => $int( __( 'Total days', 'pressdock' ), 365 ),
			'nights_makkah'   => $int( __( 'Makkah nights', 'pressdock' ), 365 ),
			'nights_madinah'  => $int( __( 'Madinah nights', 'pressdock' ), 365 ),
			'departure'       => $text( __( 'Departure month', 'pressdock' ), 20, array( 'description' => 'YYYY-MM or empty.' ) ),
			'is_ramadan'      => array(
				'type'  => 'bool',
				'label' => __( 'Ramadan departure', 'pressdock' ),
			),
			'makkah_hotel'    => $text( __( 'Makkah hotel', 'pressdock' ), 120 ),
			'makkah_similar'  => $text( __( 'Makkah: or similar', 'pressdock' ), 40 ),
			'makkah_stars'    => $text( __( 'Makkah stars', 'pressdock' ), 40 ),
			'makkah_meters'   => $int( __( 'Distance to the Haram (m)', 'pressdock' ) ),
			'madinah_hotel'   => $text( __( 'Madinah hotel', 'pressdock' ), 120 ),
			'madinah_similar' => $text( __( 'Madinah: or similar', 'pressdock' ), 40 ),
			'madinah_stars'   => $text( __( 'Madinah stars', 'pressdock' ), 40 ),
			'madinah_meters'  => $int( __( 'Distance to the Prophet\'s Mosque (m)', 'pressdock' ) ),
			'prices'          => array(
				'type'       => 'object',
				'label'      => __( 'Prices per person (BDT)', 'pressdock' ),
				'properties' => $prices,
			),
			'food_note'       => $text( __( 'Food note', 'pressdock' ), 160 ),
			'inclusions'      => $list( __( 'Included', 'pressdock' ) ),
			'exclusions'      => $list( __( 'Not included', 'pressdock' ) ),
			'itinerary'       => $list( __( 'Day by day', 'pressdock' ) ),
			'docs'            => $text( __( 'Documents', 'pressdock' ), 200 ),
			'cancel'          => array(
				'type'       => 'text',
				'label'      => __( 'Cancellation', 'pressdock' ),
				'max_length' => 400,
			),
			'agency'          => $text( __( 'Agency name', 'pressdock' ), 80 ),
			'phone'           => $text( __( 'Phone', 'pressdock' ), 40 ),
			'whatsapp'        => $text( __( 'WhatsApp', 'pressdock' ), 20 ),
			'demo'            => array(
				'type'     => 'bool',
				'label'    => __( 'Demo row', 'pressdock' ),
				'readonly' => true,
			),
			'bn'              => array(
				'type'       => 'object',
				'label'      => __( 'Bangla overlay', 'pressdock' ),
				'properties' => $bn,
			),
		);
	}

	/**
	 * Canonicalize before validation: Kafela runs sanitize_key on the id.
	 *
	 * @param array    $data Raw data.
	 * @param int|null $id   Object id for updates.
	 * @return array
	 */
	public function normalize( array $data, $id = null ) {
		unset( $id );
		if ( isset( $data['package_id'] ) && is_string( $data['package_id'] ) ) {
			$data['package_id'] = sanitize_key( $data['package_id'] );
		}
		if ( isset( $data['departure'] ) && is_string( $data['departure'] ) ) {
			$data['departure'] = trim( $data['departure'] );
		}
		return $data;
	}

	/**
	 * Business rules on top of schema validation.
	 *
	 * @param array    $data Clean data.
	 * @param int|null $id   Object id for updates.
	 * @return WP_Error|true
	 */
	public function validate( array $data, $id = null ) {
		$error = new WP_Error();

		if ( null === $id && isset( $data['package_id'] ) && '' !== $data['package_id'] ) {
			$slug = $data['package_id'];
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,50}$/', $slug ) || 'sample' === $slug ) {
				$error->add( 'pdock_invalid_field', 'Field "package_id" must be 1-50 characters of a-z, 0-9, _ or - and cannot be "sample".', array( 'field' => 'package_id' ) );
			} elseif ( null !== $this->find_row( $slug ) ) {
				return Support::unique_error( sprintf( 'A package with the id "%s" already exists.', $slug ), 'package_id', PackageIds::id_for( $slug ) );
			}
		}

		if ( isset( $data['departure'] ) && '' !== $data['departure'] && 1 !== preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $data['departure'] ) ) {
			$error->add( 'pdock_invalid_field', 'Field "departure" must be YYYY-MM or empty.', array( 'field' => 'departure' ) );
		}
		foreach ( array( 'days', 'nights_makkah', 'nights_madinah' ) as $name ) {
			if ( isset( $data[ $name ] ) && ! Support::in_range( $data[ $name ], 0, 365 ) ) {
				$error->add( 'pdock_invalid_field', sprintf( 'Field "%s" must be between 0 and 365.', $name ), array( 'field' => $name ) );
			}
		}
		foreach ( array( 'makkah_meters', 'madinah_meters' ) as $name ) {
			if ( isset( $data[ $name ] ) && ( ! is_int( $data[ $name ] ) || $data[ $name ] < 0 ) ) {
				$error->add( 'pdock_invalid_field', sprintf( 'Field "%s" cannot be negative.', $name ), array( 'field' => $name ) );
			}
		}
		if ( isset( $data['prices'] ) && is_array( $data['prices'] ) ) {
			foreach ( $data['prices'] as $tier => $value ) {
				if ( ! is_int( $value ) || $value < 0 ) {
					$error->add( 'pdock_invalid_field', sprintf( 'Price "%s" cannot be negative.', $tier ), array( 'field' => 'prices.' . $tier ) );
				}
			}
		}
		return $error->has_errors() ? $error : true;
	}

	/**
	 * Rows as Kafela reads them (the in-memory copy is refreshed first).
	 *
	 * @return array
	 */
	private function rows() {
		Support::reset_package_memo();
		return \Pothik\PackageStore::all();
	}

	/**
	 * One row by Kafela id.
	 *
	 * @param string $slug Kafela package id.
	 * @return array|null
	 */
	private function find_row( $slug ) {
		foreach ( $this->rows() as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === (string) $slug ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Persist the rows the way Kafela does.
	 *
	 * @param array $rows Rows.
	 * @return void
	 */
	private function save( array $rows ) {
		update_option( self::OPTION, array_values( $rows ), false );
		Support::reset_package_memo();
	}

	/**
	 * A row as a record.
	 *
	 * @param array $row      Kafela row.
	 * @param int   $position 1-based position in the option.
	 * @return array
	 */
	private function present( array $row, $position ) {
		$out = array(
			'id'         => PackageIds::id_for( $row['id'] ),
			'package_id' => (string) $row['id'],
			'position'   => $position,
		);
		foreach ( $this->schema() as $name => $field ) {
			if ( 'package_id' === $name || 'position' === $name ) {
				continue;
			}
			$value = isset( $row[ $name ] ) ? $row[ $name ] : null;
			switch ( $field['type'] ) {
				case 'int':
					$value = (int) $value;
					break;
				case 'bool':
					$value = ! empty( $value );
					break;
				case 'list':
					$value = is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
					break;
				case 'object':
					$value = is_array( $value ) ? $value : array();
					if ( 'prices' === $name ) {
						$clean = array();
						foreach ( self::PRICE_KEYS as $tier ) {
							$clean[ $tier ] = isset( $value[ $tier ] ) ? absint( $value[ $tier ] ) : 0;
						}
						$value = $clean;
					} elseif ( array() === $value ) {
						$value = new \stdClass();
					}
					break;
				default:
					$value = null === $value ? '' : (string) $value;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		$slug = PackageIds::slug_for( $id );
		if ( '' === $slug ) {
			return null;
		}
		foreach ( array_values( $this->rows() ) as $index => $row ) {
			if ( (string) $row['id'] === $slug ) {
				return $this->present( $row, $index + 1 );
			}
		}
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		$want = array_flip( array_map( 'intval', $ids ) );
		$out  = array();
		foreach ( array_values( $this->rows() ) as $index => $row ) {
			$id = PackageIds::id_for( $row['id'] );
			if ( isset( $want[ $id ] ) ) {
				$out[ $id ] = $this->present( $row, $index + 1 );
			}
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		$ids = array();
		foreach ( $this->rows() as $row ) {
			$id = PackageIds::id_for( $row['id'] );
			if ( $id > (int) $after ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, (int) $limit );
	}

	/**
	 * A fresh 8 character id, like PackageStore::fresh_id().
	 *
	 * @param array $rows Rows in use.
	 * @return string
	 */
	private function fresh_id( array $rows ) {
		$used = array();
		foreach ( $rows as $row ) {
			$used[ (string) $row['id'] ] = true;
		}
		do {
			$slug = strtolower( wp_generate_password( 8, false, false ) );
			$slug = preg_replace( '/[^a-z0-9]/', '', $slug );
		} while ( '' === $slug || isset( $used[ $slug ] ) );
		return $slug;
	}

	/**
	 * Merge clean field data into a row.
	 *
	 * @param array $row  Existing (or blank) row.
	 * @param array $data Clean data.
	 * @return array
	 */
	private function merge( array $row, array $data ) {
		foreach ( $this->schema() as $name => $field ) {
			if ( ! array_key_exists( $name, $data ) || ! empty( $field['readonly'] ) || ! empty( $field['create_only'] ) || 'bn' === $name ) {
				continue;
			}
			$value = $data[ $name ];
			if ( 'prices' === $name ) {
				$prices = isset( $row['prices'] ) && is_array( $row['prices'] ) ? $row['prices'] : array();
				foreach ( self::PRICE_KEYS as $tier ) {
					$prices[ $tier ] = isset( $value[ $tier ] ) ? absint( $value[ $tier ] ) : ( isset( $prices[ $tier ] ) ? absint( $prices[ $tier ] ) : 0 );
				}
				$value = $prices;
			}
			$row[ $name ] = $value;
		}

		if ( isset( $data['bn'] ) && is_array( $data['bn'] ) ) {
			$overlay = isset( $row['bn'] ) && is_array( $row['bn'] ) ? $row['bn'] : array();
			foreach ( $data['bn'] as $key => $value ) {
				if ( '' === $value || array() === $value ) {
					unset( $overlay[ $key ] ); // Same rule as Kafela: an empty Bangla value falls back to English.
				} else {
					$overlay[ $key ] = $value;
				}
			}
			$row['bn'] = $overlay;
		}
		return $row;
	}

	/**
	 * {@inheritdoc}
	 */
	public function create( array $data ) {
		$rows = array_values( $this->rows() );
		$slug = isset( $data['package_id'] ) && '' !== $data['package_id'] ? $data['package_id'] : $this->fresh_id( $rows );

		$row         = $this->merge( \Pothik\PackageStore::blank(), $data );
		$row['id']   = $slug;
		$row['demo'] = false; // A synced row must never be wiped by "Remove demo data".
		if ( empty( $row['status'] ) ) {
			$row['status'] = 'draft';
		}
		$rows[] = $row; // Kafela appends new packages.
		$this->save( $rows );

		Support::fire( 'kafela_package', 'created', $slug, $row );
		return PackageIds::id_for( $slug );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When the package does not exist.
	 */
	public function update( $id, array $data ) {
		$slug = PackageIds::slug_for( $id );
		$rows = array_values( $this->rows() );
		foreach ( $rows as $index => $row ) {
			if ( (string) $row['id'] !== $slug ) {
				continue;
			}
			$after          = $this->merge( $row, $data );
			$after['id']    = $row['id'];
			$after['demo']  = ! empty( $row['demo'] );
			$rows[ $index ] = $after;
			$this->save( $rows );
			Support::fire( 'kafela_package', 'saved', $slug, $row, $after );
			return;
		}
		throw new \RuntimeException( 'Package not found.' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $id, $force ) {
		unset( $force );
		$slug = PackageIds::slug_for( $id );
		$rows = array_values( $this->rows() );
		foreach ( $rows as $index => $row ) {
			if ( (string) $row['id'] === $slug ) {
				unset( $rows[ $index ] );
				$this->save( $rows );
				// Kafela leaves inquiries pointing at the dead id, so does this.
				Support::fire( 'kafela_package', 'deleted', $slug, $row );
				return;
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore( $id, array $before ) {
		$slug = PackageIds::slug_for( $id );
		if ( '' === $slug ) {
			return false;
		}
		$rows = array_values( $this->rows() );
		foreach ( $rows as $row ) {
			if ( (string) $row['id'] === $slug ) {
				return true;
			}
		}
		$clean       = \Pressdock\Sync\Validator::clean( $this->schema(), $before );
		$row         = $this->merge( \Pothik\PackageStore::blank(), $clean );
		$row['id']   = $slug;
		$row['demo'] = ! empty( $before['demo'] );
		$position    = isset( $before['position'] ) ? max( 1, (int) $before['position'] ) : count( $rows ) + 1;
		array_splice( $rows, min( $position - 1, count( $rows ) ), 0, array( $row ) );
		$this->save( $rows );
		return true;
	}

	/**
	 * Hook Kafela's admin screens so edits made there reach the change log.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$touch = static function ( $slug ) {
			Support::track( 'kafela_package', PackageIds::id_for( $slug ) );
		};
		add_action(
			'pothik_package_created',
			static function ( $slug ) use ( $touch ) {
				$touch( $slug );
			}
		);
		add_action(
			'pothik_package_saved',
			static function ( $slug ) use ( $touch ) {
				$touch( $slug );
			}
		);
		add_action(
			'pothik_package_status_changed',
			static function ( $slug ) use ( $touch ) {
				$touch( $slug );
			}
		);
		add_action(
			'pothik_package_deleted',
			static function ( $slug ) {
				Support::track( 'kafela_package', PackageIds::id_for( $slug ), 'delete' );
			}
		);
		add_action(
			'pothik_demo_data',
			function ( $state ) use ( $touch ) {
				if ( 'removed' === $state ) {
					Support::reconcile( 'kafela_package' );
					return;
				}
				foreach ( $this->rows() as $row ) {
					if ( ! empty( $row['demo'] ) ) {
						$touch( $row['id'] );
					}
				}
			}
		);
	}
}
