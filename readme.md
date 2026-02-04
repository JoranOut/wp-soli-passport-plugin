# Soli Passport Plugin

OIDC identity provider functionality for Soli.

~Current Version:0.1.0~

~Plugin Name: wp-soli-passport-plugin~

## Description

This plugin extends the OpenID Connect Server plugin to provide identity provider functionality for Soli applications. It manages OIDC clients, user role mappings, and provides rich claims including groups and instruments.

## Features

- **OIDC Client Management**: Register and manage OAuth clients
- **Role Mappings**: Configure role assignments based on relation types or WordPress roles
- **User Role Overrides**: Assign specific roles to individual users or relations
- **Dual-Mode Operation**:
  - **Standalone**: Works with WordPress users and roles only
  - **Enhanced**: Integrates with wp-soli-admin-plugin for relation-based role mappings

## Requirements

- WordPress 6.0+
- PHP 8.3+
- [OpenID Connect Server](https://wordpress.org/plugins/openid-connect-server/) plugin (required)
- [Soli Admin Plugin](https://github.com/Muziekvereniging-Soli/wp-soli-admin-plugin) (optional, for enhanced mode)

## Installation

1. Upload the plugin files to `/wp-content/plugins/wp-soli-passport-plugin`
2. Install and activate the OpenID Connect Server plugin
3. Activate this plugin through the WordPress admin

## Usage

### Standalone Mode

When wp-soli-admin-plugin is not installed:
- Role mappings are based on WordPress user roles
- OIDC claims include user role only
- Groups and instruments claims are empty arrays

### Enhanced Mode

When wp-soli-admin-plugin is installed:
- Role mappings can be based on relation types (lid, docent, dirigent, donateur)
- OIDC claims include groups (onderdelen) and instruments
- WordPress role mappings serve as fallback

## Role Resolution Order

1. User-specific override (by WP user ID)
2. Relatie-specific override (if admin plugin installed)
3. Relation type mappings by priority (if admin plugin installed)
4. WordPress role mappings
5. Default: "no-access"

## Development

```bash
# Start local environment
npm run wp-env:start

# Build CSS
npm run build

# Run tests
npm run test
```

## Changelog

### 0.1.0
- Initial release
- OIDC client management
- Dual-mode role mappings (WP roles and relation types)
- User role overrides
- Admin bridge for wp-soli-admin-plugin integration
