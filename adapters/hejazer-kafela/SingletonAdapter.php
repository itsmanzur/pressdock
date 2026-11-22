<?php
/**
 * Base for entities that are exactly one record kept in a wp_options row.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt and settings: object id is always 1. The option holds the whole record.
 */
abstract class SingletonAdapter implements EntityAdapter, EnumerableAdapter {

	const ID = 1;

	/**
	 * Arguments (label, group, enabled, can).
	 *
	 * @var array
	 */
	protected $args;

	/**
	 * Constructor.
	 *
	 * @param array $defaults Default arguments.
	 */
	public function __construct( array $defaults ) {
		$this->args = Support::args( $this->key(), $defaults );
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
	 * Kafela access area (receipt, settings).
	 *
	 * @return string
	 */
	abstract protected function area();

	/**
	 * Current record without its id, or null when none exists.
	 *
	 * @return array|null
	 */
	abstract protected function read();

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
		return Support::can( $this->area(), $action, $this->args );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		if ( self::ID !== (int) $id ) {
			return null;
		}
		$record = $this->read();
		return null === $record ? null : array_merge( array( 'id' => self::ID ), $record );
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		$out = array();
		if ( in_array( self::ID, array_map( 'intval', $ids ), true ) ) {
			$record = $this->get( self::ID );
			if ( null !== $record ) {
				$out[ self::ID ] = $record;
			}
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		unset( $limit );
		return (int) $after < self::ID && null !== $this->read() ? array( self::ID ) : array();
	}

	/**
	 * A second record cannot be created. Reported like a conflict that carries the existing record.
	 *
	 * @param string $what What already exists, for the message.
	 * @return \WP_Error|null
	 */
	protected function exists_error( $what ) {
		if ( null === $this->read() ) {
			return null;
		}
		return Support::unique_error( sprintf( 'The %s already exists. Update record %d instead of creating it.', $what, self::ID ), 'id', self::ID );
	}
}
