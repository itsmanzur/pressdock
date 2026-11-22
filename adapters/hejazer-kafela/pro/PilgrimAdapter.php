<?php
/**
 * Hejazer Kafela Pro pilgrims (entity "kafela_pilgrim"), read only.
 *
 * Storage: table {prefix}pothik_pilgrims. Object id: the table id.
 * Contains personal data (passport number, date of birth): access follows Pro's "pilgrims" area.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pilgrim in one departure.
 */
final class PilgrimAdapter extends ReadOnlyTableAdapter {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'label'   => __( 'Pilgrims (read only)', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela Pro', 'pressdock' ),
				'enabled' => true,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_pilgrim';
	}

	/**
	 * {@inheritdoc}
	 */
	public function table() {
		return 'pothik_pilgrims';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function area() {
		return 'pilgrims';
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
		return array(
			'departure_id' => array(
				'type'   => 'relation',
				'target' => 'kafela_departure',
				'label'  => __( 'Group', 'pressdock' ),
			),
			'name'         => $string( __( 'Name', 'pressdock' ) ),
			'gender'       => $string( __( 'Gender', 'pressdock' ) ),
			'dob'          => $string( __( 'Date of birth', 'pressdock' ) ),
			'passport'     => $string( __( 'Passport number', 'pressdock' ) ),
			'expires'      => $string( __( 'Passport expires', 'pressdock' ) ),
			'share'        => $string( __( 'Room share', 'pressdock' ) ),
			'family'       => $string( __( 'Family', 'pressdock' ) ),
			'link_text'    => $string( __( 'Family link', 'pressdock' ) ),
			'visa'         => $string( __( 'Visa status', 'pressdock' ) ),
			'biometric'    => $string( __( 'Biometric', 'pressdock' ) ),
			'vaccine'      => $string( __( 'Vaccine', 'pressdock' ) ),
			'insurance'    => $string( __( 'Insurance', 'pressdock' ) ),
			'bn'           => array(
				'type'  => 'object',
				'label' => __( 'Bangla overlay', 'pressdock' ),
			),
			'sort_order'   => array(
				'type'  => 'int',
				'label' => __( 'Sort order', 'pressdock' ),
			),
			'agent_id'     => array(
				'type'        => 'int',
				'label'       => __( 'Agent id (Pro agent)', 'pressdock' ),
				'description' => 'Agents are not synced; this is only the number.',
			),
			'district'     => $string( __( 'District', 'pressdock' ) ),
			'created_at'   => $string( __( 'Created (GMT)', 'pressdock' ) ),
		);
	}

	/**
	 * Row to record: decode the Bangla overlay.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	protected function build( array $row ) {
		$out       = parent::build( $row );
		$out['bn'] = $this->json( $row['bn'] );
		return $out;
	}
}
