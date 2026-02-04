# wp-soli-passport-plugin

WordPress plugin for OIDC identity provider functionality for Soli.

## Purpose

This plugin provides OAuth/OpenID Connect identity provider functionality:

1. **OIDC Client Management** - Register and manage OAuth clients
2. **Role Mappings** - Configure role assignments based on relation types or WP roles
3. **User Overrides** - Assign specific roles to individual users
4. **Dual-Mode Operation** - Works standalone or enhanced with wp-soli-admin-plugin

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    wp-soli-passport-plugin                      │
│                                                                 │
│  ┌─────────────────┐     ┌──────────────────────────────────┐  │
│  │ Standalone Mode │     │ Enhanced Mode (admin installed)  │  │
│  │                 │     │                                  │  │
│  │ WP Role → Role  │     │ Relatie Type → Role              │  │
│  │ mapping         │     │ + Groups/Instruments claims      │  │
│  │                 │     │ + WP Role fallback               │  │
│  └─────────────────┘     └──────────────────────────────────┘  │
│                                     │                           │
│                                     ▼                           │
│                          ┌──────────────────┐                   │
│                          │  Admin Bridge    │                   │
│                          │  (optional link) │                   │
│                          └────────┬─────────┘                   │
└───────────────────────────────────┼─────────────────────────────┘
                                    │ (if installed)
                                    ▼
┌─────────────────────────────────────────────────────────────────┐
│                    wp-soli-admin-plugin                         │
│                                                                 │
│  Relaties │ Onderdelen │ Instruments │ Relatie Types │ etc.    │
└─────────────────────────────────────────────────────────────────┘
```

## Plugin Structure

```
wp-soli-passport-plugin/
├── wp-soli-passport-plugin.php     # Main plugin file
├── updater.php                      # GitHub updater
├── uninstall.php                    # Cleanup on uninstall
├── readme.md                        # Version info for updater
├── CLAUDE.md                        # This documentation
├── package.json                     # NPM dependencies
├── tailwind.config.js               # Tailwind + DaisyUI config
├── playwright.config.js             # E2E test configuration
├── .wp-env.json                     # Local dev environment
├── includes/
│   ├── class-soli-passport-menu.php
│   ├── class-soli-passport-dependency-checker.php
│   ├── class-soli-passport-admin-bridge.php
│   ├── database/
│   │   ├── class-soli-passport-database.php
│   │   ├── class-soli-passport-clients-table.php
│   │   ├── class-soli-passport-user-roles-table.php
│   │   └── class-soli-passport-role-mappings-table.php
│   ├── oidc/
│   │   ├── class-soli-passport-oidc.php
│   │   └── class-soli-passport-roles.php
│   └── admin/pages/
│       ├── class-soli-passport-clients.php
│       ├── class-soli-passport-user-roles.php
│       └── class-soli-passport-role-mappings.php
├── src/css/admin.css
├── assets/
│   ├── css/admin.css
│   └── js/admin-tables.js
├── languages/
└── e2e/
```

## Database Tables

### wp_soli_passport_clients

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| client_id | varchar(100) | OAuth client identifier (unique) |
| name | varchar(255) | Display name |
| secret | varchar(255) | Client secret (hashed) |
| redirect_uri | varchar(500) | Callback URL |
| actief | tinyint(1) | Active status |

### wp_soli_passport_user_roles

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| wp_user_id | bigint | WordPress user ID (nullable) |
| relatie_id | bigint | Relatie ID (nullable, admin plugin) |
| client_id | varchar(100) | OAuth client identifier |
| role | varchar(100) | Role override |

### wp_soli_passport_role_mappings

Dual-mode schema supporting both WP roles and relation types:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| client_id | varchar(100) | OAuth client identifier |
| mapping_type | enum | 'wp_role' or 'relatie_type' |
| wp_role | varchar(100) | WordPress role (nullable) |
| relatie_type_id | bigint | Relation type ID (nullable) |
| role | varchar(100) | Role to assign |
| priority | int | Priority for this mapping |

## Key Components

### Dependency Checker

Shows admin notices based on plugin status:
- **Error** if openid-connect-server not installed (required)
- **Info** if wp-soli-admin-plugin not installed (standalone mode notice)

### Admin Bridge

Safe wrapper for accessing admin plugin data (returns empty arrays/null if not installed):

```php
Admin_Bridge::is_admin_plugin_active()
Admin_Bridge::get_relatie_by_wp_user_id($user_id)
Admin_Bridge::get_relatie_type_ids($relatie_id)
Admin_Bridge::get_relatie_groups($relatie_id)
Admin_Bridge::get_relatie_instruments($relatie_id)
Admin_Bridge::get_all_relatie_types()
```

### Role Resolution

```
1. Check WP user-specific override
2. IF admin plugin installed:
   a. Find relatie for WP user (by wp_username)
   b. Check relatie-specific override
   c. Map relatie types → role (by priority)
3. Map WP user roles → role (fallback, always available)
4. Return "no-access" if no mapping found
```

## Dual-Mode Role Mappings

The role mappings table supports both modes:

**Standalone mode (WP roles only):**
```php
// mapping_type = 'wp_role', wp_role = 'subscriber', relatie_type_id = NULL
```

**Enhanced mode (relation types):**
```php
// mapping_type = 'relatie_type', wp_role = NULL, relatie_type_id = 5
```

## Default WP Role Priorities

| WordPress Role | Default Priority |
|----------------|------------------|
| administrator  | 5 |
| editor         | 4 |
| author         | 3 |
| contributor    | 2 |
| subscriber     | 1 |

## Development Guidelines

### Coding Standards

- Namespace: `Soli\Passport`
- Function prefix: `soli_passport_`
- Hook prefix: `soli_passport_`
- Text domain: `soli-passport`
- Table prefix: `soli_passport_`
- Constants: `SOLI_PASSPORT__*`

### Styling

Same as admin plugin: Tailwind CSS + DaisyUI

### Testing

```bash
# Start environment (includes OIDC client on 8888, provider on 8889)
npm run wp-env:start

# Run tests
npm run test

# Run with browser visible
npm run test:headed
```

## OIDC Integration

Hooks into the OpenID Connect Server plugin:

- `oidc_registered_clients` - Registers clients from database
- `oidc_user_claims` - Adds role, groups, instruments claims
- `rest_pre_dispatch` - Tracks client_id during auth flow

## Security

- Client secrets are hashed with `wp_hash_password()`
- All forms use nonces
- Capability checks (`manage_options`) on all admin pages
- Prepared SQL statements throughout
