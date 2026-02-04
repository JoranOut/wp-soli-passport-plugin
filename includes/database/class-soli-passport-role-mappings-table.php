<?php

namespace Soli\Passport\Database;

use Soli\Passport\Admin_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role Mappings table handler
 *
 * Dual-mode schema supporting both WordPress roles and relation types.
 * - In standalone mode: maps WP roles to OIDC roles
 * - In enhanced mode: maps relation types to OIDC roles (with WP role fallback)
 */
class Role_Mappings_Table {

	const TABLE_NAME = 'role_mappings';

	/**
	 * Mapping types
	 */
	const TYPE_WP_ROLE      = 'wp_role';
	const TYPE_RELATIE_TYPE = 'relatie_type';

	/**
	 * Default WP role priorities
	 */
	const DEFAULT_WP_PRIORITIES = array(
		'administrator' => 5,
		'editor'        => 4,
		'author'        => 3,
		'contributor'   => 2,
		'subscriber'    => 1,
	);

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
			client_id varchar(100) NOT NULL,
			mapping_type varchar(20) NOT NULL DEFAULT 'wp_role',
			wp_role varchar(100) DEFAULT NULL,
			relatie_type_id bigint(20) unsigned DEFAULT NULL,
			role varchar(100) NOT NULL,
			priority int(11) NOT NULL DEFAULT 0,
			aangemaakt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			bijgewerkt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_client_wp_role (client_id, wp_role),
			UNIQUE KEY idx_client_relatie_type (client_id, relatie_type_id),
			KEY idx_client_id (client_id),
			KEY idx_mapping_type (mapping_type),
			KEY idx_priority (priority)
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
	 * Get all role mappings for a client
	 *
	 * @param string      $client_id    OAuth client identifier
	 * @param string|null $mapping_type Filter by mapping type (null for all)
	 * @return array
	 */
	public static function get_by_client( string $client_id, ?string $mapping_type = null ): array {
		global $wpdb;

		$table_name = self::get_table_name();

		$where = 'WHERE client_id = %s';
		$args  = array( $client_id );

		if ( $mapping_type ) {
			$where .= ' AND mapping_type = %s';
			$args[] = $mapping_type;
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name} {$where} ORDER BY priority DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$args
		);

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Add relatie type data if admin plugin is active
		foreach ( $results as &$row ) {
			if ( $row['mapping_type'] === self::TYPE_RELATIE_TYPE && ! empty( $row['relatie_type_id'] ) ) {
				$types = Admin_Bridge::get_all_relatie_types();
				foreach ( $types as $type ) {
					if ( (int) $type['id'] === (int) $row['relatie_type_id'] ) {
						$row['type_naam']        = $type['naam'] ?? '';
						$row['type_beschrijving'] = $type['beschrijving'] ?? '';
						$row['volgorde']         = $type['volgorde'] ?? 0;
						break;
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Get all role mappings with client info
	 *
	 * @param string|null $filter_client_id Optional client ID to filter by
	 * @param string|null $mapping_type     Optional mapping type filter
	 * @return array
	 */
	public static function get_all( ?string $filter_client_id = null, ?string $mapping_type = null ): array {
		global $wpdb;

		$table_name         = self::get_table_name();
		$clients_table_name = Clients_Table::get_table_name();

		$where = array();
		$args  = array();

		if ( $filter_client_id ) {
			$where[] = 'm.client_id = %s';
			$args[]  = $filter_client_id;
		}

		if ( $mapping_type ) {
			$where[] = 'm.mapping_type = %s';
			$args[]  = $mapping_type;
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT m.*, c.name as client_name
			FROM {$table_name} m
			LEFT JOIN {$clients_table_name} c ON m.client_id = c.client_id
			{$where_clause}
			ORDER BY c.name ASC, m.priority DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Add relatie type data if admin plugin is active
		$types = Admin_Bridge::get_all_relatie_types();
		foreach ( $results as &$row ) {
			if ( $row['mapping_type'] === self::TYPE_RELATIE_TYPE && ! empty( $row['relatie_type_id'] ) ) {
				foreach ( $types as $type ) {
					if ( (int) $type['id'] === (int) $row['relatie_type_id'] ) {
						$row['type_naam']        = $type['naam'] ?? '';
						$row['type_beschrijving'] = $type['beschrijving'] ?? '';
						$row['volgorde']         = $type['volgorde'] ?? 0;
						break;
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Get role for a WP user role
	 *
	 * @param string $client_id OAuth client identifier
	 * @param string $wp_role   WordPress role
	 * @return string|null Role or null if no mapping
	 */
	public static function get_role_for_wp_role( string $client_id, string $wp_role ): ?string {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$table_name} WHERE client_id = %s AND mapping_type = %s AND wp_role = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client_id,
				self::TYPE_WP_ROLE,
				$wp_role
			)
		);

		return $result ?: null;
	}

	/**
	 * Get role for a user based on their WP roles (uses highest priority)
	 *
	 * @param string $client_id OAuth client identifier
	 * @param array  $wp_roles  Array of WordPress role slugs
	 * @return string|null Role or null if no mapping
	 */
	public static function get_role_for_wp_roles( string $client_id, array $wp_roles ): ?string {
		if ( empty( $wp_roles ) ) {
			return null;
		}

		global $wpdb;

		$table_name   = self::get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $wp_roles ), '%s' ) );
		$args         = array_merge( array( $client_id, self::TYPE_WP_ROLE ), $wp_roles );

		$sql = $wpdb->prepare(
			"SELECT role FROM {$table_name}
			WHERE client_id = %s AND mapping_type = %s AND wp_role IN ({$placeholders})
			ORDER BY priority DESC
			LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$args
		);

		return $wpdb->get_var( $sql ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get role for relation type IDs (uses highest priority)
	 *
	 * @param string $client_id       OAuth client identifier
	 * @param array  $relatie_type_ids Array of relation type IDs
	 * @return string|null Role or null if no mapping
	 */
	public static function get_role_for_relatie_types( string $client_id, array $relatie_type_ids ): ?string {
		if ( empty( $relatie_type_ids ) ) {
			return null;
		}

		global $wpdb;

		$table_name   = self::get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $relatie_type_ids ), '%d' ) );
		$args         = array_merge( array( $client_id, self::TYPE_RELATIE_TYPE ), $relatie_type_ids );

		$sql = $wpdb->prepare(
			"SELECT role FROM {$table_name}
			WHERE client_id = %s AND mapping_type = %s AND relatie_type_id IN ({$placeholders})
			ORDER BY priority DESC
			LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$args
		);

		return $wpdb->get_var( $sql ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Set WP role mapping (insert or update)
	 *
	 * @param string $client_id OAuth client identifier
	 * @param string $wp_role   WordPress role
	 * @param string $role      OIDC role to assign
	 * @param int    $priority  Priority (optional, uses default if not provided)
	 * @return bool
	 */
	public static function set_wp_role_mapping( string $client_id, string $wp_role, string $role, ?int $priority = null ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		// Use default priority if not provided
		if ( $priority === null ) {
			$priority = self::DEFAULT_WP_PRIORITIES[ $wp_role ] ?? 0;
		}

		// Check if mapping exists
		$existing = self::get_role_for_wp_role( $client_id, $wp_role );

		if ( $existing !== null ) {
			// Update existing
			$result = $wpdb->update(
				$table_name,
				array(
					'role'     => $role,
					'priority' => $priority,
				),
				array(
					'client_id'    => $client_id,
					'mapping_type' => self::TYPE_WP_ROLE,
					'wp_role'      => $wp_role,
				),
				array( '%s', '%d' ),
				array( '%s', '%s', '%s' )
			);
		} else {
			// Insert new
			$result = $wpdb->insert(
				$table_name,
				array(
					'client_id'    => $client_id,
					'mapping_type' => self::TYPE_WP_ROLE,
					'wp_role'      => $wp_role,
					'role'         => $role,
					'priority'     => $priority,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}

		return $result !== false;
	}

	/**
	 * Set relatie type mapping (insert or update)
	 *
	 * @param string $client_id       OAuth client identifier
	 * @param int    $relatie_type_id Relation type ID
	 * @param string $role            OIDC role to assign
	 * @param int    $priority        Priority
	 * @return bool
	 */
	public static function set_relatie_type_mapping( string $client_id, int $relatie_type_id, string $role, int $priority = 0 ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		// Check if mapping exists
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE client_id = %s AND mapping_type = %s AND relatie_type_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client_id,
				self::TYPE_RELATIE_TYPE,
				$relatie_type_id
			)
		);

		if ( $existing_id ) {
			// Update existing
			$result = $wpdb->update(
				$table_name,
				array(
					'role'     => $role,
					'priority' => $priority,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		} else {
			// Insert new
			$result = $wpdb->insert(
				$table_name,
				array(
					'client_id'       => $client_id,
					'mapping_type'    => self::TYPE_RELATIE_TYPE,
					'relatie_type_id' => $relatie_type_id,
					'role'            => $role,
					'priority'        => $priority,
				),
				array( '%s', '%s', '%d', '%s', '%d' )
			);
		}

		return $result !== false;
	}

	/**
	 * Delete a mapping by ID
	 *
	 * @param int $id Mapping ID
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
	 * Delete all mappings for a client
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
	 * Get a mapping by ID
	 *
	 * @param int $id Mapping ID
	 * @return array|null
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return $result ?: null;
	}

	/**
	 * Count total mappings
	 *
	 * @param string|null $client_id    Optional client ID to filter by
	 * @param string|null $mapping_type Optional mapping type to filter by
	 * @return int
	 */
	public static function count( ?string $client_id = null, ?string $mapping_type = null ): int {
		global $wpdb;

		$table_name = self::get_table_name();

		$where = array();
		$args  = array();

		if ( $client_id ) {
			$where[] = 'client_id = %s';
			$args[]  = $client_id;
		}

		if ( $mapping_type ) {
			$where[] = 'mapping_type = %s';
			$args[]  = $mapping_type;
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT COUNT(*) FROM {$table_name} {$where_clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get default WP role priorities
	 *
	 * @return array
	 */
	public static function get_default_wp_priorities(): array {
		return self::DEFAULT_WP_PRIORITIES;
	}
}
