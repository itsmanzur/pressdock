<?php
/**
 * Plugin Name:       Pressdock Connector – Offline Data Sync
 * Plugin URI:        https://thereadscope.com/pressdock
 * Description:       Adapter-based REST sync API for the Pressdock desktop app. Pull, create, edit and delete plugin data offline, then sync it safely.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            thereadscope
 * Author URI:        https://thereadscope.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressdock
 * Domain Path:       /languages
 *
 * @package Pressdock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDOCK_VERSION', '1.0.0' );
define( 'PDOCK_DB_VERSION', '1' );
define( 'PDOCK_API_VERSION', '1.0' );
define( 'PDOCK_FILE', __FILE__ );
define( 'PDOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDOCK_URL', plugin_dir_url( __FILE__ ) );

require_once PDOCK_DIR . 'src/Autoloader.php';
Pressdock\Autoloader::register( PDOCK_DIR . 'src/' );

register_activation_hook( __FILE__, array( 'Pressdock\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pressdock\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Pressdock\Plugin', 'boot' ), 5 );
