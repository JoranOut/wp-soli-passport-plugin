<?php

namespace Soli\Passport\Database;

use Soli\Passport\Admin_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User Roles table handler
 *
 * Stores per-user/relatie role overrides for specific OIDC clients.
 * Supports both WordPress user IDs and relatie IDs for maximum flexibility.
 */
class User_Roles_Table {

	const TABLE_NAME = 'user_roles';

	/**
	 * Get full table name with prefix
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return Database::get_table_name( self::TABLE_NAME );
	}

	/**
	 * Create the table
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			relatie_id bigint(20) unsigned DEFAULT NULL,
			client_id varchar(100) NOT NULL,
			role varchar(100) NOT NULL,
			aangemaakt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			bijgewerkt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_wpuser_client (wp_user_id, client_id),
			UNIQUE KEY idx_relatie_client (relatie_id, client_id),
			KEY idx_client_id (client_id),
			KEY idx_wp_user_id (wp_user_id),
			KEY idx_relatie_id (relatie_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the table
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get role for a specific WP user and client
	 *
	 * @param int    $wp_user_id WordPress user ID
	 * @param string $client_id  OAuth client identifier
	 * @return string|null Role or null if no override exists
	 */
	public static function get_role( int $wp_user_id, string $client_id ): ?string {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$table_name} WHERE wp_user_id = %d AND client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wp_user_id,
				$client_id
			)
		);

		return $result ?: null;
	}

	/**
	 * Get role for a specific relatie and client
	 *
	 * @param int    $relatie_id Relatie ID
	 * @param string $client_id  OAuth client identifier
	 * @return string|null Role or null if no override exists
	 */
	public static function get_role_by_relatie( int $relatie_id, string $client_id ): ?string {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$table_name} WHERE relatie_id = %d AND client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$relatie_id,
				$client_id
			)
		);

		return $result ?: null;
	}

	/**
	 * Get all role assignments with user/relatie and client info
	 *
	 * @param string|null $filter_client_id Optional client ID to filter by
	 * @return array
	 */
	public static function get_all( ?string $filter_client_id = null ): array {
		global $wpdb;

		$table_name         = self::get_table_name();
		$users_table_name   = $wpdb->users;
		$clients_table_name = Clients_Table::get_table_name();

		$where = '';
		$args  = array();

		if ( $filter_client_id ) {
			$where  = 'WHERE r.client_id = %s';
			$args[] = $filter_client_id;
		}

		// Build query - join with users table, relaties data comes via Admin_Bridge
		$sql = "SELECT r.*, u.user_login, u.user_email, u.display_name, c.name as client_name
			FROM {$table_name} r
			LEFT JOIN {$users_table_name} u ON r.wp_user_id = u.ID
			LEFT JOIN {$clients_table_name} c ON r.client_id = c.client_id
			{$where}
			ORDER BY c.name ASC, COALESCE(u.display_name, '') ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Add relatie data if admin plugin is active
		foreach ( $results as &$row ) {
			if ( ! empty( $row['relatie_id'] ) ) {
				$relatie = Admin_Bridge::get_relatie_by_id( (int) $row['relatie_id'] );
				if ( $relatie ) {
					$row['voornaam']       = $relatie['voornaam'] ?? '';
					$row['tussenvoegsel']  = $relatie['tussenvoegsel'] ?? '';
					$row['achternaam']     = $relatie['achternaam'] ?? '';
				}
			}
		}

		return $results;
	}

	/**
	 * Set role for a WP user/client combination (insert or update)
	 *
	 * @param int    $wp_user_id WordPress user ID
	 * @param string $client_id  OAuth client identifier
	 * @param string $role       Role to assign
	 * @return bool
	 */
	public static function set_role( int $wp_user_id, string $client_id, string $role ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		// Check if record exists
		$existing = self::get_role( $wp_user_id, $client_id );

		if ( $existing !== null ) {
			// Update existing record
			$result = $wpdb->update(
				$table_name,
				array( 'role' => $role ),
				array(
					'wp_user_id' => $wp_user_id,
					'client_id'  => $client_id,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		} else {
			// Insert new record
			$result = $wpdb->insert(
				$table_name,
				array(
					'wp_user_id' => $wp_user_id,
					'client_id'  => $client_id,
					'role'       => $role,
				),
				array( '%d', '%s', '%s' )
			);
		}

		return $result !== false;
	}

	/**
	 * Set role for a relatie/client combination (insert or update)
	 *
	 * @param int    $relatie_id Relatie ID
	 * @param string $client_id  OAuth client identifier
	 * @param string $role       Role to assign
	 * @return bool
	 */
	public static function set_role_by_relatie( int $relatie_id, string $client_id, string $role ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		// Check if record exists
		$existing = self::get_role_by_relatie( $relatie_id, $client_id );

		if ( $existing !== null ) {
			// Update existing record
			$result = $wpdb->update(
				$table_name,
				array( 'role' => $role ),
				array(
					'relatie_id' => $relatie_id,
					'client_id'  => $client_id,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		} else {
			// Insert new record
			$result = $wpdb->insert(
				$table_name,
				array(
					'relatie_id' => $relatie_id,
					'client_id'  => $client_id,
					'role'       => $role,
				),
				array( '%d', '%s', '%s' )
			);
		}

		return $result !== false;
	}

	/**
	 * Delete by ID
	 *
	 * @param int $id Record ID
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete all role overrides for a client
	 *
	 * @param string $client_id OAuth client identifier
	 * @return int Number of rows deleted
	 */
	public static function delete_by_client( string $client_id ): int {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->delete(
			$table_name,
			array( 'client_id' => $client_id ),
			array( '%s' )
		);

		return $result ?: 0;
	}

	/**
	 * Count total role overrides
	 *
	 * @param string|null $client_id Optional client ID to filter by
	 * @return int
	 */
	public static function count( ?string $client_id = null ): int {
		global $wpdb;

		$table_name = self::get_table_name();

		if ( $client_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$client_id
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
