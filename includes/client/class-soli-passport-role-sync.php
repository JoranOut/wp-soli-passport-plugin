<?php

namespace Soli\Passport\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role Sync for OIDC Client mode
 *
 * Adapter between the Soli identity provider (admin.soli.nl, Laravel Passport)
 * and WordPress. All authorization decisions are made by the provider; this
 * class only applies them locally.
 *
 * Claim contract (see App\OpenId\SoliIdentityEntity in laravel-soli-administration):
 *
 *   roles       string[]  Requires the 'roles' scope. Contains at most one entry:
 *                         the role the provider resolved for this client.
 *                         An empty array means "no access to this client".
 *   assignments array[]   Requires the 'assignments' scope. Each entry has
 *                         onderdeel_id, instrument_soort_id, instrument_soort
 *                         and instrument_familie.
 *
 * Users are matched on the 'sub' claim by the OIDC client plugin, so the client
 * must be configured with "Link existing users" disabled - otherwise two
 * provider accounts sharing an email address collapse into one WP user.
 */
class Role_Sync {

	/**
	 * Query parameter to bypass SSO redirect
	 */
	const BYPASS_SSO_PARAM = 'bypass-sso';

	/**
	 * User meta key holding the assignments claim
	 */
	const ASSIGNMENTS_META_KEY = 'soli_passport_assignments';

	/**
	 * Initialize hooks
	 */
	public function init(): void {
		// Refuse the login when the provider grants no role for this client.
		add_filter( 'openid-connect-generic-user-login-test', array( $this, 'allow_login' ), 10, 2 );
		add_filter( 'openid-connect-generic-user-creation-test', array( $this, 'allow_user_creation' ), 10, 2 );

		// Apply role and assignments on every login.
		add_action( 'openid-connect-generic-update-user-using-current-claim', array( $this, 'sync_from_claim' ), 10, 2 );

		// Allow bypassing SSO with ?bypass-sso parameter
		add_filter( 'openid-connect-generic-settings', array( $this, 'maybe_disable_sso' ) );

		// Handle OIDC login errors: show retry button, hide login form
		add_filter( 'login_message', array( $this, 'add_retry_button_on_error' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'hide_login_form_on_error' ) );
	}

	/**
	 * Resolve the WordPress role from the roles claim
	 *
	 * Returns null when the provider granted no access, or when the granted role
	 * does not exist in WordPress. The latter means the client is not configured
	 * on the provider - it then falls back to sending its own application roles
	 * ('admin', 'bestuur', ...), which are deliberately not mapped here.
	 *
	 * @param array $user_claim The user claims from the OIDC provider.
	 * @return string|null WordPress role slug, or null when access is denied.
	 */
	public function resolve_role( array $user_claim ): ?string {
		$roles = $user_claim['roles'] ?? null;

		if ( ! is_array( $roles ) || empty( $roles ) ) {
			return null;
		}

		$role = sanitize_key( (string) reset( $roles ) );

		if ( ! in_array( $role, array_keys( wp_roles()->roles ), true ) ) {
			return null;
		}

		return $role;
	}

	/**
	 * Deny the login when the provider granted no usable role
	 *
	 * @param bool  $allow      Whether the login is allowed so far.
	 * @param array $user_claim The user claims from the OIDC provider.
	 * @return bool
	 */
	public function allow_login( $allow, $user_claim ): bool {
		if ( ! $allow ) {
			return false;
		}

		if ( ! is_array( $user_claim ) || null === $this->resolve_role( $user_claim ) ) {
			$this->log_denial( is_array( $user_claim ) ? $user_claim : array() );
			return false;
		}

		return true;
	}

	/**
	 * Do not create a WordPress user for someone who may not sign in
	 *
	 * @param bool  $create     Whether user creation is allowed so far.
	 * @param array $user_claim The user claims from the OIDC provider.
	 * @return bool
	 */
	public function allow_user_creation( $create, $user_claim ): bool {
		if ( ! $create ) {
			return false;
		}

		return is_array( $user_claim ) && null !== $this->resolve_role( $user_claim );
	}

	/**
	 * Log why a login was denied
	 *
	 * Distinguishes an intentional denial from a misconfigured client, because
	 * the two need very different fixes.
	 *
	 * @param array $user_claim The user claims from the OIDC provider.
	 */
	private function log_denial( array $user_claim ): void {
		$roles   = $user_claim['roles'] ?? null;
		$subject = $user_claim['sub'] ?? 'unknown';

		if ( ! is_array( $roles ) ) {
			$reason = 'no roles claim in the response - is the "roles" scope requested?';
		} elseif ( empty( $roles ) ) {
			$reason = 'the provider granted no access to this client';
		} else {
			$reason = sprintf(
				'the provider returned role "%s", which is not a WordPress role - is this client configured on the provider?',
				(string) reset( $roles )
			);
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[soli-passport] denied login for sub %s: %s', $subject, $reason ) );
	}

	/**
	 * Apply the role and assignments from the claims to the WordPress user
	 *
	 * @param \WP_User $user       The WordPress user object.
	 * @param array    $user_claim The user claims from the OIDC provider.
	 */
	public function sync_from_claim( \WP_User $user, array $user_claim ): void {
		$role = $this->resolve_role( $user_claim );

		// allow_login() already rejected this case; guard against other callers.
		if ( null === $role ) {
			return;
		}

		if ( ! in_array( $role, $user->roles, true ) || count( $user->roles ) > 1 ) {
			$user->set_role( $role );
		}

		$this->sync_assignments( $user, $user_claim );
	}

	/**
	 * Store the assignments claim as user meta
	 *
	 * Consumed by other Soli plugins to filter content per orchestra and
	 * instrument. Absent when the client does not request the 'assignments'
	 * scope, in which case existing meta is left untouched.
	 *
	 * @param \WP_User $user       The WordPress user object.
	 * @param array    $user_claim The user claims from the OIDC provider.
	 */
	private function sync_assignments( \WP_User $user, array $user_claim ): void {
		if ( ! isset( $user_claim['assignments'] ) || ! is_array( $user_claim['assignments'] ) ) {
			return;
		}

		$assignments = array();

		foreach ( $user_claim['assignments'] as $assignment ) {
			if ( ! is_array( $assignment ) ) {
				continue;
			}

			$assignments[] = array(
				'onderdeel_id'        => isset( $assignment['onderdeel_id'] ) ? absint( $assignment['onderdeel_id'] ) : 0,
				'instrument_soort_id' => isset( $assignment['instrument_soort_id'] ) ? absint( $assignment['instrument_soort_id'] ) : 0,
				'instrument_soort'    => isset( $assignment['instrument_soort'] ) ? sanitize_text_field( (string) $assignment['instrument_soort'] ) : '',
				'instrument_familie'  => isset( $assignment['instrument_familie'] ) ? sanitize_text_field( (string) $assignment['instrument_familie'] ) : '',
			);
		}

		update_user_meta( $user->ID, self::ASSIGNMENTS_META_KEY, $assignments );
	}

	/**
	 * Get the assignments synced for a user
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array List of assignments, empty when nothing was synced.
	 */
	public static function get_assignments( int $user_id ): array {
		$assignments = get_user_meta( $user_id, self::ASSIGNMENTS_META_KEY, true );

		return is_array( $assignments ) ? $assignments : array();
	}

	/**
	 * Disable SSO when bypass param or login error is present
	 *
	 * On an error page this also breaks the redirect loop: in 'auto' mode the
	 * login page would immediately bounce back to the provider that just failed.
	 *
	 * @param object $settings The OIDC plugin settings object.
	 * @return object Modified settings.
	 */
	public function maybe_disable_sso( $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::BYPASS_SSO_PARAM ] ) || isset( $_GET['login-error'] ) ) {
			$settings->login_type = 'button';
		}

		return $settings;
	}

	/**
	 * Get the provider's logout URL with a redirect back to the client login
	 *
	 * @return string|null The logout URL, or null if the provider cannot be determined.
	 */
	private function get_provider_logout_url(): ?string {
		$settings       = get_option( 'openid_connect_generic_settings', array() );
		$login_endpoint = $settings['endpoint_login'] ?? '';

		if ( empty( $login_endpoint ) ) {
			return null;
		}

		// Extract provider base URL from the login endpoint
		$parsed = wp_parse_url( $login_endpoint );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return null;
		}

		$provider_base = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$provider_base .= ':' . $parsed['port'];
		}

		return add_query_arg(
			array( 'redirect_uri' => wp_login_url() ),
			$provider_base . '/oauth/logout'
		);
	}

	/**
	 * Show an explanation and a retry button on OIDC login errors
	 *
	 * The retry link logs the user out at the provider before returning here,
	 * so they can sign in with a different account.
	 *
	 * @param string $message The current login message.
	 * @return string Modified login message.
	 */
	public function add_retry_button_on_error( string $message ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['login-error'] ) ) {
			return $message;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'unauthorized' === sanitize_key( wp_unslash( $_GET['login-error'] ) ) ) {
			$message .= '<p class="message">' . esc_html__( 'Your Soli account does not have access to this site.', 'soli-passport' ) . '</p>';
		}

		$retry_url = $this->get_provider_logout_url() ?? wp_login_url();

		$message .= '<p style="text-align: center; margin: 16px 0;">';
		$message .= '<a href="' . esc_url( $retry_url ) . '" style="display: inline-block; padding: 8px 24px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px;">';
		$message .= esc_html__( 'Sign in with a different account', 'soli-passport' );
		$message .= '</a>';
		$message .= '</p>';

		return $message;
	}

	/**
	 * Hide the login form and OIDC button on error pages
	 *
	 * Only shows the error message and retry button. ?bypass-sso still gets the
	 * regular form, so an administrator is never locked out by a failed SSO.
	 */
	public function hide_login_form_on_error(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['login-error'] ) || isset( $_GET[ self::BYPASS_SSO_PARAM ] ) ) {
			return;
		}

		echo '<style>
			#loginform, .openid-connect-login-button, #nav, #backtoblog { display: none !important; }
		</style>';
	}
}
