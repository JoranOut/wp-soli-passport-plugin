<?php
/**
 * Tests for keeping synced member data out of the public user endpoints.
 *
 * @package Soli\Passport
 */

use Soli\Passport\User_Privacy;

/**
 * User_Privacy test case.
 */
class UserPrivacyTest extends WP_UnitTestCase {

	/**
	 * Class under test.
	 *
	 * @var User_Privacy
	 */
	private User_Privacy $privacy;

	/**
	 * Route keys WordPress uses for the users endpoints.
	 */
	private const USER_ROUTES = array(
		'/wp/v2/users',
		'/wp/v2/users/(?P<id>[\d]+)',
	);

	/**
	 * Set up the class under test.
	 */
	public function set_up() {
		parent::set_up();

		$this->privacy = new User_Privacy();
	}

	/**
	 * Build an endpoints array shaped like the one WordPress passes to the filter.
	 *
	 * @return array
	 */
	private function endpoints(): array {
		return array(
			'/wp/v2/users'                => array( 'stub' ),
			'/wp/v2/users/(?P<id>[\d]+)'  => array( 'stub' ),
			'/wp/v2/posts'                => array( 'stub' ),
		);
	}

	/**
	 * Anonymous visitors must not be able to enumerate members.
	 */
	public function test_user_routes_are_hidden_from_anonymous_requests() {
		wp_set_current_user( 0 );

		$endpoints = $this->privacy->hide_user_endpoints_from_anonymous( $this->endpoints() );

		foreach ( self::USER_ROUTES as $route ) {
			$this->assertArrayNotHasKey( $route, $endpoints );
		}
	}

	/**
	 * Unrelated routes are left alone.
	 */
	public function test_other_routes_are_untouched() {
		wp_set_current_user( 0 );

		$endpoints = $this->privacy->hide_user_endpoints_from_anonymous( $this->endpoints() );

		$this->assertArrayHasKey( '/wp/v2/posts', $endpoints );
	}

	/**
	 * The block editor and other signed-in consumers keep working.
	 */
	public function test_user_routes_stay_available_to_signed_in_users() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$endpoints = $this->privacy->hide_user_endpoints_from_anonymous( $this->endpoints() );

		foreach ( self::USER_ROUTES as $route ) {
			$this->assertArrayHasKey( $route, $endpoints );
		}
	}

	/**
	 * A site that genuinely needs public author data can opt out.
	 */
	public function test_the_restriction_can_be_filtered_off() {
		wp_set_current_user( 0 );

		add_filter( 'soli_passport_restrict_rest_users', '__return_false' );

		$endpoints = $this->privacy->hide_user_endpoints_from_anonymous( $this->endpoints() );

		remove_filter( 'soli_passport_restrict_rest_users', '__return_false' );

		foreach ( self::USER_ROUTES as $route ) {
			$this->assertArrayHasKey( $route, $endpoints );
		}
	}
}
