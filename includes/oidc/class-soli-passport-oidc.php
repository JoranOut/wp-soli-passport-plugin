<?php

namespace Soli\Passport\OIDC;

use Soli\Passport\Admin_Bridge;
use Soli\Passport\Dependency_Checker;
use Soli\Passport\Database\Clients_Table;
use Soli\Passport\Database\User_Roles_Table;
use Soli\Passport\Database\Role_Mappings_Table;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main OIDC handler class
 *
 * Integrates with the OpenID Connect Server plugin to:
 * - Register clients from the database
 * - Add role claims to OIDC responses
 * - Support dual-mode operation (standalone/enhanced)
 */
class OIDC {

	/**
	 * Transient prefix for storing client_id during auth flow
	 */
	const TRANSIENT_PREFIX = 'soli_passport_client_';

	/**
	 * Transient expiration time in seconds (5 minutes)
	 */
	const TRANSIENT_EXPIRATION = 300;

	/**
	 * Initialize OIDC hooks
	 */
	public function init(): void {
		// Allow all logged-in users to use OIDC (default requires 'edit_posts')
		add_filter( 'oidc_minimal_capability', array( $this, 'set_minimal_capability' ) );

		// Register clients from database
		add_filter( 'oidc_registered_clients', array( $this, 'get_registered_clients' ) );

		// Track client_id when authorization starts
		add_action( 'rest_api_init', array( $this, 'register_client_tracking' ) );

		// Add role claim to user claims
		add_filter( 'oidc_user_claims', array( $this, 'add_role_claim' ), 10, 2 );

		// Clean up transient after token is issued
		add_action( 'oidc_after_token_response', array( $this, 'cleanup_client_transient' ) );

		// Ensure REST API Link header is sent on all requests (required for OIDC discovery)
		add_action( 'send_headers', array( $this, 'send_rest_api_link_header' ), 1 );
	}

	/**
	 * Send REST API Link header on all frontend requests
	 *
	 * WordPress normally sends this via rest_output_link_header() on template_redirect,
	 * but this doesn't work reliably for HEAD requests or in some server configurations.
	 * As an OIDC provider, we need the REST API to be discoverable.
	 */
	public function send_rest_api_link_header(): void {
		if ( headers_sent() ) {
			return;
		}

		$api_root = get_rest_url();
		if ( ! empty( $api_root ) ) {
			header( sprintf( 'Link: <%s>; rel="https://api.w.org/"', esc_url( $api_root ) ), false );
		}
	}

	/**
	 * Set minimal capability required for OIDC authentication
	 *
	 * By default, the OpenID Connect Server plugin requires 'edit_posts',
	 * which excludes subscribers. We lower this to 'read' to allow all
	 * logged-in users to authenticate via OIDC.
	 *
	 * @return string The minimal capability required
	 */
	public function set_minimal_capability(): string {
		return 'read';
	}

	/**
	 * Get registered clients from database for OIDC plugin
	 *
	 * @param array $clients Existing clients from filter
	 * @return array
	 */
	public function get_registered_clients( array $clients = array() ): array {
		$db_clients = Clients_Table::get_all( true );

		foreach ( $db_clients as $client ) {
			$clients[ $client['client_id'] ] = array(
				'name'         => $client['name'],
				'secret'       => $client['secret'],
				'redirect_uri' => $client['redirect_uri'],
				'grant_types'  => array( 'authorization_code' ),
				'scope'        => 'openid profile email',
			);
		}

		return $clients;
	}

	/**
	 * Register REST API filter to track client_id during authorization
	 */
	public function register_client_tracking(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'track_client_on_auth' ), 10, 3 );
	}

	/**
	 * Track client_id when authorization endpoint is called
	 *
	 * @param mixed            $result  Response to replace the requested version with
	 * @param \WP_REST_Server  $server  Server instance
	 * @param \WP_REST_Request $request Request used to generate the response
	 * @return mixed
	 */
	public function track_client_on_auth( $result, $server, $request ) {
		$route = $request->get_route();

		// Check if this is the OIDC authorize endpoint
		if ( strpos( $route, '/openid-connect/authorize' ) !== false ) {
			$client_id = $request->get_param( 'client_id' );

			if ( $client_id && is_user_logged_in() ) {
				$user_id       = get_current_user_id();
				$transient_key = self::TRANSIENT_PREFIX . $user_id;

				set_transient( $transient_key, $client_id, self::TRANSIENT_EXPIRATION );
			}
		}

		// Check if this is the OIDC token endpoint
		if ( strpos( $route, '/openid-connect/token' ) !== false ) {
			$client_id = $request->get_param( 'client_id' );

			if ( $client_id ) {
				// Store client_id in a request-scoped way for the token flow
				$GLOBALS['soli_passport_token_client_id'] = $client_id;
			}
		}

		return $result;
	}

	/**
	 * Add custom claims to OIDC user claims
	 *
	 * Adds the following claims:
	 * - User profile: given_name, family_name, nickname, email
	 * - Orchestras with instruments: orchestras (array of groups with instruments)
	 * - Role: user_role (resolved based on mappings)
	 *
	 * Role resolution algorithm:
	 * 1. Check user-specific override (by WP user ID)
	 * 2. IF enhanced mode (admin plugin installed):
	 *    a. Find relatie for WP user
	 *    b. Check relatie-specific override
	 *    c. Get relation types and apply priority-based mapping
	 * 3. Get WP roles and apply priority-based mapping (fallback)
	 * 4. Return "no-access" if no mapping found
	 *
	 * @param array   $claims User claims array
	 * @param WP_User $user   WordPress user object
	 * @return array
	 */
	public function add_role_claim( array $claims, WP_User $user ): array {
		// Add user profile claims
		$claims = $this->add_profile_claims( $claims, $user );

		// Add orchestras and instruments claims
		$claims = $this->add_relatie_claims( $claims, $user );

		// Add user_role claim (requires client_id for role resolution)
		$client_id = $this->get_current_client_id( $user->ID );

		if ( ! $client_id ) {
			$claims['user_role'] = Roles::NO_ACCESS_ROLE;
			return $claims;
		}

		$role = $this->resolve_role( $user, $client_id );

		$claims['user_role'] = $role ?: Roles::NO_ACCESS_ROLE;

		return $claims;
	}

	/**
	 * Add user profile claims
	 *
	 * Adds standard OIDC profile claims:
	 * - given_name: First name (from WP or relatie)
	 * - family_name: Last name (from WP or relatie)
	 * - nickname: Nice name / roepnaam (from relatie or WP display_name)
	 * - email: Email address
	 *
	 * @param array   $claims User claims array
	 * @param WP_User $user   WordPress user object
	 * @return array
	 */
	private function add_profile_claims( array $claims, WP_User $user ): array {
		// Default to WordPress user data
		$given_name  = $user->first_name;
		$family_name = $user->last_name;
		$nickname    = $user->display_name;
		$email       = $user->user_email;

		// If enhanced mode, try to get data from relatie
		if ( Dependency_Checker::is_enhanced_mode() ) {
			$relatie = Admin_Bridge::get_relatie_by_wp_user_id( $user->ID );

			if ( $relatie ) {
				// Use relatie data if available (more complete than WP user data)
				if ( ! empty( $relatie['voornaam'] ) ) {
					$given_name = $relatie['voornaam'];
				}

				// Combine tussenvoegsel with achternaam for family_name
				$family_parts = array_filter( array(
					$relatie['tussenvoegsel'] ?? '',
					$relatie['achternaam'] ?? '',
				) );
				if ( ! empty( $family_parts ) ) {
					$family_name = implode( ' ', $family_parts );
				}

				// Use roepnaam as nickname if available
				if ( ! empty( $relatie['roepnaam'] ) ) {
					$nickname = $relatie['roepnaam'];
				}
			}
		}

		$claims['given_name']  = $given_name;
		$claims['family_name'] = $family_name;
		$claims['nickname']    = $nickname;
		$claims['email']       = $email;

		return $claims;
	}

	/**
	 * Add relatie-based claims (orchestras with instruments)
	 *
	 * Returns an 'orchestras' claim containing an array of groups with their instruments.
	 * Each orchestra entry includes:
	 * - name: The group name
	 * - type: The group type (orkest, ensemble, etc.)
	 * - instruments: Array of instruments the user plays in this group
	 *
	 * @param array   $claims User claims array
	 * @param WP_User $user   WordPress user object
	 * @return array
	 */
	private function add_relatie_claims( array $claims, WP_User $user ): array {
		// If admin plugin not active, return empty array
		if ( ! Dependency_Checker::is_enhanced_mode() ) {
			$claims['orchestras'] = array();
			return $claims;
		}

		// Find relatie for this WP user
		$relatie = Admin_Bridge::get_relatie_by_wp_user_id( $user->ID );

		if ( ! $relatie ) {
			$claims['orchestras'] = array();
			return $claims;
		}

		$relatie_id = (int) $relatie['id'];

		// Get instruments grouped by group - this returns the combined structure
		$instruments_by_group = Admin_Bridge::get_relatie_instruments( $relatie_id );

		// Transform to orchestras format
		$orchestras = array();
		foreach ( $instruments_by_group as $group_data ) {
			$instruments = array();
			foreach ( $group_data['instruments'] ?? array() as $instrument ) {
				$instruments[] = $instrument['type'] ?? '';
			}

			$orchestras[] = array(
				'name'        => $group_data['group'] ?? '',
				'type'        => $group_data['group_type'] ?? '',
				'instruments' => $instruments,
			);
		}

		$claims['orchestras'] = $orchestras;

		return $claims;
	}

	/**
	 * Resolve the role for a user and client
	 *
	 * @param WP_User $user      WordPress user object
	 * @param string  $client_id OAuth client identifier
	 * @return string The resolved role
	 */
	private function resolve_role( WP_User $user, string $client_id ): string {
		// Step 1: Check for WP user-specific override
		$override_role = User_Roles_Table::get_role( $user->ID, $client_id );
		if ( $override_role ) {
			return $override_role;
		}

		// Step 2: Enhanced mode - check relatie-based resolution
		if ( Dependency_Checker::is_enhanced_mode() ) {
			$role = $this->resolve_role_enhanced( $user, $client_id );
			if ( $role ) {
				return $role;
			}
		}

		// Step 3: Standalone/fallback - resolve from WP roles
		$role = $this->resolve_role_from_wp_roles( $user, $client_id );
		if ( $role ) {
			return $role;
		}

		// Step 4: No mapping found - return no-access
		return Roles::NO_ACCESS_ROLE;
	}

	/**
	 * Resolve role using enhanced mode (relatie-based)
	 *
	 * @param WP_User $user      WordPress user object
	 * @param string  $client_id OAuth client identifier
	 * @return string|null Role or null if no mapping
	 */
	private function resolve_role_enhanced( WP_User $user, string $client_id ): ?string {
		// Find relatie for this WP user
		$relatie = Admin_Bridge::get_relatie_by_wp_user_id( $user->ID );

		if ( ! $relatie ) {
			return null;
		}

		$relatie_id = (int) $relatie['id'];

		// Check for relatie-specific override
		$relatie_override = User_Roles_Table::get_role_by_relatie( $relatie_id, $client_id );
		if ( $relatie_override ) {
			return $relatie_override;
		}

		// Get current relation types for this relatie
		$type_ids = Admin_Bridge::get_relatie_type_ids( $relatie_id );

		if ( empty( $type_ids ) ) {
			return null;
		}

		// Get role from relation type mappings (highest priority wins)
		return Role_Mappings_Table::get_role_for_relatie_types( $client_id, $type_ids );
	}

	/**
	 * Resolve role from WordPress roles
	 *
	 * @param WP_User $user      WordPress user object
	 * @param string  $client_id OAuth client identifier
	 * @return string|null Role or null if no mapping
	 */
	private function resolve_role_from_wp_roles( WP_User $user, string $client_id ): ?string {
		$wp_roles = $user->roles;

		if ( empty( $wp_roles ) ) {
			return null;
		}

		return Role_Mappings_Table::get_role_for_wp_roles( $client_id, $wp_roles );
	}

	/**
	 * Get the current client_id from transient or global
	 *
	 * @param int $user_id User ID
	 * @return string|null
	 */
	private function get_current_client_id( int $user_id ): ?string {
		// First try the user-specific transient (from authorize flow)
		$transient_key = self::TRANSIENT_PREFIX . $user_id;
		$client_id     = get_transient( $transient_key );

		if ( $client_id ) {
			return $client_id;
		}

		// Fall back to global (from token flow)
		if ( ! empty( $GLOBALS['soli_passport_token_client_id'] ) ) {
			return $GLOBALS['soli_passport_token_client_id'];
		}

		return null;
	}

	/**
	 * Clean up client transient after token is issued
	 */
	public function cleanup_client_transient(): void {
		if ( is_user_logged_in() ) {
			$user_id       = get_current_user_id();
			$transient_key = self::TRANSIENT_PREFIX . $user_id;
			delete_transient( $transient_key );
		}
	}

	/**
	 * Verify client credentials
	 *
	 * @param string $client_id     Client identifier
	 * @param string $client_secret Plain text secret
	 * @return bool
	 */
	public static function verify_client( string $client_id, string $client_secret ): bool {
		return Clients_Table::verify_secret( $client_id, $client_secret );
	}

	/**
	 * Get role for a user and client combination
	 *
	 * Uses the full role resolution algorithm.
	 *
	 * @param int    $user_id   WordPress user ID
	 * @param string $client_id OAuth client identifier
	 * @return string The resolved role
	 */
	public static function get_user_role( int $user_id, string $client_id ): string {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return Roles::NO_ACCESS_ROLE;
		}

		$oidc = new self();
		return $oidc->resolve_role( $user, $client_id );
	}
}
