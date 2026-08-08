#!/bin/bash
#
# wp-env setup script
#
# Runs after `wp-env start` and configures the development environment (8910) as an
# OIDC client of the stub provider that is mapped in at /oidc-stub.
#
# The tests environment (8911) is only used for PHPUnit and needs no OIDC config, but it
# does need WP_DEBUG - see the env.tests.config block in .wp-env.json for why.
#
# Browser-facing endpoints use the published port (8910 by default), server-to-server
# endpoints use localhost (port 80 inside the container). Keeping those apart is what
# lets this run identically on a laptop and in CI - no cross-container networking and
# no host.docker.internal involved.

set -e

# Honour the same port override wp-env itself uses, so this works alongside other
# wp-env projects: WP_ENV_PORT=8886 npm run wp-env:start
CLIENT_PORT="${WP_ENV_PORT:-8910}"

STUB_BROWSER_BASE="http://localhost:${CLIENT_PORT}/oidc-stub/stub-provider/index.php"
STUB_INTERNAL_BASE="http://localhost/oidc-stub/stub-provider/index.php"

echo "=== Setting up wp-env OIDC client environment ==="

# -----------------------------------------------------------------------------
# Stub provider signing keys (generated locally, never committed)
# -----------------------------------------------------------------------------
bash ./tests/stub-provider/generate-keys.sh

# -----------------------------------------------------------------------------
# PHPUnit dependency for the unit suite
# -----------------------------------------------------------------------------
# The WordPress test suite needs PHPUnit 9 and the Yoast polyfills. Both go into
# the container instead of a composer.json here, so the plugin itself stays free
# of Composer dependencies. See tests/install-test-deps.sh for the version pins.
wp-env run tests-cli -- bash /var/www/html/wp-content/plugins/wp-soli-passport-plugin/tests/install-test-deps.sh

# -----------------------------------------------------------------------------
# Development environment (OIDC client)
# -----------------------------------------------------------------------------
echo ""
echo "--- Configuring OIDC client (port ${CLIENT_PORT}) ---"

# After a core update an existing database serves the "Database Update Required"
# screen instead of wp-admin, which looks exactly like a broken login.
wp-env run cli wp core update-db

wp-env run cli wp plugin activate wp-soli-passport-plugin
wp-env run cli wp plugin activate daggerhart-openid-connect-generic

# Pretty permalinks, so the OIDC callback and login URLs behave like production.
wp-env run cli wp rewrite structure '/%postname%/'
wp-env run cli wp rewrite flush

# See OpenID_Connect_Generic::bootstrap() for the full list of settings keys.
#
# Notable choices:
#   scope                requests 'roles' and 'assignments'; without them the
#                        provider omits those claims entirely
#   endpoint_jwks        enables RS256 signature verification (without it the
#                        client logs a security warning and trusts the token)
#   issuer               fixed value the stub signs its tokens with
#   allow_internal_idp   permits server-side calls to localhost, which
#                        wp_safe_remote_get() would otherwise block
#   link_existing_users  off, so users are matched on the 'sub' claim only and
#                        two provider accounts sharing an email stay separate
wp-env run cli wp option update openid_connect_generic_settings '{
  "login_type": "auto",
  "client_id": "soli-dev-client",
  "client_secret": "dev-secret-12345",
  "scope": "openid email profile roles assignments",
  "endpoint_login": "'"${STUB_BROWSER_BASE}"'?action=authorize",
  "endpoint_token": "'"${STUB_INTERNAL_BASE}"'?action=token",
  "endpoint_userinfo": "'"${STUB_INTERNAL_BASE}"'?action=userinfo",
  "endpoint_jwks": "'"${STUB_INTERNAL_BASE}"'?action=jwks",
  "endpoint_end_session": "'"${STUB_BROWSER_BASE}"'?action=logout",
  "issuer": "https://stub-provider.test",
  "jwks_cache_ttl": 3600,
  "allow_internal_idp": 1,
  "identity_key": "preferred_username",
  "nickname_key": "preferred_username",
  "email_format": "{email}",
  "displayname_format": "{given_name} {family_name}",
  "identify_with_username": false,
  "link_existing_users": 0,
  "create_if_does_not_exist": 1,
  "redirect_user_back": 0,
  "enable_logging": 1,
  "log_limit": 1000
}' --format=json

echo "OIDC client configured."

echo ""
echo "=== Setup Complete ==="
echo ""
echo "WordPress client:   http://localhost:${CLIENT_PORT}"
echo "  - Local login:    http://localhost:${CLIENT_PORT}/wp-login.php?bypass-sso (admin / password)"
echo "  - SSO login:      http://localhost:${CLIENT_PORT}/wp-login.php (redirects to the stub provider)"
echo ""
echo "Stub provider:      ${STUB_BROWSER_BASE}?action=openid-configuration"
echo "  - Accounts come from tests/fixtures/oidc-claims.json"
echo ""
