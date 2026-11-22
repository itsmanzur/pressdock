<?php
/**
 * Hejazer Kafela Pro groups (entity "kafela_departure"), read only.
 *
 * Storage: table {prefix}pothik_departures. Object id: the table id.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela\Pro;

use Pressdock\Adapters\Kafela\PackageIds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A departure (group) is a dated trip of one package: dates, flight, seats, leaders and visa deadlines.
 */
final class DepartureAdapter extends ReadOnlyTableAdapter {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'label'   => __( 'Groups / departures (read only)', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela Pro', 'pressdock' ),
				'enabled' => true,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_departure';
	}

	/**
	 * {@inheritdoc}
	 */
	public function table() {
		return 'pothik_departures';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function area() {
		return 'groups';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function columns() {
		$string = static function ( $label ) {
			return array(
				'type'  => 'string',
				'label' => $label,
			);
		};
		$int    = static function ( $label ) {
			return array(
				'type'  => 'int',
				'label' => $label,
			);
		};
		return array(
			'name'         => $string( __( 'Name', 'pressdock' ) ),
			'name_bn'      => $string( __( 'Name (বাংলা)', 'pressdock' ) ),
			'type'         => $string( __( 'Type', 'pressdock' ) ),
			'package_id'   => array(
				'type'   => 'relation',
				'target' => 'kafela_package',
				'label'  => __( 'Package', 'pressdock' ),
				'column' => 'package_id',
			),
			'package_slug' => array(
				'type'   => 'string',
				'label'  => __( 'Package ID (Kafela string)', 'pressdock' ),
				'column' => 'package_id',
			),
			'status'       => $string( __( 'Status', 'pressdock' ) ),
			'leave_date'   => $string( __( 'Leaves', 'pressdock' ) ),
			'return_date'  => $string( __( 'Returns', 'pressdock' ) ),
			'route'        => $string( __( 'Route', 'pressdock' ) ),
			'origin'       => $string( __( 'Origin airport', 'pressdock' ) ),
			'airline'      => $string( __( 'Airline', 'pressdock' ) ),
			'flight'       => $string( __( 'Flight', 'pressdock' ) ),
			'seats'        => $int( __( 'Seats', 'pressdock' ) ),
			'held'         => $int( __( 'Held seats', 'pressdock' ) ),
			'company'      => $string( __( 'Saudi company', 'pressdock' ) ),
			'program'      => $string( __( 'Program', 'pressdock' ) ),
			'leader'       => $string( __( 'Group leader', 'pressdock' ) ),
			'leader_phone' => $string( __( 'Leader phone', 'pressdock' ) ),
			'guide'        => $string( __( 'Guide', 'pressdock' ) ),
			'guide_phone'  => $string( __( 'Guide phone', 'pressdock' ) ),
			'visa_open'    => $string( __( 'Visa opens', 'pressdock' ) ),
			'visa_last'    => $string( __( 'Visa last day', 'pressdock' ) ),
			'entry_last'   => $string( __( 'Last entry', 'pressdock' ) ),
			'depart_last'  => $string( __( 'Last departure', 'pressdock' ) ),
			'color'        => $string( __( 'Colour', 'pressdock' ) ),
			'bn'           => array(
				'type'  => 'object',
				'label' => __( 'Bangla overlay', 'pressdock' ),
			),
			'sort_order'   => $int( __( 'Sort order', 'pressdock' ) ),
		);
	}

	/**
	 * Row to record: the package string becomes the package object id, the raw string stays readable.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	protected function build( array $row ) {
		$out                 = parent::build( $row );
		$slug                = (string) $row['package_id'];
		$out['package_id']   = PackageIds::id_for( $slug, false );
		$out['package_slug'] = $slug;
		$out['bn']           = $this->json( $row['bn'] );
		return $out;
	}
}
