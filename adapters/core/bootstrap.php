<?php
/**
 * Registers the demo "post" adapter. Core posts are always available.
 *
 * Bundled adapters for other plugins follow the same pattern and only register
 * when their plugin is active. Check inside the filter callback (for example
 * `if ( ! defined( 'POTHIK_VERSION' ) ) { return $adapters; }`) because the filter runs
 * lazily, after every plugin has loaded.
 *
 * @package Pressdock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/PostAdapter.php';

add_filter(
	'pdock_register_entities',
	static function ( $adapters ) {
		$adapters[] = new Pressdock\Adapters\Core\PostAdapter();
		return $adapters;
	}
);

// Post types an administrator added under Pressdock → Data sources (no code needed).
// Priority 30: dedicated adapters registered earlier win for the same post type.
add_filter(
	'pdock_register_entities',
	static function ( $adapters ) {
		return array_merge( (array) $adapters, Pressdock\Adapters\PostTypes::adapters( (array) $adapters ) );
	},
	30
);
