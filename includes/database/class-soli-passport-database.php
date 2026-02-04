<?php

namespace Soli\Passport\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base database class for managing all Passport database tables
 */
class Database {

	/**
	 * WordPress database prefix for Passport tables
	 */
	const TABLE_PREFIX = 'soli_passport_';

	/**
	 * Array of table handler instances
	 *
	 * @var array
	 */
	private static array $tables = array();

	/**
	 * Get all table handler class names
	 *
	 * @return array
	 */
	public static function get_table_classes(): array {
		return array(
			'Soli\Passport\Database\Clients_Table',
			'Soli\Passport\Database\User_Roles_Table',
			'Soli\Passport\Database\Role_Mappings_Table',
		);
	}

	/**
	 * Get full table name with WordPress prefix
	 *
	 * @param string $table_name Table name without prefix
	 * @return string Full table name
	 */
	public static function get_table_name( string $table_name ): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PREFIX . $table_name;
	}

	/**
	 * Create all tables on plugin activation
	 */
	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Load all table classes
		self::load_table_classes();

		// Run migrations before creating tables
		self::run_migrations();

		// Create tables in order
		foreach ( self::get_table_classes() as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'create_table' ) ) {
				$class::create_table();
			}
		}
	}

	/**
	 * Run database migrations
	 *
	 * Handles data migration from wp-soli-admin-plugin OIDC tables if they exist.
	 */
	public static function run_migrations(): void {
		global $wpdb;

		// Check if we need to migrate from admin plugin tables
		self::migrate_from_admin_plugin();
	}

	/**
	 * Migrate data from wp-soli-admin-plugin OIDC tables
	 *
	 * This handles the transition when splitting the plugins.
	 */
	private static function migrate_from_admin_plugin(): void {
		global $wpdb;

		// Old table names from admin plugin
		$old_clients_table  = $wpdb->prefix . 'soli_oidc_clients';
		$old_user_roles     = $wpdb->prefix . 'soli_oidc_user_roles';
		$old_role_mappings  = $wpdb->prefix . 'soli_oidc_client_role_mappings';

		// New table names
		$new_clients_table  = self::get_table_name( 'clients' );
		$new_user_roles     = self::get_table_name( 'user_roles' );
		$new_role_mappings  = self::get_table_name( 'role_mappings' );

		// Check if old tables exist and new tables don't have data
		$old_clients_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_clients_table ) ) === $old_clients_table;
		$new_clients_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_clients_table ) ) === $new_clients_table;

		if ( ! $old_clients_exists ) {
			return; // No old data to migrate
		}

		// Check if we've already migrated
		$migration_done = get_option( 'soli_passport_migration_from_admin_done', false );
		if ( $migration_done ) {
			return;
		}

		// Migrate clients
		if ( $old_clients_exists ) {
			$new_clients_count = $new_clients_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_clients_table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $new_clients_count === 0 ) {
				// Create new table if it doesn't exist
				Clients_Table::create_table();

				// Copy data
				$wpdb->query(
					"INSERT INTO {$new_clients_table} (client_id, name, secret, redirect_uri, actief, aangemaakt_op, bijgewerkt_op)
					SELECT client_id, name, secret, redirect_uri, actief, aangemaakt_op, bijgewerkt_op
					FROM {$old_clients_table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		// Migrate user roles
		$old_user_roles_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_user_roles ) ) === $old_user_roles;
		$new_user_roles_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_user_roles ) ) === $new_user_roles;

		if ( $old_user_roles_exists ) {
			$new_user_roles_count = $new_user_roles_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_user_roles}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $new_user_roles_count === 0 ) {
				User_Roles_Table::create_table();

				$wpdb->query(
					"INSERT INTO {$new_user_roles} (wp_user_id, relatie_id, client_id, role, aangemaakt_op, bijgewerkt_op)
					SELECT wp_user_id, relatie_id, client_id, role, aangemaakt_op, bijgewerkt_op
					FROM {$old_user_roles}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		// Migrate role mappings (convert to new dual-mode schema)
		$old_mappings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_role_mappings ) ) === $old_role_mappings;
		$new_mappings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_role_mappings ) ) === $new_role_mappings;

		if ( $old_mappings_exists ) {
			$new_mappings_count = $new_mappings_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_role_mappings}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $new_mappings_count === 0 ) {
				Role_Mappings_Table::create_table();

				// Get the volgorde (priority) from relatie_types table
				$relatie_types_table = $wpdb->prefix . 'soli_relatie_types';
				$types_exist         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relatie_types_table ) ) === $relatie_types_table;

				if ( $types_exist ) {
					// Migrate with priority from relatie_types
					$wpdb->query(
						"INSERT INTO {$new_role_mappings} (client_id, mapping_type, relatie_type_id, role, priority, aangemaakt_op, bijgewerkt_op)
						SELECT m.client_id, 'relatie_type', m.relatie_type_id, m.role, COALESCE(rt.volgorde, 0), m.aangemaakt_op, m.bijgewerkt_op
						FROM {$old_role_mappings} m
						LEFT JOIN {$relatie_types_table} rt ON m.relatie_type_id = rt.id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					);
				} else {
					// Migrate without priority
					$wpdb->query(
						"INSERT INTO {$new_role_mappings} (client_id, mapping_type, relatie_type_id, role, priority, aangemaakt_op, bijgewerkt_op)
						SELECT client_id, 'relatie_type', relatie_type_id, role, 0, aangemaakt_op, bijgewerkt_op
						FROM {$old_role_mappings}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					);
				}
			}
		}

		// Mark migration as done
		update_option( 'soli_passport_migration_from_admin_done', true );
	}

	/**
	 * Drop all tables on plugin uninstall
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// Load all table classes
		self::load_table_classes();

		// Drop tables in reverse order
		$classes = array_reverse( self::get_table_classes() );

		foreach ( $classes as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'drop_table' ) ) {
				$class::drop_table();
			}
		}
	}

	/**
	 * Load all table class files
	 */
	public static function load_table_classes(): void {
		$database_dir = SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/database/';

		$files = array(
			'class-soli-passport-clients-table.php',
			'class-soli-passport-user-roles-table.php',
			'class-soli-passport-role-mappings-table.php',
		);

		foreach ( $files as $file ) {
			$path = $database_dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Get a table handler instance
	 *
	 * @param string $class_name Full class name
	 * @return object|null
	 */
	public static function get_table( string $class_name ): ?object {
		if ( ! isset( self::$tables[ $class_name ] ) ) {
			self::load_table_classes();
			if ( class_exists( $class_name ) ) {
				self::$tables[ $class_name ] = new $class_name();
			}
		}
		return self::$tables[ $class_name ] ?? null;
	}
}
