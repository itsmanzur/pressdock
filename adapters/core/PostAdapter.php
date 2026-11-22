<?php
/**
 * Demo adapter for core posts (title, content, status).
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Core;

use Pressdock\Adapters\AbstractPostAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proves the sync flow end to end. Entity key: "post".
 */
final class PostAdapter extends AbstractPostAdapter {

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Posts', 'pressdock' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function group() {
		return __( 'WordPress core', 'pressdock' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function post_type() {
		return 'post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function fields() {
		return array(
			'title'   => array(
				'type'     => 'string',
				'label'    => __( 'Title', 'pressdock' ),
				'required' => true,
				'storage'  => array( 'post_field' => 'post_title' ),
			),
			'content' => array(
				'type'    => 'html',
				'label'   => __( 'Content', 'pressdock' ),
				'storage' => array( 'post_field' => 'post_content' ),
			),
			'status'  => array(
				'type'    => 'enum',
				'label'   => __( 'Status', 'pressdock' ),
				'options' => array( 'draft', 'pending', 'private', 'publish' ),
				'storage' => array( 'post_field' => 'post_status' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( array $data, $id = null ) {
		unset( $id );
		if ( isset( $data['status'] ) ) {
			return $this->check_status_capability( $data['status'] );
		}
		return true;
	}
}
