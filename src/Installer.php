<?php
/**
 * Activation, schema and versioned migrations.
 *
 * @package Pressdock
 */

namespace Pressdock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the four sync tables.
 */
final class Installer {

	const CRON_HOOK = 'pdock_daily_prune';

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::migrate();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run migrations when the stored schema version is older than the code.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( 'pdock_db_version' ) !== PDOCK_DB_VERSION ) {
			self::migrate();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Create or upgrade tables with dbDelta. Add new steps below as the schema grows.
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'pdock_';

		dbDelta(
			"CREATE TABLE {$p}changes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity varchar(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			action varchar(10) NOT NULL DEFAULT 'upsert',
			revision int(11) unsigned NOT NULL DEFAULT 1,
			changed_at datetime NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'other',
			PRIMARY KEY  (id),
			KEY entity_id (entity,id),
			KEY object_lookup (entity,object_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}records (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity varchar(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			uuid char(36) NOT NULL,
			revision int(11) unsigned NOT NULL DEFAULT 0,
			deleted tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY entity_object (entity,object_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}ops (
			op_id char(36) NOT NULL,
			batch_id varchar(36) NOT NULL DEFAULT '',
			entity varchar(64) NOT NULL DEFAULT '',
			op varchar(10) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			result longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (op_id),
			KEY batch_id (batch_id),
			KEY created_at (created_at)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id varchar(36) NOT NULL,
			entity varchar(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			op varchar(10) NOT NULL DEFAULT 'update',
			before_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY created_at (created_at)
		) {$charset};"
		);

		update_option( 'pdock_db_version', PDOCK_DB_VERSION, false );
	}

	/**
	 * Table name helper.
	 *
	 * @param string $name changes|records|ops|snapshots.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pdock_' . $name;
	}

	/**
	 * Drop every table and option. Called from uninstall.php only after opt-in.
	 *
	 * @return void
	 */
	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'changes', 'records', 'ops', 'snapshots' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( 'pdock_db_version' );
		delete_option( 'pdock_settings' );
		delete_option( 'pdock_devices' );
	}
}
