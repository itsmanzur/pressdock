<?php
/**
 * Integer ids for records that Kafela identifies by a string.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pressdock needs integer object ids. A number is never reused, so a deleted record keeps its id.
 * State lives in one option: array( 'next' => int, 'map' => array( string => int ) ).
 */
final class StringIdMap {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Constructor.
	 *
	 * @param string $option Option name.
	 */
	public function __construct( $option ) {
		$this->option = $option;
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	private function state() {
		$state = get_option( $this->option, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'next' => max( 1, isset( $state['next'] ) ? (int) $state['next'] : 1 ),
			'map'  => isset( $state['map'] ) && is_array( $state['map'] ) ? $state['map'] : array(),
		);
	}

	/**
	 * Integer ids for several strings with a single write.
	 *
	 * @param string[] $slugs  Strings.
	 * @param bool     $create Allocate numbers for new strings.
	 * @return array string => int (0 for unknown when not creating)
	 */
	public function bulk( array $slugs, $create = true ) {
		$state   = $this->state();
		$out     = array();
		$changed = false;
		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $state['map'][ $slug ] ) ) {
				$out[ $slug ] = (int) $state['map'][ $slug ];
			} elseif ( $create ) {
				$out[ $slug ]          = $state['next'];
				$state['map'][ $slug ] = $state['next'];
				++$state['next'];
				$changed = true;
			} else {
				$out[ $slug ] = 0;
			}
		}
		if ( $changed ) {
			update_option( $this->option, $state, false );
		}
		return $out;
	}

	/**
	 * Integer id of one string.
	 *
	 * @param string $slug   String.
	 * @param bool   $create Allocate a number when new.
	 * @return int
	 */
	public function id_for( $slug, $create = true ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return 0;
		}
		$map = $this->bulk( array( $slug ), $create );
		return isset( $map[ $slug ] ) ? $map[ $slug ] : 0;
	}

	/**
	 * String of an integer id.
	 *
	 * @param int $id Integer id.
	 * @return string Empty when unknown.
	 */
	public function slug_for( $id ) {
		$id = (int) $id;
		foreach ( $this->state()['map'] as $slug => $number ) {
			if ( (int) $number === $id ) {
				return (string) $slug;
			}
		}
		return '';
	}
}
