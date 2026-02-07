#!/bin/bash
#
# wp-env setup script for OIDC testing
#
# This script runs after wp-env start and configures:
# - Tests environment (8889): OIDC Provider with test data
# - Development environment (8888): OIDC Client connected to provider
#

set -e

echo "=== Setting up wp-env OIDC testing environments ==="

# =============================================================================
# TESTS ENVIRONMENT (OIDC Provider) - localhost:8889
# =============================================================================
echo ""
echo "--- Configuring Tests Environment (OIDC Provider - localhost:8889) ---"

# Activate plugins - passport first, then admin (if available), then OIDC server
wp-env run tests-cli wp plugin activate wp-soli-passport-plugin
wp-env run tests-cli wp plugin activate wp-soli-admin-plugin 2>/dev/null || echo "Admin plugin not available (standalone mode)"
wp-env run tests-cli wp plugin activate openid-connect-server

# Insert passport test data (OIDC clients and role mappings)
wp-env run tests-cli wp soli-passport test-data insert

# Insert admin test data if admin plugin is active
wp-env run tests-cli wp soli test-data insert 2>/dev/null || echo "Admin test data not inserted (admin plugin not active)"

# Update admin user with name fields (required for OIDC claims)
wp-env run tests-cli wp user update admin --first_name=Admin --last_name=User --display_name="Admin User"

# Create test user for E2E testing
wp-env run tests-cli wp user create testuser testuser@soli.nl --user_pass=testpass --role=subscriber --first_name=Test --last_name=User --display_name="Test User" 2>/dev/null || echo "Test user already exists"

# Create relatie for testuser with lid type, group membership, and instrument (enhanced mode)
wp-env run tests-cli wp eval '
global $wpdb;

// Check if admin plugin is active (relaties table exists)
$relaties_table = $wpdb->prefix . "soli_relaties";
$table_exists = $wpdb->get_var($wpdb->prepare(
    "SHOW TABLES LIKE %s",
    $relaties_table
)) === $relaties_table;

if (!$table_exists) {
    echo "Admin plugin not active - skipping relatie creation (standalone mode)\n";
    return;
}

// Get testuser WP user ID
$user = get_user_by("login", "testuser");
if (!$user) {
    echo "Testuser WP user not found\n";
    return;
}
$wp_user_id = $user->ID;

// Check if testuser relatie already exists (by wp_user_id)
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$relaties_table} WHERE wp_user_id = %d LIMIT 1",
    $wp_user_id
));

if ($existing) {
    echo "Testuser relatie already exists (ID: {$existing})\n";
    return;
}

// Also check by email
$email_table = $wpdb->prefix . "soli_email";
$existing_by_email = $wpdb->get_var($wpdb->prepare(
    "SELECT relatie_id FROM {$email_table} WHERE email = %s LIMIT 1",
    "testuser@soli.nl"
));

if ($existing_by_email) {
    // Link existing relatie to wp_user_id
    $wpdb->update($relaties_table,
        array("wp_user_id" => $wp_user_id),
        array("id" => $existing_by_email)
    );
    echo "Linked existing relatie (ID: {$existing_by_email}) to wp_user_id {$wp_user_id}\n";
    return;
}

// Get next relatie_id
$max_relatie_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(relatie_id), 0) FROM {$relaties_table}");
$next_relatie_id = $max_relatie_id + 1;

// Create relatie for testuser
$wpdb->insert($relaties_table, array(
    "relatie_id" => $next_relatie_id,
    "wp_user_id" => $wp_user_id,
    "voornaam" => "Test",
    "achternaam" => "User",
    "roepnaam" => "Test",
    "geslacht" => "O",
    "actief" => 1,
));
$id = $wpdb->insert_id;
echo "Created relatie ID: {$id} (relatie_id: {$next_relatie_id}) with wp_user_id: {$wp_user_id}\n";

// Link email to relatie
$wpdb->insert($email_table, array(
    "relatie_id" => $id,
    "email" => "testuser@soli.nl",
    "van" => date("Y-m-d"),
));
echo "Linked email to relatie\n";

// Get "lid" relation type
$types_table = $wpdb->prefix . "soli_relatie_types";
$lid_type_id = $wpdb->get_var("SELECT id FROM {$types_table} WHERE naam = \"lid\" LIMIT 1");

if ($lid_type_id) {
    // Assign lid type to relatie
    $relatie_type_table = $wpdb->prefix . "soli_relatie_relatie_type";
    $wpdb->insert($relatie_type_table, array(
        "relatie_id" => $id,
        "relatie_type_id" => $lid_type_id,
        "van" => date("Y-m-d"),
    ));
    echo "Assigned lid type to relatie\n";
}

// Get "Harmonie orkest" group (or first available orkest)
$onderdelen_table = $wpdb->prefix . "soli_onderdelen";
$onderdeel = $wpdb->get_row("SELECT id, naam FROM {$onderdelen_table} WHERE naam = \"Harmonie orkest\" OR onderdeel_type = \"orkest\" ORDER BY naam = \"Harmonie orkest\" DESC LIMIT 1");

if ($onderdeel) {
    // Assign to group
    $relatie_onderdeel_table = $wpdb->prefix . "soli_relatie_onderdeel";
    $wpdb->insert($relatie_onderdeel_table, array(
        "relatie_id" => $id,
        "onderdeel_id" => $onderdeel->id,
        "van" => date("Y-m-d"),
    ));
    echo "Assigned to group: {$onderdeel->naam}\n";
}

// Assign instrument (Trompet) for the group
if ($onderdeel) {
    $relatie_instrument_table = $wpdb->prefix . "soli_relatie_instrument";
    $wpdb->insert($relatie_instrument_table, array(
        "relatie_id" => $id,
        "onderdeel_id" => $onderdeel->id,
        "instrument_type" => "Trompet",
        "van" => date("Y-m-d"),
    ));
    echo "Assigned instrument: Trompet in {$onderdeel->naam}\n";
}

echo "Testuser relatie setup complete!\n";
'

# Enable pretty permalinks (required for REST API endpoints)
# --hard flag is required to write .htaccess in Docker environment
wp-env run tests-cli wp rewrite structure '/%postname%/' --hard

echo "Tests environment configured."

# =============================================================================
# DEVELOPMENT ENVIRONMENT (OIDC Client) - localhost:8888
# =============================================================================
echo ""
echo "--- Configuring Development Environment (OIDC Client - localhost:8888) ---"

# Activate plugins - passport plugin and OIDC client
wp-env run cli wp plugin activate wp-soli-passport-plugin
wp-env run cli wp plugin activate daggerhart-openid-connect-generic

# Configure OIDC client settings to connect to the provider (8889)
# The openid-connect-generic plugin stores settings in the 'openid_connect_generic_settings' option
wp-env run cli wp option update openid_connect_generic_settings '{
  "login_type": "auto",
  "client_id": "soli-dev-client",
  "client_secret": "dev-secret-12345",
  "scope": "openid email profile",
  "endpoint_login": "http://localhost:8889/?rest_route=/openid-connect/authorize",
  "endpoint_userinfo": "http://host.docker.internal:8889/?rest_route=/openid-connect/userinfo",
  "endpoint_token": "http://host.docker.internal:8889/?rest_route=/openid-connect/token",
  "endpoint_end_session": "http://localhost:8889/wp-login.php?action=logout",
  "acr_values": "",
  "enable_logging": "1",
  "log_limit": "1000",
  "link_existing_users": "1",
  "create_if_does_not_exist": "1",
  "redirect_user_back": "1",
  "redirect_on_logout": "1",
  "enforce_privacy": "0",
  "alternate_redirect_uri": "0",
  "identity_key": "nickname",
  "nickname_key": "nickname",
  "email_format": "{email}",
  "displayname_format": "{given_name} {family_name}",
  "identify_with_username": "1",
  "state_time_limit": "300",
  "token_refresh_enable": "1",
  "http_request_timeout": "5",
  "enable_sso": "1"
}' --format=json

echo "Development environment configured."

# =============================================================================
# SUMMARY
# =============================================================================
echo ""
echo "=== Setup Complete ==="
echo ""
echo "OIDC Provider (tests):     http://localhost:8889"
echo "  - Admin:                 admin / password"
echo "  - Test user:             testuser / testpass"
echo ""
echo "OIDC Client (development): http://localhost:8888"
echo "  - Admin:                 admin / password"
echo "  - Login with OIDC:       Auto-redirects to provider (SSO)"
echo ""
echo "OIDC Client Credentials:"
echo "  - Client ID:             soli-dev-client"
echo "  - Client Secret:         dev-secret-12345"
echo ""
