<?php
/**
 * Tests for the claim contract between the Soli identity provider and WordPress.
 *
 * Driven by tests/fixtures/oidc-claims.json, which mirrors the provider's real
 * output. If the provider changes what it emits, update the fixture and these
 * tests should be what fails.
 *
 * @package Soli\Passport
 */

use Soli\Passport\Client\Role_Sync;

/**
 * Role_Sync test case.
 */
class RoleSyncTest extends WP_UnitTestCase {

	/**
	 * Class under test.
	 *
	 * @var Role_Sync
	 */
	private Role_Sync $role_sync;

	/**
	 * Claim fixtures.
	 *
	 * @var array<string, array>
	 */
	private static array $fixtures;

	/**
	 * Load the shared fixtures once.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		$fixtures = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/oidc-claims.json' ), true );
		unset( $fixtures['_comment'] );

		self::$fixtures = $fixtures;
	}

	/**
	 * Set up the class under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->role_sync = new Role_Sync();
	}

	/**
	 * Get a fixture claim set.
	 *
	 * @param string $key Fixture key.
	 * @return array
	 */
	private function claims( string $key ): array {
		$this->assertArrayHasKey( $key, self::$fixtures, "Fixture '{$key}' is missing from oidc-claims.json" );

		return self::$fixtures[ $key ];
	}

	/**
	 * The fixture file must keep matching the provider's contract.
	 */
	public function test_fixtures_cover_the_documented_claim_contract() {
		foreach ( array( 'editor', 'subscriber', 'no-access', 'unconfigured-client', 'no-roles-scope' ) as $key ) {
			$claims = $this->claims( $key );

			$this->assertArrayHasKey( 'sub', $claims, "Fixture '{$key}' must carry a sub claim" );
			$this->assertIsString( $claims['sub'], "Fixture '{$key}' sub must be a string" );
		}

		// The provider always sends roles as an array, never a bare string.
		$this->assertIsArray( $this->claims( 'editor' )['roles'] );
		$this->assertSame( array(), $this->claims( 'no-access' )['roles'] );
		$this->assertArrayNotHasKey( 'roles', $this->claims( 'no-roles-scope' ) );
	}

	/**
	 * The shared-email pair must stay two accounts with one address.
	 *
	 * The e2e test built on it checks that they never collapse into a single
	 * WordPress user. Give them the same sub, or different addresses, and it would
	 * still pass while testing nothing.
	 */
	public function test_shared_email_fixtures_are_two_accounts_with_one_address() {
		$first  = $this->claims( 'shared-email-first' );
		$second = $this->claims( 'shared-email-second' );

		$this->assertNotSame( $first['sub'], $second['sub'], 'The pair must be two provider accounts' );
		$this->assertSame( $first['email'], $second['email'], 'The pair must share one email address' );
		$this->assertNotSame(
			$first['preferred_username'],
			$second['preferred_username'],
			'Two people, so two usernames - sub is the only identity key'
		);
	}

	/**
	 * The revocation pair must stay one account whose granted role shrank.
	 *
	 * Same reasoning: a different sub would make the e2e de-escalation test create a
	 * second user and assert nothing about revocation.
	 */
	public function test_role_revocation_fixtures_are_one_account_losing_a_role() {
		$before = $this->claims( 'role-revoked-before' );
		$after  = $this->claims( 'role-revoked-after' );

		$this->assertSame( $before['sub'], $after['sub'], 'Both must be the same provider account' );
		$this->assertSame( array( 'administrator' ), $before['roles'] );
		$this->assertNotContains( 'administrator', $after['roles'], 'The elevated role must be gone' );
		$this->assertNotEmpty( $after['roles'], 'A revoked login would never reach the role sync' );
	}

	/**
	 * A granted role that exists in WordPress resolves to that role.
	 */
	public function test_resolve_role_returns_the_granted_role() {
		$this->assertSame( 'editor', $this->role_sync->resolve_role( $this->claims( 'editor' ) ) );
		$this->assertSame( 'subscriber', $this->role_sync->resolve_role( $this->claims( 'subscriber' ) ) );
	}

	/**
	 * An empty roles array is the provider saying "no access".
	 */
	public function test_resolve_role_denies_when_no_role_is_granted() {
		$this->assertNull( $this->role_sync->resolve_role( $this->claims( 'no-access' ) ) );
	}

	/**
	 * A role that is not a WordPress role means the client is unconfigured upstream.
	 */
	public function test_resolve_role_denies_unmapped_provider_roles() {
		$this->assertNull( $this->role_sync->resolve_role( $this->claims( 'unconfigured-client' ) ) );
	}

	/**
	 * A missing roles claim means the 'roles' scope was not requested.
	 */
	public function test_resolve_role_denies_when_the_roles_claim_is_absent() {
		$this->assertNull( $this->role_sync->resolve_role( $this->claims( 'no-roles-scope' ) ) );
	}

	/**
	 * A roles claim of the wrong shape must not fatal or leak through.
	 */
	public function test_resolve_role_denies_malformed_roles_claims() {
		$this->assertNull( $this->role_sync->resolve_role( array( 'roles' => 'editor' ) ) );
		$this->assertNull( $this->role_sync->resolve_role( array( 'roles' => null ) ) );
		$this->assertNull( $this->role_sync->resolve_role( array() ) );
	}

	/**
	 * Login is allowed only when a role could be resolved.
	 */
	public function test_allow_login_follows_role_resolution() {
		$this->assertTrue( $this->role_sync->allow_login( true, $this->claims( 'editor' ) ) );
		$this->assertFalse( $this->role_sync->allow_login( true, $this->claims( 'no-access' ) ) );
		$this->assertFalse( $this->role_sync->allow_login( true, $this->claims( 'unconfigured-client' ) ) );
		$this->assertFalse( $this->role_sync->allow_login( true, $this->claims( 'no-roles-scope' ) ) );
	}

	/**
	 * An earlier denial is never overridden.
	 */
	public function test_allow_login_never_re_allows_a_denied_login() {
		$this->assertFalse( $this->role_sync->allow_login( false, $this->claims( 'editor' ) ) );
	}

	/**
	 * No WordPress user is created for someone who may not sign in.
	 */
	public function test_allow_user_creation_follows_role_resolution() {
		$this->assertTrue( $this->role_sync->allow_user_creation( true, $this->claims( 'editor' ) ) );
		$this->assertFalse( $this->role_sync->allow_user_creation( true, $this->claims( 'no-access' ) ) );
		$this->assertFalse( $this->role_sync->allow_user_creation( false, $this->claims( 'editor' ) ) );
	}

	/**
	 * The granted role is applied to the user.
	 */
	public function test_sync_applies_the_granted_role() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'editor' ) );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user->ID )->roles ) );
	}

	/**
	 * The provider is authoritative: extra local roles are removed.
	 */
	public function test_sync_collapses_multiple_local_roles() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_role( 'contributor' );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'editor' ) );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user->ID )->roles ) );
	}

	/**
	 * A role the provider took away is taken away locally, capabilities included.
	 *
	 * The provider is the only place roles are decided, so a demotion there has to
	 * land here. Keeping a stale administrator would be the worst version of getting
	 * this wrong.
	 */
	public function test_sync_removes_a_role_the_provider_revoked() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'role-revoked-after' ) );

		$fresh = get_userdata( $user->ID );

		$this->assertSame( array( 'subscriber' ), array_values( $fresh->roles ) );
		$this->assertFalse( $fresh->has_cap( 'manage_options' ), 'The administrator capability must be gone' );
		$this->assertFalse( $fresh->has_cap( 'edit_others_posts' ) );
	}

	/**
	 * An unresolvable role leaves the local role untouched.
	 */
	public function test_sync_leaves_the_role_alone_when_access_is_denied() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'no-access' ) );

		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $user->ID )->roles ) );
	}

	/**
	 * Assignments are stored for other plugins to read.
	 */
	public function test_sync_stores_assignments() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'editor' ) );

		$assignments = Role_Sync::get_assignments( $user->ID );

		$this->assertCount( 2, $assignments );
		$this->assertSame(
			array(
				'onderdeel_id'        => 3,
				'instrument_soort_id' => 12,
				'instrument_soort'    => 'Trompet',
				'instrument_familie'  => 'Koper',
			),
			$assignments[0]
		);
	}

	/**
	 * An empty assignments claim clears previously synced assignments.
	 */
	public function test_sync_clears_assignments_when_the_provider_sends_none() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'editor' ) );
		$this->assertNotEmpty( Role_Sync::get_assignments( $user->ID ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'subscriber' ) );
		$this->assertSame( array(), Role_Sync::get_assignments( $user->ID ) );
	}

	/**
	 * Without the 'assignments' scope the claim is absent and meta is left as is.
	 */
	public function test_sync_keeps_assignments_when_the_claim_is_absent() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim( $user, $this->claims( 'editor' ) );

		$claims = $this->claims( 'editor' );
		unset( $claims['assignments'] );

		$this->role_sync->sync_from_claim( $user, $claims );

		$this->assertCount( 2, Role_Sync::get_assignments( $user->ID ) );
	}

	/**
	 * Assignment values are sanitized and normalised before being stored.
	 */
	public function test_sync_sanitizes_assignments() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->role_sync->sync_from_claim(
			$user,
			array(
				'sub'         => '99',
				'roles'       => array( 'subscriber' ),
				'assignments' => array(
					array(
						'onderdeel_id'        => '7',
						'instrument_soort_id' => '3',
						'instrument_soort'    => '<script>alert(1)</script>Hoorn',
						'instrument_familie'  => 'Koper',
					),
					'not-an-assignment',
					array( 'onderdeel_id' => 9 ),
				),
			)
		);

		$assignments = Role_Sync::get_assignments( $user->ID );

		$this->assertCount( 2, $assignments, 'Non-array entries must be skipped' );
		$this->assertSame( 7, $assignments[0]['onderdeel_id'] );
		$this->assertSame( 3, $assignments[0]['instrument_soort_id'] );
		$this->assertStringNotContainsString( '<script>', $assignments[0]['instrument_soort'] );
		$this->assertSame(
			array(
				'onderdeel_id'        => 9,
				'instrument_soort_id' => 0,
				'instrument_soort'    => '',
				'instrument_familie'  => '',
			),
			$assignments[1],
			'Missing keys must be filled with safe defaults'
		);
	}

	/**
	 * Assignments must never become readable over the REST API.
	 *
	 * They describe which orchestras a named member plays in. Registering the meta key
	 * with show_in_rest would expose that on /wp/v2/users, so this locks the decision in.
	 */
	public function test_assignments_are_not_exposed_over_rest() {
		$this->assertArrayNotHasKey(
			Role_Sync::ASSIGNMENTS_META_KEY,
			get_registered_meta_keys( 'user' ),
			'Assignments meta must not be registered, which would risk exposing it in REST'
		);
	}

	/**
	 * Users who never signed in through the provider have no assignments.
	 */
	public function test_get_assignments_defaults_to_an_empty_list() {
		$user_id = $this->factory->user->create();

		$this->assertSame( array(), Role_Sync::get_assignments( $user_id ) );
	}
}
