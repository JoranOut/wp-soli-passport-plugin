<?php

namespace Soli\Passport\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OIDC Clients table handler
 *
 * Stores OAuth client configuration for the OpenID Connect Server.
 */
class Clients_Table {

	const TABLE_NAME = 'clients';

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
			name varchar(255) NOT NULL,
			secret varchar(255) NOT NULL,
			redirect_uri varchar(500) NOT NULL,
			actief tinyint(1) NOT NULL DEFAULT 1,
			aangemaakt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			bijgewerkt_op datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_client_id (client_id),
			KEY idx_actief (actief)
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
	 * Get all clients
	 *
	 * @param bool $actief_only Only return active clients
	 * @return array
	 */
	public static function get_all( bool $actief_only = false ): array {
		global $wpdb;

		$table_name = self::get_table_name();

		$where = $actief_only ? 'WHERE actief = 1' : '';

		$sql = "SELECT * FROM {$table_name} {$where} ORDER BY name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a client by ID
	 *
	 * @param int $id Client ID
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
	 * Get a client by client_id
	 *
	 * @param string $client_id OAuth client identifier
	 * @return array|null
	 */
	public static function get_by_client_id( string $client_id ): ?array {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client_id
			),
			ARRAY_A
		);

		return $result ?: null;
	}

	/**
	 * Insert a new client
	 *
	 * @param array $data Client data
	 * @return int|false Insert ID or false on failure
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$result = $wpdb->insert(
			$table_name,
			array(
				'client_id'    => $data['client_id'],
				'name'         => $data['name'],
				'secret'       => $data['secret'],
				'redirect_uri' => $data['redirect_uri'],
				'actief'       => $data['actief'] ?? 1,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a client
	 *
	 * @param int   $id   Client ID
	 * @param array $data Data to update
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$table_name = self::get_table_name();

		$update_data   = array();
		$update_format = array();

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
			$update_format[]     = '%s';
		}

		if ( isset( $data['redirect_uri'] ) ) {
			$update_data['redirect_uri'] = $data['redirect_uri'];
			$update_format[]             = '%s';
		}

		if ( isset( $data['actief'] ) ) {
			$update_data['actief'] = $data['actief'];
			$update_format[]       = '%d';
		}

		// Only update secret if provided and not empty
		if ( ! empty( $data['secret'] ) ) {
			$update_data['secret'] = $data['secret'];
			$update_format[]       = '%s';
		}

		if ( empty( $update_data ) ) {
			return true;
		}

		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $id ),
			$update_format,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete a client
	 *
	 * @param int $id Client ID
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
	 * Verify a client secret
	 *
	 * @param string $client_id OAuth client identifier
	 * @param string $secret    Plain text secret to verify
	 * @return bool
	 */
	public static function verify_secret( string $client_id, string $secret ): bool {
		$client = self::get_by_client_id( $client_id );

		if ( ! $client ) {
			return false;
		}

		return hash_equals( $client['secret'], $secret );
	}

	/**
	 * Count total clients
	 *
	 * @param bool $actief_only Only count active clients
	 * @return int
	 */
	public static function count( bool $actief_only = false ): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$where      = $actief_only ? 'WHERE actief = 1' : '';

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Generate a random client secret
	 *
	 * @param int $length Secret length
	 * @return string
	 */
	public static function generate_secret( int $length = 32 ): string {
		return wp_generate_password( $length, true, false );
	}
}
