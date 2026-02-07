# OIDC Test Keys

These RSA keys are used for local OIDC development and testing only.

**WARNING: These keys are committed to the repository for local development convenience. Do NOT use these keys in production.**

## Usage

- `private.key` - Used by the OIDC provider (localhost:8889) to sign tokens
- `public.key` - Used to verify token signatures

## Regenerating Keys

If you need to regenerate the keys:

```bash
openssl genrsa -out private.key 2048
openssl rsa -in private.key -pubout -out public.key
```
