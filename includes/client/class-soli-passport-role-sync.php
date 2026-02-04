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
 */
class Role_Sync {

	/**
	 * Initialize the role sync hook
	 */
	public function init(): void {
		add_action( 'openid-connect-generic-update-user-using-current-claim', array( $this, 'sync_role_from_claim' ), 10, 2 );
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
