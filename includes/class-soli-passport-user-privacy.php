<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps synced member data out of WordPress's public user endpoints.
 *
 * WordPress serves /wp/v2/users to anonymous requests, listing every user who has
 * published a post: display name, slug, author archive link and a hash of their email
 * address. That is defensible for a blog with pseudonymous authors. It is not for a
 * members' association, where the identity provider fills those fields with real names
 * of real people - the moment such a member publishes anything, their name becomes a
 * public, enumerable endpoint.
 *
 * Authenticated requests are untouched, so the block editor and anything else that
 * legitimately needs the user list keeps working.
 */
class User_Privacy {

	/**
	 * Initialize hooks
	 */
	public function init(): void {
		add_filter( 'rest_endpoints', array( $this, 'hide_user_endpoints_from_anonymous' ) );
	}

	/**
	 * Remove the users REST routes for requests that are not signed in
	 *
	 * @param array $endpoints REST endpoints, keyed by route.
	 * @return array
	 */
	public function hide_user_endpoints_from_anonymous( $endpoints ) {
		/**
		 * Filter whether the users REST routes are hidden from anonymous requests.
		 *
		 * Set to false when a site genuinely needs public author data, for instance a
		 * headless front end that renders bylines.
		 *
		 * @param bool $restrict Whether to hide the routes. Default true.
		 */
		if ( ! apply_filters( 'soli_passport_restrict_rest_users', true ) ) {
			return $endpoints;
		}

		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}
}
