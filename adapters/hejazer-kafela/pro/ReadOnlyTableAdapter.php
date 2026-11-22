<?php
/**
 * Base for read-only views of Hejazer Kafela Pro tables.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela\Pro;

use Pressdock\Adapters\AbstractTableAdapter;
use Pressdock\Adapters\Kafela\Support;
use Pressdock\Adapters\Kafela\TableScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integer primary key, every field readonly, writes refused. Pro fires no hooks, so refresh() (called before a
 * pull) detects changes made in Pro's screens.
 */
abstract class ReadOnlyTableAdapter extends AbstractTableAdapter {

	/**
	 * Arguments (label, group, enabled, can).
	 *
	 * @var array
	 */
	protected $args;

	/**
	 * Change detection.
	 *
	 * @var TableScanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param array $defaults Default arguments (label, group, enabled).
	 */
	public function __construct( array $defaults ) {
		$this->args    = Support::args( $this->key(), $defaults );
		$this->scanner = new TableScanner( $this->key(), $this->table(), 'id', null );
	}

	/**
	 * Pro access area (groups, pilgrims, ...).
	 *
	 * @return string
	 */
	abstract protected function area();

	/**
	 * Field definitions without the readonly flag; it is added here.
	 *
	 * @return array
	 */
	abstract protected function columns();

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
	public function fields() {
		$out = array();
		foreach ( $this->columns() as $name => $field ) {
			$out[ $name ] = array_merge( $field, array( 'readonly' => true ) );
		}
		return $out;
	}

	/**
	 * Read only; staff limited to some groups are refused (see Support::can_pro()).
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		return 'read' === $action && Support::can_pro( $this->area(), 'read', $this->args );
	}

	/**
	 * Decode a JSON column (Pro stores the Bangla overlay as JSON text).
	 *
	 * @param mixed $raw Column value.
	 * @return array|\stdClass
	 */
	protected function json( $raw ) {
		$value = json_decode( (string) $raw, true );
		return is_array( $value ) && array() !== $value ? $value : new \stdClass();
	}

	/**
	 * Detect changes made in Pro's own screens.
	 *
	 * @return void
	 */
	public function refresh() {
		$this->scanner->refresh();
	}

	/**
	 * Refused.
	 *
	 * @param array $data Data.
	 * @return int
	 * @throws \RuntimeException Always.
	 */
	public function create( array $data ) {
		unset( $data );
		throw new \RuntimeException( 'This entity is read only.' );
	}

	/**
	 * Refused.
	 *
	 * @param int   $id   Id.
	 * @param array $data Data.
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function update( $id, array $data ) {
		unset( $id, $data );
		throw new \RuntimeException( 'This entity is read only.' );
	}

	/**
	 * Refused.
	 *
	 * @param int  $id    Id.
	 * @param bool $force Force.
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function delete( $id, $force ) {
		unset( $id, $force );
		throw new \RuntimeException( 'This entity is read only.' );
	}
}
