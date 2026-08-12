<?php
/**
 * Stub OIDC provider for local development and e2e tests.
 *
 * Stands in for the real identity provider (admin.soli.nl, Laravel Passport) so the
 * client can be tested without a second WordPress, a database or any cross-container
 * networking. It is mapped into the WordPress container at /oidc-stub and reachable
 * both from the browser (http://localhost:8910/oidc-stub/) and from PHP inside the
 * container (http://localhost/oidc-stub/).
 *
 * It deliberately mirrors the real provider's scope-gated claim contract: omit the
 * 'roles' scope and no roles claim is returned, exactly as in production.
 *
 * The whole tests/ directory is mapped to /oidc-stub, so this file is served at
 * /oidc-stub/stub-provider/index.php and reads its fixtures from ../fixtures/.
 *
 * Endpoints (all via ?action=):
 *   openid-configuration  discovery document
 *   jwks                  public signing key
 *   authorize             account picker, redirects back with a code
 *   token                 exchanges the code for an id_token
 *   userinfo              returns the same claims for a bearer token
 *
 * `authorize` also accepts `stub_sign` to weaken how the id_token is signed, so tests can
 * check that the client actually verifies signatures against the JWKS:
 *
 *   valid      (default) RS256, signed with the key the JWKS publishes
 *   wrong-key  RS256, signed with a key the JWKS does not publish
 *   alg-none   unsigned, `alg: none`, no signature segment
 *
 * The mode travels in the authorization code, so the token endpoint needs no state.
 *
 * NOT FOR PRODUCTION USE. It performs no real authentication.
 *
 * @package Soli\Passport
 */

declare( strict_types = 1 );

/**
 * Refuse to run anywhere that is not a local test environment.
 *
 * publish.js already keeps tests/ out of the release zip, so this should be
 * unreachable in production. It is here because the cost of being wrong is an
 * unauthenticated endpoint that hands out signed tokens: if this file ever does end
 * up on a real host, it must do nothing rather than serve.
 */
( static function (): void {
	$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host );

	$allowed = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true )
		|| str_ends_with( $host, '.test' )
		|| str_ends_with( $host, '.localhost' );

	if ( $allowed ) {
		return;
	}

	http_response_code( 404 );
	header( 'Content-Type: text/plain' );
	echo 'Not found.';
	exit;
} )();

const STUB_ISSUER        = 'https://stub-provider.test';
const STUB_CLIENT_ID     = 'soli-dev-client';
const STUB_CLIENT_SECRET = 'dev-secret-12345';
const STUB_KEY_ID        = 'stub';

/**
 * Signing modes the stub accepts, see the file docblock.
 */
const STUB_SIGN_MODES = array( 'valid', 'wrong-key', 'alg-none' );

/**
 * Base URL the browser uses to reach this stub.
 *
 * Derived from the incoming request so the stub does not care which port wp-env
 * published. Only browser-facing responses use it; server-to-server endpoints are
 * configured separately on the client because they resolve inside the container.
 *
 * @return string
 */
function stub_public_base(): string {
	$scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
	$host   = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
	$script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '/oidc-stub/stub-provider/index.php' );

	return $scheme . '://' . $host . $script;
}

/**
 * Base64url encode.
 *
 * @param string $data Raw data.
 * @return string
 */
function stub_b64u( string $data ): string {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Base64url decode.
 *
 * @param string $data Encoded data.
 * @return string
 */
function stub_b64u_decode( string $data ): string {
	return (string) base64_decode( strtr( $data, '-_', '+/' ), false );
}

/**
 * Send a JSON response and exit.
 *
 * @param array $data   Response body.
 * @param int   $status HTTP status code.
 * @return never
 */
function stub_json( array $data, int $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

/**
 * Send an error response and exit.
 *
 * @param string $code    OAuth error code.
 * @param string $message Human readable description.
 * @param int    $status  HTTP status code.
 * @return never
 */
function stub_error( string $code, string $message, int $status = 400 ) {
	stub_json(
		array(
			'error'             => $code,
			'error_description' => $message,
		),
		$status
	);
}

/**
 * Load the shared claim fixtures.
 *
 * @return array<string, array> Fixture key => claim set.
 */
function stub_fixtures(): array {
	$path = __DIR__ . '/../fixtures/oidc-claims.json';

	if ( ! is_readable( $path ) ) {
		stub_error( 'server_error', 'Claim fixtures not found at ' . $path, 500 );
	}

	$fixtures = json_decode( (string) file_get_contents( $path ), true );

	if ( ! is_array( $fixtures ) ) {
		stub_error( 'server_error', 'Claim fixtures are not valid JSON', 500 );
	}

	unset( $fixtures['_comment'] );

	return $fixtures;
}

/**
 * Read the signing key generated by .wp-env-setup.sh.
 *
 * @param string $which 'private' or 'public'.
 * @return string PEM contents.
 */
function stub_key( string $which ): string {
	$path = __DIR__ . '/keys/' . $which . '.key';

	if ( ! is_readable( $path ) ) {
		stub_error(
			'server_error',
			'Signing key missing at ' . $path . '. Run `npm run stub:keys` (or wp-env start) to generate it.',
			500
		);
	}

	return (string) file_get_contents( $path );
}

/**
 * Filter a claim set by granted scopes, mirroring the real provider.
 *
 * @param array    $claims Full fixture claim set.
 * @param string[] $scopes Granted scopes.
 * @return array Scope-filtered claims.
 */
function stub_filter_claims( array $claims, array $scopes ): array {
	$filtered = array( 'sub' => $claims['sub'] );

	$by_scope = array(
		'profile'     => array( 'name', 'preferred_username', 'given_name', 'family_name' ),
		'email'       => array( 'email', 'email_verified' ),
		'roles'       => array( 'roles' ),
		'assignments' => array( 'assignments' ),
	);

	foreach ( $by_scope as $scope => $keys ) {
		if ( ! in_array( $scope, $scopes, true ) ) {
			continue;
		}

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $claims ) ) {
				$filtered[ $key ] = $claims[ $key ];
			}
		}
	}

	return $filtered;
}

/**
 * Sign an ID token with RS256.
 *
 * @param array  $claims Token claims.
 * @param string $mode   Signing mode, one of STUB_SIGN_MODES.
 * @return string Compact JWS.
 */
function stub_sign_id_token( array $claims, string $mode = 'valid' ): string {
	$header = array(
		'typ' => 'JWT',
		'alg' => ( 'alg-none' === $mode ) ? 'none' : 'RS256',
		'kid' => STUB_KEY_ID,
	);

	$signing_input = stub_b64u( (string) json_encode( $header ) ) . '.' . stub_b64u( (string) json_encode( $claims ) );

	// Unsigned: three segments, the last one empty. A client that reads the payload
	// anyway trusts claims anyone could have written.
	if ( 'alg-none' === $mode ) {
		return $signing_input . '.';
	}

	// wrong-key signs with a key the JWKS does not publish, so the signature is
	// well-formed but unverifiable.
	$pem = stub_key( 'wrong-key' === $mode ? 'wrong-private' : 'private' );

	$key = openssl_pkey_get_private( $pem );

	if ( false === $key ) {
		stub_error( 'server_error', 'Could not read the stub private key', 500 );
	}

	$signature = '';
	if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
		stub_error( 'server_error', 'Could not sign the id_token', 500 );
	}

	return $signing_input . '.' . stub_b64u( $signature );
}

/**
 * Handle ?action=openid-configuration
 *
 * @return never
 */
function stub_handle_discovery() {
	stub_json(
		array(
			'issuer'                              => STUB_ISSUER,
			'authorization_endpoint'              => stub_public_base() . '?action=authorize',
			'token_endpoint'                      => stub_public_base() . '?action=token',
			'userinfo_endpoint'                   => stub_public_base() . '?action=userinfo',
			'jwks_uri'                            => stub_public_base() . '?action=jwks',
			'end_session_endpoint'                => stub_public_base() . '?action=logout',
			'response_types_supported'            => array( 'code' ),
			'subject_types_supported'             => array( 'public' ),
			'id_token_signing_alg_values_supported' => array( 'RS256' ),
			'scopes_supported'                    => array( 'openid', 'profile', 'email', 'roles', 'assignments' ),
		)
	);
}

/**
 * Handle ?action=jwks
 *
 * @return never
 */
function stub_handle_jwks() {
	$details = openssl_pkey_get_details( openssl_pkey_get_public( stub_key( 'public' ) ) );

	if ( ! is_array( $details ) || ! isset( $details['rsa']['n'], $details['rsa']['e'] ) ) {
		stub_error( 'server_error', 'Could not read the stub public key', 500 );
	}

	stub_json(
		array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'use' => 'sig',
					'alg' => 'RS256',
					'kid' => STUB_KEY_ID,
					'n'   => stub_b64u( $details['rsa']['n'] ),
					'e'   => stub_b64u( $details['rsa']['e'] ),
				),
			),
		)
	);
}

/**
 * Handle ?action=authorize
 *
 * Shows an account picker. Each fixture user is one button, which lets e2e tests
 * choose deterministically which claim set comes back.
 *
 * @return never
 */
function stub_handle_authorize() {
	$client_id    = (string) ( $_GET['client_id'] ?? '' );
	$redirect_uri = (string) ( $_GET['redirect_uri'] ?? '' );

	if ( STUB_CLIENT_ID !== $client_id ) {
		stub_error( 'unauthorized_client', 'Unknown client_id: ' . $client_id );
	}

	if ( '' === $redirect_uri ) {
		stub_error( 'invalid_request', 'Missing redirect_uri' );
	}

	$state = (string) ( $_GET['state'] ?? '' );
	$nonce = (string) ( $_GET['nonce'] ?? '' );
	$scope = (string) ( $_GET['scope'] ?? 'openid' );
	$user  = (string) ( $_GET['stub_user'] ?? '' );
	$sign  = (string) ( $_GET['stub_sign'] ?? 'valid' );

	if ( ! in_array( $sign, STUB_SIGN_MODES, true ) ) {
		stub_error( 'invalid_request', 'Unknown stub_sign mode: ' . $sign );
	}

	$fixtures = stub_fixtures();

	// Approving a specific account: redirect back to the client with a code.
	if ( '' !== $user ) {
		if ( ! isset( $fixtures[ $user ] ) ) {
			stub_error( 'invalid_request', 'Unknown stub user: ' . $user );
		}

		$code = stub_b64u(
			(string) json_encode(
				array(
					'user'  => $user,
					'scope' => $scope,
					'nonce' => $nonce,
					'sign'  => $sign,
				)
			)
		);

		$separator = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		$location  = $redirect_uri . $separator . 'code=' . rawurlencode( $code );

		if ( '' !== $state ) {
			$location .= '&state=' . rawurlencode( $state );
		}

		header( 'Location: ' . $location, true, 302 );
		exit;
	}

	// Otherwise render the account picker.
	header( 'Content-Type: text/html; charset=utf-8' );

	$query_base = array(
		'action'       => 'authorize',
		'client_id'    => $client_id,
		'redirect_uri' => $redirect_uri,
		'state'        => $state,
		'nonce'        => $nonce,
		'scope'        => $scope,
		'stub_sign'    => $sign,
	);

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<title>Stub provider sign-in</title>';
	echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem}';
	echo 'h1{font-size:1.25rem}a{display:block;padding:.75rem 1rem;margin:.5rem 0;border:1px solid #ccc;';
	echo 'border-radius:.25rem;text-decoration:none;color:#1d2327}a:hover{background:#f0f0f1}';
	echo 'code{color:#646970;font-size:.875rem}</style></head><body>';
	echo '<h1>Stub provider &mdash; choose an account</h1>';
	echo '<p><code>scope: ' . htmlspecialchars( $scope, ENT_QUOTES ) . '</code></p>';

	foreach ( $fixtures as $key => $claims ) {
		$url  = stub_public_base() . '?' . http_build_query( $query_base + array( 'stub_user' => $key ) );
		$name = (string) ( $claims['name'] ?? $key );

		echo '<a id="stub-user-' . htmlspecialchars( $key, ENT_QUOTES ) . '" href="' . htmlspecialchars( $url, ENT_QUOTES ) . '">';
		echo htmlspecialchars( $name, ENT_QUOTES );
		echo '<br><code>' . htmlspecialchars( $key, ENT_QUOTES ) . '</code></a>';
	}

	echo '</body></html>';
	exit;
}

/**
 * Handle ?action=token
 *
 * @return never
 */
function stub_handle_token() {
	$client_id     = (string) ( $_POST['client_id'] ?? '' );
	$client_secret = (string) ( $_POST['client_secret'] ?? '' );

	// Fall back to HTTP Basic client authentication.
	if ( '' === $client_id && isset( $_SERVER['PHP_AUTH_USER'] ) ) {
		$client_id     = (string) $_SERVER['PHP_AUTH_USER'];
		$client_secret = (string) ( $_SERVER['PHP_AUTH_PW'] ?? '' );
	}

	if ( STUB_CLIENT_ID !== $client_id || STUB_CLIENT_SECRET !== $client_secret ) {
		stub_error( 'invalid_client', 'Bad client credentials', 401 );
	}

	$code    = (string) ( $_POST['code'] ?? '' );
	$payload = json_decode( stub_b64u_decode( $code ), true );

	if ( ! is_array( $payload ) || ! isset( $payload['user'] ) ) {
		stub_error( 'invalid_grant', 'Unreadable authorization code' );
	}

	$fixtures = stub_fixtures();
	$user     = (string) $payload['user'];

	if ( ! isset( $fixtures[ $user ] ) ) {
		stub_error( 'invalid_grant', 'Unknown stub user: ' . $user );
	}

	$sign = (string) ( $payload['sign'] ?? 'valid' );

	if ( ! in_array( $sign, STUB_SIGN_MODES, true ) ) {
		stub_error( 'invalid_grant', 'Unknown stub_sign mode: ' . $sign );
	}

	$scopes = array_values( array_filter( explode( ' ', (string) ( $payload['scope'] ?? 'openid' ) ) ) );
	$claims = stub_filter_claims( $fixtures[ $user ], $scopes );

	$now = time();

	$id_token_claims = array_merge(
		$claims,
		array(
			'iss' => STUB_ISSUER,
			'aud' => STUB_CLIENT_ID,
			'iat' => $now,
			'exp' => $now + 3600,
		)
	);

	if ( ! empty( $payload['nonce'] ) ) {
		$id_token_claims['nonce'] = (string) $payload['nonce'];
	}

	// The access token carries the grant so userinfo needs no server-side state.
	$access_token = stub_b64u(
		(string) json_encode(
			array(
				'user'  => $user,
				'scope' => implode( ' ', $scopes ),
			)
		)
	);

	stub_json(
		array(
			'access_token' => $access_token,
			'token_type'   => 'Bearer',
			'expires_in'   => 3600,
			'scope'        => implode( ' ', $scopes ),
			'id_token'     => stub_sign_id_token( $id_token_claims, $sign ),
		)
	);
}

/**
 * Handle ?action=userinfo
 *
 * @return never
 */
function stub_handle_userinfo() {
	$token = '';

	// Depending on the SAPI the Authorization header lands in different places, and
	// Apache hides it from $_SERVER unless CGIPassAuth is on.
	$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );

	if ( '' === $header && function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
				$header = (string) $value;
				break;
			}
		}
	}

	if ( 0 === stripos( $header, 'bearer ' ) ) {
		$token = substr( $header, 7 );
	} elseif ( isset( $_GET['access_token'] ) ) {
		$token = (string) $_GET['access_token'];
	} elseif ( isset( $_POST['access_token'] ) ) {
		$token = (string) $_POST['access_token'];
	}

	$payload = json_decode( stub_b64u_decode( $token ), true );

	if ( ! is_array( $payload ) || ! isset( $payload['user'] ) ) {
		stub_error( 'invalid_token', 'Missing or unreadable access token', 401 );
	}

	$fixtures = stub_fixtures();
	$user     = (string) $payload['user'];

	if ( ! isset( $fixtures[ $user ] ) ) {
		stub_error( 'invalid_token', 'Unknown stub user: ' . $user, 401 );
	}

	$scopes = array_values( array_filter( explode( ' ', (string) ( $payload['scope'] ?? 'openid' ) ) ) );

	stub_json( stub_filter_claims( $fixtures[ $user ], $scopes ) );
}

/**
 * Handle ?action=logout
 *
 * @return never
 */
function stub_handle_logout() {
	$redirect_uri = (string) ( $_GET['redirect_uri'] ?? '' );

	if ( '' !== $redirect_uri ) {
		header( 'Location: ' . $redirect_uri, true, 302 );
		exit;
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Signed out</title></head>';
	echo '<body><p id="stub-signed-out">Signed out of the stub provider.</p></body></html>';
	exit;
}

$action = (string) ( $_GET['action'] ?? '' );

switch ( $action ) {
	case 'openid-configuration':
		stub_handle_discovery();
		// no break, handlers exit.
	case 'jwks':
		stub_handle_jwks();
	case 'authorize':
		stub_handle_authorize();
	case 'token':
		stub_handle_token();
	case 'userinfo':
		stub_handle_userinfo();
	case 'logout':
		stub_handle_logout();
	default:
		stub_error( 'invalid_request', 'Unknown action: ' . $action, 404 );
}
