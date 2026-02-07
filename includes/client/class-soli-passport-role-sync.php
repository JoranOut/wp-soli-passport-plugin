<?php

namespace Soli\Passport\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role Sync for OIDC Client mode
 *
 * When the passport plugin is installed on a site with the daggerhart
 * OIDC client plugin, this class syncs the user_role claim from the
 * provider to the actual WordPress role.
 *
 * Also provides a way to bypass SSO for direct WordPress login.
 */
class Role_Sync {

	/**
	 * Query parameter to bypass SSO redirect
	 */
	const BYPASS_SSO_PARAM = 'bypass-sso';

	/**
	 * Initialize the role sync hook
	 */
	public function init(): void {
		add_action( 'openid-connect-generic-update-user-using-current-claim', array( $this, 'sync_role_from_claim' ), 10, 2 );

		// Allow bypassing SSO with ?direct=1 parameter
		add_filter( 'openid-connect-generic-settings', array( $this, 'maybe_disable_sso' ) );
	}

	/**
	 * Disable SSO when ?direct=1 is present
	 *
	 * This allows admins to login directly with WordPress credentials
	 * instead of being redirected to the OIDC provider.
	 *
	 * Usage: /wp-login.php?bypass-sso
	 *
	 * @param object $settings The OIDC plugin settings object.
	 * @return object Modified settings.
	 */
	public function maybe_disable_sso( $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::BYPASS_SSO_PARAM ] ) ) {
			// Change login_type from 'auto' to 'button' to disable auto-redirect
			$settings->login_type = 'button';
		}

		return $settings;
	}

	/**
	 * Sync WordPress role from OIDC user_role claim
	 *
	 * @param \WP_User $user       The WordPress user object.
	 * @param array    $user_claim The user claims from the OIDC provider.
	 */
	public function sync_role_from_claim( \WP_User $user, array $user_claim ): void {
		if ( empty( $user_claim['user_role'] ) ) {
			return;
		}

		$new_role = sanitize_key( $user_claim['user_role'] );

		// Verify it's a valid WordPress role
		$valid_roles = array_keys( wp_roles()->roles );
		if ( ! in_array( $new_role, $valid_roles, true ) ) {
			return;
		}

		// Only update if different
		if ( ! in_array( $new_role, $user->roles, true ) ) {
			$user->set_role( $new_role );
		}
	}
}
