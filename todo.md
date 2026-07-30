# Passport Plugin TODO

## Done

- [x] Fix no-access role not blocking access (was assigning subscriber) — an empty `roles`
      claim now refuses the login and creates no user
- [x] Handle users with duplicate email addresses across sites — users are matched on `sub`
      only, with `link_existing_users` off

## Still on the WordPress side

- [ ] Update login page styling to match wp-theme-soli
- [ ] Redirect client profile pages to admin.soli.nl

## Moved to laravel-soli-administration (provider concerns)

- [ ] Sign out impacted user sessions when a role mapping or user mapping changes
- [ ] Verify if deleting user sessions on the provider actually logs out users on connected clients
- [ ] Translate the OIDC authorization flow (permission screen is always English)
