<?php
/**
 * Regression tests for bugs found by the first real test run.
 *
 * @package Pressdock
 */

/**
 * Baseline and pagination rules.
 */
class RegressionTest extends Pdock_Test_Case {

	/**
	 * A pull must not bump the revision of a record the client just created.
	 */
	public function test_pull_does_not_bump_revision() {
		list( $id, , $rev ) = $this->push_create();
		$this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$this->assertSame( $rev, Pressdock\Store\Records::revision( 'post', $id ) );
	}

	/**
	 * Baseline adds no second change row for a record that already has one.
	 */
	public function test_baseline_adds_no_duplicate_rows() {
		list( $id ) = $this->push_create();
		global $wpdb;
		$table  = Pressdock\Installer::table( 'changes' );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE entity = 'post' AND object_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE entity = 'post' AND object_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $before, $after );
	}

	/**
	 * A record edited several times shows once in a paged walk, at its latest state.
	 */
	public function test_edited_record_appears_once_per_walk() {
		$id = self::factory()->post->create( array( 'post_title' => 'A', 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'B' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'C' ) );

		$seen   = array();
		$cursor = 0;
		$guard  = 0;
		do {
			$data = $this->pull( $cursor, array( 'entity' => 'post', 'limit' => 1 ) );
			foreach ( $data['items'] as $item ) {
				$seen[] = $item;
			}
			$cursor = $data['next_cursor'];
		} while ( $data['has_more'] && ++$guard < 30 );

		$mine = array_values( array_filter( $seen, static function ( $i ) use ( $id ) {
			return $i['remote_id'] === $id;
		} ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'C', $mine[0]['data']['title'] );
	}
}
