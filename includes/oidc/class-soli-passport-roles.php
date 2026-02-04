<?php

namespace Soli\Passport\OIDC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OIDC Roles helper class
 *
 * Provides the list of allowed roles for OIDC role assignments.
 * Combines WordPress built-in roles with a special 'no-access' role.
 */
class Roles {

	/**
	 * Special role indicating no access should be granted
	 */
	const NO_ACCESS_ROLE = 'no-access';

	/**
	 * Get all allowed roles for OIDC role assignments
	 *
	 * Returns an array of role slugs that can be assigned via OIDC.
	 * This includes WordPress built-in roles plus a special 'no-access' role.
	 *
	 * @return array Array of role slugs
	 */
	public static function get_allowed_roles(): array {
		return array(
			'administrator',
			'editor',
			'author',
			'contributor',
			'subscriber',
			self::NO_ACCESS_ROLE,
		);
	}

	/**
	 * Get all allowed roles with display labels
	 *
	 * Returns an associative array of role slugs => display names.
	 *
	 * @return array Array of role slug => display name pairs
	 */
	public static function get_allowed_roles_with_labels(): array {
		$wp_roles = wp_roles();
		$roles    = array();

		foreach ( self::get_allowed_roles() as $role ) {
			if ( self::NO_ACCESS_ROLE === $role ) {
				$roles[ $role ] = __( 'No Access', 'soli-passport' );
			} elseif ( isset( $wp_roles->role_names[ $role ] ) ) {
				$roles[ $role ] = translate_user_role( $wp_roles->role_names[ $role ] );
			} else {
				$roles[ $role ] = ucfirst( $role );
			}
		}

		return $roles;
	}

	/**
	 * Check if a role is valid
	 *
	 * @param string $role Role slug to check
	 * @return bool True if the role is allowed
	 */
	public static function is_valid_role( string $role ): bool {
		return in_array( $role, self::get_allowed_roles(), true );
	}

	/**
	 * Get the display label for a role
	 *
	 * @param string $role Role slug
	 * @return string Display label
	 */
	public static function get_role_label( string $role ): string {
		$roles = self::get_allowed_roles_with_labels();
		return $roles[ $role ] ?? ucfirst( $role );
	}

	/**
	 * Check if a role indicates no access
	 *
	 * @param string $role Role slug
	 * @return bool True if the role is 'no-access'
	 */
	public static function is_no_access( string $role ): bool {
		return self::NO_ACCESS_ROLE === $role;
	}

	/**
	 * Get role options for HTML select element
	 *
	 * Returns an HTML string of option elements for use in a select dropdown.
	 *
	 * @param string $selected The currently selected role
	 * @return string HTML option elements
	 */
	public static function get_role_options_html( string $selected = '' ): string {
		$html  = '';
		$roles = self::get_allowed_roles_with_labels();

		foreach ( $roles as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		return $html;
	}

	/**
	 * Get all WordPress roles
	 *
	 * @return array Array of role slug => display name pairs
	 */
	public static function get_wp_roles(): array {
		$wp_roles = wp_roles();
		$roles    = array();

		foreach ( $wp_roles->role_names as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}

		return $roles;
	}

	/**
	 * Get WP role options for HTML select element
	 *
	 * @param string $selected The currently selected role
	 * @return string HTML option elements
	 */
	public static function get_wp_role_options_html( string $selected = '' ): string {
		$html  = '';
		$roles = self::get_wp_roles();

		foreach ( $roles as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		return $html;
	}
}
