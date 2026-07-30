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
	 * Users who never signed in through the provider have no assignments.
	 */
	public function test_get_assignments_defaults_to_an_empty_list() {
		$user_id = $this->factory->user->create();

		$this->assertSame( array(), Role_Sync::get_assignments( $user_id ) );
	}
}
