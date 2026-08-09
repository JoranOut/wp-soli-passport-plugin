# wp-soli-passport-plugin

WordPress-side adapter for Soli single sign-on.

## Purpose

The identity provider is `laravel-soli-administration` (Laravel Passport + OIDC), serving
`admin.soli.nl`. It owns the OAuth clients, the role mappings and the per-user overrides, and
resolves one role per user per client.

This plugin is the WordPress end of that: it applies the provider's decision locally and
nothing more.

1. **Role sync** - the granted role becomes the local WordPress role
2. **Access control** - no granted role means the login is refused
3. **Assignments** - orchestra/instrument data stored as user meta for other Soli plugins
4. **Login page handling** - SSO bypass and recovery from a failed SSO login

> This plugin used to be an identity provider itself, backed by its own tables and admin
> pages, with an optional bridge to the (now retired) `wp-soli-admin-plugin`. All of that
> moved to the Laravel app; nothing of it remains here.

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  laravel-soli-administration  (admin.soli.nl)                    │
│                                                                  │
│  oauth_clients + OauthClientSetting                              │
│  ClientRoleMapping / OauthClientUserRole                         │
│  ClientRoleResolver  ──►  one role per (user, client)            │
│  SoliIdentityEntity  ──►  claims, gated on scope                │
└───────────────────────────────┬──────────────────────────────────┘
                                │ OIDC authorization code flow
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│  WordPress site (soli.nl, muziek.soli.nl, ...)                   │
│                                                                  │
│  ┌────────────────────────────────┐                              │
│  │ OpenID Connect Generic plugin  │  protocol, JWKS verification,│
│  │ (daggerhart)                   │  user lookup by 'sub'        │
│  └───────────────┬────────────────┘                              │
│                  │ filters / actions                             │
│                  ▼                                               │
│  ┌────────────────────────────────┐                              │
│  │ wp-soli-passport-plugin        │  role sync, access control,  │
│  │ Client\Role_Sync               │  assignments meta            │
│  └────────────────────────────────┘                              │
└──────────────────────────────────────────────────────────────────┘
```

## Plugin Structure

```
wp-soli-passport-plugin/
├── wp-soli-passport-plugin.php           # Main plugin file
├── updater.php                            # GitHub updater
├── uninstall.php                          # Removes synced user meta
├── readme.md                              # Version info for updater
├── CLAUDE.md                              # This documentation
├── phpunit.xml.dist                       # Unit test configuration
├── playwright.config.js                   # E2E test configuration
├── .wp-env.json                           # Local dev environment
├── .wp-env-setup.sh                       # Configures the client after start
├── includes/
│   ├── class-soli-passport-dependency-checker.php
│   └── client/
│       └── class-soli-passport-role-sync.php
├── tests/
│   ├── bootstrap.php                      # WP test suite bootstrap
│   ├── install-test-deps.sh               # PHPUnit toolchain in the container
│   ├── fixtures/oidc-claims.json          # Golden claims from the provider
│   ├── stub-provider/                     # Fake OIDC provider for tests
│   └── unit/RoleSyncTest.php
├── languages/
└── e2e/sso.spec.js
```

There is no database schema, no admin page and no build step. Anything that looks like it
needs one probably belongs in the Laravel app instead.

## Claim Contract

Defined by `App\OpenId\SoliIdentityEntity::getClaims()` in the Laravel app. Claims are gated
on scope, so the client's `scope` setting is part of the contract:

| Claim | Scope | Type | Meaning |
|-------|-------|------|---------|
| `sub` | always | string | Laravel user ID; the only identity key used |
| `name`, `preferred_username`, `given_name`, `family_name` | `profile` | string | Display data |
| `email`, `email_verified` | `email` | string, bool | |
| `roles` | `roles` | string[] | At most one entry. **Empty array means no access.** |
| `assignments` | `assignments` | array[] | `onderdeel_id`, `instrument_soort_id`, `instrument_soort`, `instrument_familie` |

`tests/fixtures/oidc-claims.json` mirrors this and drives both test suites. When the provider
changes what it emits, update that fixture first - the tests are written so it fails loudly.

## Role Resolution

All resolution happens on the provider (`App\Services\ClientRoleResolver`): user override →
active relatie type mapping by priority → the client's default role → no access.

WordPress only interprets the result:

| `roles` claim | Result |
|---------------|--------|
| `["editor"]` and `editor` exists in WP | Role applied |
| `[]` | Login refused, no user created |
| absent | Login refused - the `roles` scope was not requested |
| `["ledenadministratie"]` (not a WP role) | Login refused - this client is not configured on the provider, so the provider fell back to its own application roles |

Deliberately fails closed. `wp-login.php?bypass-sso` remains available so an administrator is
never locked out by a misconfigured client.

## Client Settings

The settings the OIDC client plugin needs; `.wp-env-setup.sh` writes the same set for local
development. Anything not listed can stay at its default.

| Setting | Value | Why |
|---------|-------|-----|
| `scope` | `openid email profile roles assignments` | Claims are scope-gated |
| `endpoint_login` | `https://admin.soli.nl/oauth/authorize` | |
| `endpoint_token` | `https://admin.soli.nl/oauth/token` | |
| `endpoint_userinfo` | *(empty)* | **The provider has no userinfo endpoint** - `/oauth/userinfo` returns 404. Leave it blank and the client reads the claims from the ID token instead, which is where the provider puts them. Filling it in breaks every login. |
| `endpoint_jwks` | `https://admin.soli.nl/oauth/jwks` | **Required.** Without it the client does not verify token signatures |
| `endpoint_end_session` | `https://admin.soli.nl/oauth/logout` | Accepts `redirect_uri` for `*.soli.nl` hosts |
| `link_existing_users` | off | Users are matched on `sub`; linking on email collapses two provider accounts that share an address |
| `login_type` | `auto` | Redirect straight to the provider |

### Two Provider Accounts, One Email Address

`sub` is the only identity key, but WordPress still reserves an email address for a
single user. So when two provider accounts share an address - one household, one mailbox,
which happens - the first one to sign in gets the local user and the second is refused
with `login-error=failed-user-creation`. There is an e2e test pinning that, because the
alternative is worse: with `link_existing_users` on, the second account would be handed
the first account's WordPress user, its `sub` mapping and its role. That is a takeover,
and the test fails loudly if the setting ever flips.

The refusal is a fail-closed outcome, not a fixed one. If a member ever needs it, the fix
belongs in the provider (a unique address per account) or in `email_format`, not in
linking on email.

## Hooks Used

From the OpenID Connect Generic plugin:

- `openid-connect-generic-user-login-test` - refuse the login when no role was granted
- `openid-connect-generic-user-creation-test` - do not create a user who may not sign in
- `openid-connect-generic-update-user-using-current-claim` - apply role and assignments
- `openid-connect-generic-settings` - disable the SSO redirect for `?bypass-sso` and on errors

## Member Data Exposure

Synced users are real members with real names, which changes what WordPress's defaults give
away.

`User_Privacy` removes `/wp/v2/users` and `/wp/v2/users/<id>` for **anonymous** requests.
By default WordPress serves that route publicly and lists every user who has published a
post, including display name, slug, author archive URL and a hash of their email address.
Signed-in requests are untouched, so the block editor still works. Opt out with the
`soli_passport_restrict_rest_users` filter.

The `assignments` user meta is deliberately not registered with `show_in_rest`; there is a
test asserting it stays that way, because it maps a named member to the orchestras they play
in.

Still exposed, and worth a decision per site:

- **Author archives.** `/author/<slug>/` remains public. The slug comes from the username,
  which comes from the `preferred_username` claim - a real name. Only reachable once that
  user has published content.
- **Gravatar hashes.** Anywhere WordPress renders an avatar for a logged-out visitor it emits
  a SHA-256 of the member's email address. That is core behaviour and not fixable here.

## Reading Assignments From Another Plugin

```php
use Soli\Passport\Client\Role_Sync;

$assignments = Role_Sync::get_assignments( get_current_user_id() );
// [ [ 'onderdeel_id' => 3, 'instrument_soort' => 'Trompet', ... ], ... ]
```

Returns an empty array when the user never signed in through the provider, or when the client
does not request the `assignments` scope.

## Testing

Two layers, both runnable without the real provider.

```bash
npm run wp-env:start   # WordPress client + stub provider + PHPUnit toolchain
npm run test           # unit + e2e
npm run test:unit      # PHPUnit, claim contract and role logic
npm run test:e2e       # Playwright, full browser SSO flow
```

**Unit tests** run against the WordPress test suite in the `tests-cli` container. PHPUnit 9
and the Yoast polyfills are installed into that container by `tests/install-test-deps.sh`
rather than a `composer.json`, so the plugin stays free of Composer dependencies. WordPress
still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed, hence
the version pin.

**E2E tests** run against `tests/stub-provider/`, a small OIDC provider served from the same
WordPress site at `/oidc-stub/`. Being same-origin is the point: browser-facing endpoints use
the published port and server-to-server endpoints use `localhost` inside the container, so
there is no cross-container networking and nothing depends on `host.docker.internal`. Its
accounts are the fixture entries; `#stub-user-<key>` picks one.

The stub also signs badly on request, so the tests can check that `endpoint_jwks` is doing
something. Add `stub_sign` to the authorize URL:

| `stub_sign` | id_token |
|-------------|----------|
| `valid` (default) | RS256, signed with the key the JWKS publishes |
| `wrong-key` | RS256, signed with a key the JWKS does not publish |
| `alg-none` | `alg: none`, no signature at all |

Both bad modes must end at `login-error=jwt-verification-failed` with no session and no
local user. Empty `endpoint_jwks` and they sign straight in, which is what the tests exist
to catch.

A few fixture entries are related to each other on purpose - `shared-email-*` are two
accounts with one address, `role-revoked-*` are one account whose role shrank. `RoleSyncTest`
asserts those relations, because an e2e test built on them passes while testing nothing if
someone "tidies up" a `sub`.

REST requests in the tests use `?rest_route=`; pretty `/wp-json/` routes are not rewritten
in the wp-env container. Cookie authentication on those requests also needs an `X-WP-Nonce`
header, which is why the members' side of the `User_Privacy` test reads the nonce from
wp-admin first.

Run alongside another wp-env project with `WP_ENV_PORT` / `WP_ENV_TESTS_PORT`; nothing
hardcodes a port.

## Development Guidelines

### Coding Standards

- Namespace: `Soli\Passport`
- Function prefix: `soli_passport_`
- Hook prefix: `soli_passport_`
- Text domain: `soli-passport`
- User meta prefix: `soli_passport_`
- Constants: `SOLI_PASSPORT__*`

### Security

- All authorization decisions belong to the provider; do not add role logic here
- Fail closed: an unreadable or unmapped claim must deny access, never grant a default
- `endpoint_jwks` must be configured in every environment, otherwise ID token signatures go
  unverified
- Sanitize every claim value before storing it; claims are remote input
