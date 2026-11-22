<?php
/**
 * Cursor pull and pagination.
 *
 * @package Pressdock
 */

/**
 * Tests for GET /pull.
 */
class PullTest extends Pdock_Test_Case {

	/**
	 * Pages are stable, ordered and complete, and has_more flips to false at the end.
	 */
	public function test_cursor_pagination() {
		$ids = self::factory()->post->create_many( 5, array( 'post_status' => 'draft' ) );

		$seen   = array();
		$cursor = 0;
		$pages  = 0;
		do {
			$data = $this->pull( $cursor, array( 'entity' => 'post', 'limit' => 2 ) );
			foreach ( $data['items'] as $item ) {
				$seen[] = $item['remote_id'];
				$this->assertGreaterThan( $cursor, $item['cursor'] );
			}
			$this->assertGreaterThanOrEqual( $cursor, $data['next_cursor'] );
			$cursor = $data['next_cursor'];
			++$pages;
			$this->assertLessThan( 20, $pages, 'Pagination must terminate.' );
		} while ( $data['has_more'] );

		foreach ( $ids as $id ) {
			$this->assertContains( $id, $seen );
		}
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'Each record appears once per cursor walk.' );
	}

	/**
	 * Pulling from the returned cursor yields nothing new.
	 */
	public function test_pull_from_latest_cursor_is_empty() {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$first = $this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$this->assertFalse( $first['has_more'] );

		$second = $this->pull( $first['next_cursor'], array( 'entity' => 'post' ) );
		$this->assertSame( array(), $second['items'] );
		$this->assertFalse( $second['has_more'] );
	}

	/**
	 * Many changes to one record collapse to its latest state.
	 */
	public function test_repeated_edits_collapse() {
		$id = self::factory()->post->create( array( 'post_title' => 'One', 'post_status' => 'draft' ) );
		$this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$cursor = Pressdock\Store\Changes::max_id();

		wp_update_post( array( 'ID' => $id, 'post_title' => 'Two' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Three' ) );

		$data = $this->pull( $cursor, array( 'entity' => 'post' ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'Three', $data['items'][0]['data']['title'] );
	}

	/**
	 * Records that existed before the connector are returned by an initial pull.
	 */
	public function test_initial_pull_includes_pre_existing_records() {
		$id = self::factory()->post->create( array( 'post_title' => 'Old', 'post_status' => 'publish' ) );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pdock_changes" ); // phpcs:ignore WordPress.DB
		delete_option( 'pdock_baseline' );

		$data = $this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$ids  = wp_list_pluck( $data['items'], 'remote_id' );
		$this->assertContains( $id, $ids );
	}
}
