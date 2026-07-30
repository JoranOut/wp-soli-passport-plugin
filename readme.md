# Soli Passport Plugin

OIDC client adapter for Soli WordPress sites.

~Current Version:0.1.0~

~Plugin Name: wp-soli-passport-plugin~

## Description

Applies the authorization decisions made by the Soli identity provider (`admin.soli.nl`) to
a WordPress site. The provider decides which role a user gets per application; this plugin
puts that role on the local WordPress user, refuses the login when no role was granted, and
stores the user's orchestra and instrument assignments for other Soli plugins to read.

It is an adapter on top of the [OpenID Connect Generic](https://wordpress.org/plugins/daggerhart-openid-connect-generic/)
plugin, which handles the OIDC protocol itself.

## Features

- **Role sync**: the `roles` claim from the provider becomes the local WordPress role
- **Access control**: no granted role means the login is refused and no user is created
- **Assignments**: the `assignments` claim is stored as user meta for other plugins
- **SSO bypass**: `wp-login.php?bypass-sso` for local WordPress login
- **Error recovery**: a failed SSO login offers a way to sign in as someone else instead of
  bouncing back into a redirect loop

## Requirements

- WordPress 6.0+
- PHP 8.3+
- [OpenID Connect Generic](https://wordpress.org/plugins/daggerhart-openid-connect-generic/) plugin (required)

## Installation

1. Upload the plugin files to `/wp-content/plugins/wp-soli-passport-plugin`
2. Install and activate the OpenID Connect Generic plugin
3. Activate this plugin through the WordPress admin
4. Configure the OIDC client against `admin.soli.nl` (see CLAUDE.md for the required settings)

## Claim contract

The provider emits, per OAuth client:

| Claim | Scope | Meaning |
|-------|-------|---------|
| `roles` | `roles` | At most one entry: the role granted for this client. Empty means no access. |
| `assignments` | `assignments` | Orchestra and instrument assignments. |

Requesting the scope matters: without `roles` in the client's scope the claim is absent and
every login is refused.

## Development

```bash
# Start local environment (WordPress client + stub OIDC provider)
npm run wp-env:start

# Run all tests
npm run test

# Unit tests only
npm run test:unit

# End-to-end tests only
npm run test:e2e
```

Run alongside another wp-env project by overriding the ports:

```bash
WP_ENV_PORT=8886 WP_ENV_TESTS_PORT=8887 npm run wp-env:start
WP_ENV_PORT=8886 npm run test
```

## Changelog

### 0.1.0
- Initial release
- Role sync from the provider's `roles` claim, with login refused when no role is granted
- Assignments stored as user meta
- SSO bypass and error recovery on the login page
