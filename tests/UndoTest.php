<?php
/**
 * Undo of a pushed batch.
 *
 * @package Pressdock
 */

/**
 * Tests for POST /undo.
 */
class UndoTest extends Pdock_Test_Case {

	/**
	 * Undo restores the title an update replaced.
	 */
	public function test_undo_update() {
		list( $id, $uuid, $rev ) = $this->push_create( 'Before' );
		$batch                   = $this->uuid();

		$this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'update',
					'entity'        => 'post',
					'uuid'          => $uuid,
					'base_revision' => $rev,
					'data'          => array( 'title' => 'After' ),
				),
			),
			$batch
		);
		$this->assertSame( 'After', get_the_title( $id ) );

		$response = $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['restored'] );
		$this->assertSame( 'Before', get_the_title( $id ) );
	}

	/**
	 * Undo of a create removes the record and leaves a tombstone.
	 */
	public function test_undo_create() {
		$batch = $this->uuid();
		$uuid  = $this->uuid();
		$res   = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $uuid,
					'data'   => array( 'title' => 'Temp' ),
				),
			),
			$batch
		);
		$id    = $res[0]['remote_id'];
		$this->assertNotNull( get_post( $id ) );

		$this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertNull( get_post( $id ) );

		$row = Pressdock\Store\Records::find( 'post', $id );
		$this->assertSame( 1, (int) $row['deleted'] );
	}

	/**
	 * Undo of a delete brings the post back under its original id.
	 */
	public function test_undo_delete_restores_in_place() {
		list( $id, $uuid, $rev ) = $this->push_create( 'Precious' );
		$batch                   = $this->uuid();

		$this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'delete',
					'entity'        => 'post',
					'uuid'          => $uuid,
					'base_revision' => $rev,
				),
			),
			$batch
		);
		$this->assertTrue( null === get_post( $id ) || 'trash' === get_post_status( $id ) );

		$this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( 'Precious', get_the_title( $id ) );
		$this->assertNotSame( 'trash', get_post_status( $id ) );

		$row = Pressdock\Store\Records::find( 'post', $id );
		$this->assertSame( 0, (int) $row['deleted'] );
		$this->assertSame( $uuid, $row['uuid'] );
	}

	/**
	 * Undone batches cannot be undone twice.
	 */
	public function test_undo_twice_is_404() {
		$batch = $this->uuid();
		$this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array( 'title' => 'Once' ),
				),
			),
			$batch
		);
		$this->assertSame( 200, $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) )->get_status() );
	}

	/**
	 * Snapshots are pruned after the retention period.
	 */
	public function test_prune_removes_old_snapshots() {
		global $wpdb;
		Pressdock\Store\Snapshots::add( 'oldbatch-1', 'post', 1, 'update', array( 'title' => 'x' ) );
		$wpdb->query( "UPDATE {$wpdb->prefix}pdock_snapshots SET created_at = '2000-01-01 00:00:00'" ); // phpcs:ignore WordPress.DB

		Pressdock\Plugin::prune();
		$this->assertSame( array(), Pressdock\Store\Snapshots::for_batch( 'oldbatch-1' ) );
	}
}
