<?php
/**
 * Hejazer Kafela Pro departure and pilgrim adapters (read only).
 *
 * @package Pressdock
 */

/**
 * Entities "kafela_departure" and "kafela_pilgrim".
 */
class Pdock_Kafela_Pro_Group_Test extends Pdock_Kafela_Test_Case {

	/**
	 * Departure id.
	 *
	 * @var int
	 */
	private $departure = 0;

	/**
	 * Pilgrim id.
	 *
	 * @var int
	 */
	private $pilgrim = 0;

	/**
	 * Set up: a package, a group of that package and one pilgrim.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! defined( 'POTHIK_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Hejazer Kafela Pro is not active.' );
		}
		parent::set_up();
		global $wpdb;
		$p = $wpdb->prefix;

		update_option( 'pothik_packages', array( array_merge( Pothik\PackageStore::blank(), array( 'id' => 'march01', 'title' => 'March Umrah' ) ) ), false );
		Pressdock\Adapters\Kafela\Support::reset_package_memo();

		$wpdb->insert(
			"{$p}pothik_departures",
			array(
				'name'       => 'March group',
				'package_id' => 'march01',
				'leave_date' => '2027-03-05',
				'seats'      => 40,
				'bn'         => wp_json_encode( array( 'name' => 'মার্চ গ্রুপ' ) ),
			)
		);
		$this->departure = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}pothik_pilgrims", array( 'departure_id' => $this->departure, 'name' => 'Karim', 'passport' => 'ZP1234567', 'gender' => 'male', 'visa' => 'draft', 'bn' => '{}' ) );
		$this->pilgrim = (int) $wpdb->insert_id;

		foreach ( array( 'kafela_departure', 'kafela_pilgrim' ) as $entity ) {
			delete_option( 'pdock_kafela_fp_' . $entity );
			delete_transient( 'pdock_kafela_scan_' . $entity );
		}
	}

	/**
	 * Pull returns the group with its package relation and Bangla overlay.
	 */
	public function test_departure_is_pulled() {
		$package = $this->pull_entity( 'kafela_package' );
		$package = reset( $package );

		$items = $this->pull_entity( 'kafela_departure' );
		$this->assertArrayHasKey( $this->departure, $items );
		$data = $items[ $this->departure ]['data'];
		$this->assertSame( 'March group', $data['name'] );
		$this->assertSame( 40, $data['seats'] );
		$this->assertSame( 'march01', $data['package_slug'] );
		$this->assertSame( $package['remote_id'], $data['package_id'], 'The package string becomes the package object id.' );
		$this->assertSame( array( 'name' => 'মার্চ গ্রুপ' ), $data['bn'] );
	}

	/**
	 * Pull returns the pilgrim with its group as a relation.
	 */
	public function test_pilgrim_is_pulled() {
		$items = $this->pull_entity( 'kafela_pilgrim' );
		$this->assertArrayHasKey( $this->pilgrim, $items );
		$data = $items[ $this->pilgrim ]['data'];
		$this->assertSame( 'Karim', $data['name'] );
		$this->assertSame( 'ZP1234567', $data['passport'] );
		$this->assertSame( $this->departure, $data['departure_id'] );
	}

	/**
	 * Both entities refuse every write.
	 */
	public function test_writes_are_refused() {
		foreach ( array( 'kafela_departure' => $this->departure, 'kafela_pilgrim' => $this->pilgrim ) as $entity => $id ) {
			$items = $this->pull_entity( $entity );
			$rev   = $items[ $id ]['revision'];
			$ops   = array(
				$this->op( 'create', $entity, $this->uuid(), array( 'name' => 'X' ) ),
				$this->op( 'update', $entity, null, array( 'name' => 'Y' ), array( 'remote_id' => $id, 'base_revision' => $rev ) ),
				$this->op( 'delete', $entity, null, null, array( 'remote_id' => $id, 'base_revision' => $rev ) ),
			);
			foreach ( $this->push( $ops ) as $result ) {
				$this->assertSame( 'pdock_forbidden', $result['error']['code'], $entity );
			}
		}
		global $wpdb;
		$this->assertSame( 'Karim', $wpdb->get_var( "SELECT name FROM {$wpdb->prefix}pothik_pilgrims WHERE id = {$this->pilgrim}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * An edit made in Pro's own screen reaches the next pull; a removed row becomes a tombstone.
	 */
	public function test_changes_made_in_pro_are_picked_up() {
		global $wpdb;
		$this->pull_entity( 'kafela_pilgrim' ); // First scan: fingerprints only.
		$cursor = Pressdock\Store\Changes::max_id();

		$wpdb->update( "{$wpdb->prefix}pothik_pilgrims", array( 'visa' => 'issued' ), array( 'id' => $this->pilgrim ) );
		delete_transient( 'pdock_kafela_scan_kafela_pilgrim' );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_pilgrim' ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'issued', $data['items'][0]['data']['visa'] );

		$wpdb->delete( "{$wpdb->prefix}pothik_pilgrims", array( 'id' => $this->pilgrim ) );
		delete_transient( 'pdock_kafela_scan_kafela_pilgrim' );
		$data = $this->pull( $data['next_cursor'], array( 'entity' => 'kafela_pilgrim' ) );
		$this->assertTrue( $data['items'][0]['deleted'] );
	}

	/**
	 * Staff limited to some groups cannot pull pilgrims (passport data).
	 */
	public function test_group_limited_staff_are_refused() {
		$user = self::factory()->user->create( array( 'role' => 'pothik_manager' ) );
		wp_set_current_user( $user );
		update_user_meta( $user, PothikPro\Access::META, wp_json_encode( array( $this->departure ) ) );

		$response = $this->call( 'GET', '/pull', null, array( 'since' => 0, 'entity' => 'kafela_pilgrim' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
