<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Bridge
 *
 * Provides safe access to wp-soli-admin-plugin data.
 * Returns empty/null values when the admin plugin is not installed.
 */
class Admin_Bridge {

	/**
	 * Check if the admin plugin is active
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return Dependency_Checker::is_admin_plugin_active();
	}

	/**
	 * Get a relatie by WordPress user ID
	 *
	 * Matches via the wp_user_id column in the relaties table.
	 *
	 * @param int $wp_user_id WordPress user ID
	 * @return array|null Relatie data or null
	 */
	public static function get_relatie_by_wp_user_id( int $wp_user_id ): ?array {
		if ( ! self::is_active() ) {
			return null;
		}

		// Try to find relatie by wp_user_id
		if ( class_exists( 'Soli\Admin\Database\Relaties_Table' ) ) {
			$relaties_table = 'Soli\Admin\Database\Relaties_Table';

			// First try by wp_user_id (preferred)
			if ( method_exists( $relaties_table, 'get_by_wp_user_id' ) ) {
				$relatie = $relaties_table::get_by_wp_user_id( $wp_user_id );
				if ( $relatie ) {
					return $relatie;
				}
			}

			// Fallback: try by email
			$user = get_user_by( 'ID', $wp_user_id );
			if ( $user && method_exists( $relaties_table, 'get_by_email' ) ) {
				return $relaties_table::get_by_email( $user->user_email );
			}
		}

		return null;
	}

	/**
	 * Get current relation type IDs for a relatie
	 *
	 * @param int $relatie_id Relatie ID
	 * @return array Array of relation type IDs
	 */
	public static function get_relatie_type_ids( int $relatie_id ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( class_exists( 'Soli\Admin\Database\Relatie_Relatie_Type_Table' ) ) {
			$table = 'Soli\Admin\Database\Relatie_Relatie_Type_Table';
			if ( method_exists( $table, 'get_current_type_ids' ) ) {
				return $table::get_current_type_ids( $relatie_id );
			}
		}

		return array();
	}

	/**
	 * Get all relation types
	 *
	 * @param bool $actief_only Only return active types
	 * @return array Array of relation types
	 */
	public static function get_all_relatie_types( bool $actief_only = true ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( class_exists( 'Soli\Admin\Database\Relatie_Types_Table' ) ) {
			$table = 'Soli\Admin\Database\Relatie_Types_Table';
			if ( method_exists( $table, 'get_all' ) ) {
				return $table::get_all( $actief_only );
			}
		}

		return array();
	}

	/**
	 * Get groups (onderdelen) for a relatie
	 *
	 * @param int $relatie_id Relatie ID
	 * @return array Array of group data
	 */
	public static function get_relatie_groups( int $relatie_id ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( class_exists( 'Soli\Admin\Database\Relatie_Onderdeel_Table' ) ) {
			$table = 'Soli\Admin\Database\Relatie_Onderdeel_Table';
			if ( method_exists( $table, 'get_by_relatie_id' ) ) {
				$onderdelen = $table::get_by_relatie_id( $relatie_id, true );
				$groups     = array();

				foreach ( $onderdelen as $onderdeel ) {
					$groups[] = array(
						'name' => $onderdeel['onderdeel_naam'] ?? '',
						'type' => $onderdeel['onderdeel_type'] ?? '',
					);
				}

				return $groups;
			}
		}

		return array();
	}

	/**
	 * Get instruments for a relatie (grouped by onderdeel)
	 *
	 * @param int $relatie_id Relatie ID
	 * @return array Array of instrument data grouped by group
	 */
	public static function get_relatie_instruments( int $relatie_id ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( class_exists( 'Soli\Admin\Database\Relatie_Instrument_Table' ) ) {
			$table = 'Soli\Admin\Database\Relatie_Instrument_Table';
			if ( method_exists( $table, 'get_by_relatie_grouped' ) ) {
				$grouped     = $table::get_by_relatie_grouped( $relatie_id, true );
				$instruments = array();

				foreach ( $grouped as $onderdeel_data ) {
					$group_instruments = array();
					foreach ( $onderdeel_data['instruments'] as $instrument ) {
						$group_instruments[] = array(
							'type' => $instrument['instrument_type'] ?? '',
						);
					}

					$instruments[] = array(
						'group'       => $onderdeel_data['onderdeel']['naam'] ?? '',
						'group_type'  => $onderdeel_data['onderdeel']['type'] ?? '',
						'instruments' => $group_instruments,
					);
				}

				return $instruments;
			}
		}

		return array();
	}

	/**
	 * Get a relatie by ID
	 *
	 * @param int $relatie_id Relatie ID
	 * @return array|null Relatie data or null
	 */
	public static function get_relatie_by_id( int $relatie_id ): ?array {
		if ( ! self::is_active() ) {
			return null;
		}

		if ( class_exists( 'Soli\Admin\Database\Relaties_Table' ) ) {
			$table = 'Soli\Admin\Database\Relaties_Table';
			if ( method_exists( $table, 'get_by_id' ) ) {
				return $table::get_by_id( $relatie_id );
			}
		}

		return null;
	}

	/**
	 * Get all relaties for dropdown
	 *
	 * @param array $args Query arguments
	 * @return array Array of relaties
	 */
	public static function get_all_relaties( array $args = array() ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( class_exists( 'Soli\Admin\Database\Relaties_Table' ) ) {
			$table = 'Soli\Admin\Database\Relaties_Table';
			if ( method_exists( $table, 'get_all' ) ) {
				$defaults = array(
					'orderby'     => 'achternaam',
					'order'       => 'ASC',
					'actief_only' => true,
				);
				return $table::get_all( array_merge( $defaults, $args ) );
			}
		}

		return array();
	}

	/**
	 * Get display name for a relatie
	 *
	 * @param array $relatie Relatie data
	 * @return string Display name
	 */
	public static function get_relatie_display_name( array $relatie ): string {
		if ( class_exists( 'Soli\Admin\Database\Relaties_Table' ) ) {
			$table = 'Soli\Admin\Database\Relaties_Table';
			if ( method_exists( $table, 'get_display_name' ) ) {
				return $table::get_display_name( $relatie );
			}
		}

		// Fallback: construct name manually
		$parts = array_filter( array(
			$relatie['voornaam'] ?? '',
			$relatie['tussenvoegsel'] ?? '',
			$relatie['achternaam'] ?? '',
		) );

		return implode( ' ', $parts );
	}
}
