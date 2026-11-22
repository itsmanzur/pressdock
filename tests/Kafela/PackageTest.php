<?php
/**
 * Hejazer Kafela package adapter.
 *
 * @package Pressdock
 */

use Pressdock\Adapters\Kafela\PackageIds;

/**
 * Entity "kafela_package": option "pothik_packages".
 */
class Pdock_Kafela_Package_Test extends Pdock_Kafela_Test_Case {

	/**
	 * Create, update and delete through /push, with the pull view and the real option checked each time.
	 */
	public function test_create_update_delete_and_pull() {
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_package', $uuid, $this->package_data() ) );
		$this->assertSame( 'applied', $create['status'] );
		$id = $create['remote_id'];

		// Storage: one row in the real option, Kafela's own shape.
		$stored = get_option( 'pothik_packages' );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'Ramadan Umrah 15 days', $stored[0]['title'] );
		$this->assertSame( 'published', $stored[0]['status'] );
		$this->assertSame( 180000, $stored[0]['prices']['quad'] );
		$this->assertSame( 0, $stored[0]['prices']['single'], 'Missing price tiers are filled with 0.' );
		$this->assertFalse( $stored[0]['demo'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{8}$/', $stored[0]['id'], 'The id follows Kafela\'s own 8 character scheme.' );
		$this->assertSame( $stored[0]['id'], PackageIds::slug_for( $id ) );

		// Pull returns it, keyed by the same uuid.
		$items = $this->pull_entity( 'kafela_package' );
		$this->assertArrayHasKey( $id, $items );
		$this->assertSame( $uuid, $items[ $id ]['uuid'] );
		$this->assertSame( 'Ramadan Umrah 15 days', $items[ $id ]['data']['title'] );
		$this->assertSame( $stored[0]['id'], $items[ $id ]['data']['package_id'] );
		$this->assertSame( 1, $items[ $id ]['data']['position'] );

		// Partial update: prices merge per tier, other fields stay.
		$update = $this->push_one(
			$this->op(
				'update',
				'kafela_package',
				null,
				array(
					'title'  => 'Renamed',
					'prices' => array( 'single' => 300000 ),
				),
				array(
					'remote_id'     => $id,
					'base_revision' => $create['revision'],
				)
			)
		);
		$this->assertSame( 'applied', $update['status'] );
		$stored = get_option( 'pothik_packages' );
		$this->assertSame( 'Renamed', $stored[0]['title'] );
		$this->assertSame( 180000, $stored[0]['prices']['quad'] );
		$this->assertSame( 300000, $stored[0]['prices']['single'] );
		$this->assertSame( 15, $stored[0]['days'] );

		$delete = $this->push_one( $this->op( 'delete', 'kafela_package', null, null, array( 'remote_id' => $id, 'base_revision' => $update['revision'] ) ) );
		$this->assertSame( 'applied', $delete['status'] );
		$this->assertSame( array(), get_option( 'pothik_packages' ) );

		$items = $this->pull_entity( 'kafela_package' );
		$this->assertTrue( $items[ $id ]['deleted'] );
	}

	/**
	 * Computed and system fields are refused with a clear error and nothing is written.
	 */
	public function test_readonly_fields_are_rejected() {
		foreach ( array( 'demo', 'position' ) as $field ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( $field => 1 ) ) ) );
			$this->assertSame( 'error', $result['status'], $field );
			$this->assertSame( 'pdock_readonly_field', $result['error']['code'] );
			$this->assertSame( $field, $result['error']['field'] );
		}
		$this->assertEmpty( get_option( 'pothik_packages' ) );
	}

	/**
	 * The package id is chosen at creation and then fixed.
	 */
	public function test_package_id_is_create_only() {
		$created = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'umrah-march' ) ) ) );
		$this->assertSame( 'applied', $created['status'] );
		$this->assertSame( 'umrah-march', get_option( 'pothik_packages' )[0]['id'] );

		$update = $this->push_one(
			$this->op( 'update', 'kafela_package', null, array( 'package_id' => 'other' ), array( 'remote_id' => $created['remote_id'], 'base_revision' => $created['revision'] ) )
		);
		$this->assertSame( 'error', $update['status'] );
		$this->assertSame( 'pdock_readonly_field', $update['error']['code'] );
	}

	/**
	 * A duplicate package id is a conflict that points at the record already owning it.
	 */
	public function test_duplicate_package_id_conflicts() {
		$first = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'same1' ) ) ) );
		$this->assertSame( 'applied', $first['status'] );

		$second = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'SAME1' ) ) ) );
		$this->assertSame( 'conflict', $second['status'] );
		$this->assertSame( 'unique', $second['reason'] );
		$this->assertSame( 'package_id', $second['field'] );
		$this->assertSame( $first['remote_id'], $second['remote_id'] );
		$this->assertCount( 1, get_option( 'pothik_packages' ) );

		$sample = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'sample' ) ) ) );
		$this->assertSame( 'error', $sample['status'], '"sample" is reserved: Remove demo data would delete it.' );
	}

	/**
	 * Schema limits come from Kafela: enums, ranges, month format, required title.
	 */
	public function test_schema_validation() {
		$bad = array(
			array( 'status' => 'archived' ),
			array( 'days' => 400 ),
			array( 'departure' => '2027-13' ),
			array( 'prices' => array( 'quad' => -5 ) ),
			array( 'title' => '' ),
		);
		foreach ( $bad as $case ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( $case ) ) );
			$this->assertSame( 'error', $result['status'], wp_json_encode( $case ) );
		}
		$this->assertEmpty( get_option( 'pothik_packages' ) );
	}

	/**
	 * The Bangla overlay is written explicitly; an empty value removes the key (Kafela's fallback rule).
	 */
	public function test_bangla_overlay() {
		$created = $this->push_one(
			$this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'bn' => array( 'title' => 'রমজান ওমরাহ', 'inclusions' => array( 'ভিসা' ) ) ) ) )
		);
		$this->assertSame( 'applied', $created['status'] );
		$stored = get_option( 'pothik_packages' )[0];
		$this->assertSame( 'রমজান ওমরাহ', $stored['bn']['title'] );
		$this->assertSame( array( 'ভিসা' ), $stored['bn']['inclusions'] );

		$update = $this->push_one(
			$this->op( 'update', 'kafela_package', null, array( 'bn' => array( 'title' => '' ) ), array( 'remote_id' => $created['remote_id'], 'base_revision' => $created['revision'] ) )
		);
		$this->assertSame( 'applied', $update['status'] );
		$stored = get_option( 'pothik_packages' )[0];
		$this->assertArrayNotHasKey( 'title', $stored['bn'] );
		$this->assertSame( array( 'ভিসা' ), $stored['bn']['inclusions'] );
	}

	/**
	 * A package made in Kafela's own storage (not through sync) gets an id and shows up in the first pull.
	 */
	public function test_existing_packages_are_enumerated() {
		update_option( 'pothik_packages', array( array_merge( Pothik\PackageStore::blank(), array( 'id' => 'legacy01', 'title' => 'Old one' ) ) ), false );
		Pothik\PackageStore::all(); // Kafela's own memo, as a normal page load would fill it.
		Pressdock\Registry::reset();

		$items = $this->pull_entity( 'kafela_package' );
		$this->assertCount( 1, $items );
		$item = reset( $items );
		$this->assertSame( 'legacy01', $item['data']['package_id'] );
		$this->assertSame( 'Old one', $item['data']['title'] );
	}

	/**
	 * Undo of a delete puts the package back in the same position with the same id.
	 */
	public function test_undo_delete_restores_in_place() {
		$a = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'title' => 'A', 'package_id' => 'aaa' ) ) ) );
		$this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'title' => 'B', 'package_id' => 'bbb' ) ) ) );

		$batch = $this->uuid();
		$this->push( array( $this->op( 'delete', 'kafela_package', null, null, array( 'remote_id' => $a['remote_id'], 'base_revision' => $a['revision'] ) ) ), $batch );
		$this->assertCount( 1, get_option( 'pothik_packages' ) );

		$response = $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( get_option( 'pothik_packages' ), 'id' );
		$this->assertSame( array( 'aaa', 'bbb' ), $ids );
		$this->assertSame( $a['remote_id'], PackageIds::id_for( 'aaa', false ), 'The integer id survives the round trip.' );
	}
}
