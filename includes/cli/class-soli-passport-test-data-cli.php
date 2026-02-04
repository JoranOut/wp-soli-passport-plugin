<?php

namespace Soli\Passport\CLI;

use Soli\Passport\Database\Clients_Table;
use Soli\Passport\Database\User_Roles_Table;
use Soli\Passport\Database\Role_Mappings_Table;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for managing test/mock data in the Soli Passport plugin.
 *
 * This class is intended for development and testing purposes only.
 * It provides commands to insert and clear OIDC test data.
 */
class Test_Data_CLI {

	const OPTION_TEST_DATA_INSERTED = 'soli_passport_test_data_inserted';

	/**
	 * Register WP-CLI commands
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'soli-passport test-data', self::class );
	}

	/**
	 * Insert test data for development/testing.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Insert test data even if it was already inserted.
	 *
	 * ## EXAMPLES
	 *
	 *     # Insert test data (only if not already inserted)
	 *     wp soli-passport test-data insert
	 *
	 *     # Force re-insert test data
	 *     wp soli-passport test-data insert --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function insert( array $args, array $assoc_args ): void {
		$force = isset( $assoc_args['force'] ) && $assoc_args['force'];

		// Check if already inserted (option-based check)
		if ( ! $force && get_option( self::OPTION_TEST_DATA_INSERTED ) ) {
			WP_CLI::log( 'Test data already inserted. Use --force to re-insert.' );
			return;
		}

		// Additional safety check: verify data doesn't already exist in tables
		if ( ! $force && Clients_Table::count() > 0 ) {
			WP_CLI::log( 'Test data already exists in database. Use --force to re-insert.' );
			update_option( self::OPTION_TEST_DATA_INSERTED, time() ); // Fix missing option
			return;
		}

		WP_CLI::log( 'Inserting Passport test data...' );

		// Insert OIDC clients
		$client_count = $this->insert_oidc_clients();
		WP_CLI::log( sprintf( '  - Inserted %d OIDC clients', $client_count ) );

		// Insert WP role mappings
		$wp_mapping_count = $this->insert_wp_role_mappings();
		WP_CLI::log( sprintf( '  - Inserted %d WP role mappings', $wp_mapping_count ) );

		// Mark as inserted
		update_option( self::OPTION_TEST_DATA_INSERTED, time() );

		WP_CLI::success( 'Passport test data inserted successfully.' );
	}

	/**
	 * Clear all test data.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear test data with confirmation
	 *     wp soli-passport test-data clear
	 *
	 *     # Clear test data without confirmation
	 *     wp soli-passport test-data clear --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'This will delete ALL Passport test data. Are you sure?', $assoc_args );

		global $wpdb;

		// Order matters: clear tables with foreign key references first
		$tables = array(
			'role_mappings',
			'user_roles',
			'clients',
		);

		foreach ( $tables as $table ) {
			$table_name = $wpdb->prefix . 'soli_passport_' . $table;
			$wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore
			WP_CLI::log( sprintf( '  - Cleared %s', $table ) );
		}

		// Remove the inserted flag
		delete_option( self::OPTION_TEST_DATA_INSERTED );

		WP_CLI::success( 'Passport test data cleared.' );
	}

	/**
	 * Check if test data has been inserted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp soli-passport test-data status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$inserted = get_option( self::OPTION_TEST_DATA_INSERTED );

		if ( $inserted ) {
			$date = wp_date( 'Y-m-d H:i:s', $inserted );
			WP_CLI::log( sprintf( 'Test data was inserted on: %s', $date ) );
		} else {
			WP_CLI::log( 'Test data has not been inserted.' );
		}

		// Show counts
		WP_CLI::log( sprintf( 'Current client count: %d', Clients_Table::count() ) );
	}

	/**
	 * Insert OIDC test clients.
	 *
	 * @return int Number of clients inserted
	 */
	private function insert_oidc_clients(): int {
		$count = 0;

		// Test clients for development
		$clients = array(
			array(
				'client_id'    => 'soli-dev-client',
				'name'         => 'Local Development Client',
				'secret'       => 'dev-secret-12345',
				'redirect_uri' => 'http://localhost:8888/wp-admin/admin-ajax.php?action=openid-connect-authorize',
			),
			array(
				'client_id'    => 'soli-bestuur-app',
				'name'         => 'Soli Bestuur App',
				'secret'       => 'test-secret-bestuur',
				'redirect_uri' => 'https://bestuur.soli.nl/oauth/callback',
			),
		);

		foreach ( $clients as $client ) {
			// Check if exists
			$existing = Clients_Table::get_by_client_id( $client['client_id'] );
			if ( $existing ) {
				continue;
			}

			$result = Clients_Table::insert( $client );

			if ( $result ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Insert WordPress role mappings for all clients.
	 *
	 * @return int Number of mappings inserted
	 */
	private function insert_wp_role_mappings(): int {
		$count = 0;

		// Get all clients
		$clients = Clients_Table::get_all( true );

		// Default WP role to passport role mappings (using real WordPress roles)
		$wp_role_mappings = array(
			'administrator' => 'administrator',
			'editor'        => 'editor',
			'author'        => 'author',
			'contributor'   => 'contributor',
			'subscriber'    => 'subscriber',
		);

		// Priority: higher = more important
		$priorities = array(
			'administrator' => 5,
			'editor'        => 4,
			'author'        => 3,
			'contributor'   => 2,
			'subscriber'    => 1,
		);

		foreach ( $clients as $client ) {
			foreach ( $wp_role_mappings as $wp_role => $passport_role ) {
				$result = Role_Mappings_Table::set_wp_role_mapping(
					$client['client_id'],
					$wp_role,
					$passport_role,
					$priorities[ $wp_role ] ?? 0
				);

				if ( $result ) {
					$count++;
				}
			}
		}

		return $count;
	}
}
