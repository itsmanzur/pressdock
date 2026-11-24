<?php
/**
 * Vendor-free PSR-4 autoloader.
 *
 * @package Pressdock
 */

namespace Pressdock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the Pressdock\ namespace to a directory.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @param string $base_dir Directory that holds the namespace root.
	 * @return void
	 */
	public static function register( $base_dir ) {
		spl_autoload_register(
			static function ( $class_name ) use ( $base_dir ) {
				$prefix = 'Pressdock\\';
				if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
