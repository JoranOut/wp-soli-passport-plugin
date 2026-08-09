#!/bin/bash
#
# Generate the stub provider's RSA signing keypair.
#
# The keys are local test material and are deliberately NOT committed: the stub
# regenerates them on demand, and nothing outside the test environment trusts them.

set -e

KEYS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/keys"

mkdir -p "${KEYS_DIR}"

generated=0

# The signing pair the JWKS publishes. Only ever regenerated as a pair, and never
# while it is complete: the client caches the JWKS for jwks_cache_ttl seconds, so
# replacing a working key would break every login until that cache expires.
if [ ! -f "${KEYS_DIR}/private.key" ] || [ ! -f "${KEYS_DIR}/public.key" ]; then
	openssl genrsa -out "${KEYS_DIR}/private.key" 2048 2>/dev/null
	openssl rsa -in "${KEYS_DIR}/private.key" -pubout -out "${KEYS_DIR}/public.key" 2>/dev/null
	chmod 644 "${KEYS_DIR}/private.key" "${KEYS_DIR}/public.key"
	generated=1
fi

# A second key that is never published in the JWKS. Signing with it produces a
# well-formed token with a signature the client cannot verify, which is what
# ?stub_sign=wrong-key hands out.
if [ ! -f "${KEYS_DIR}/wrong-private.key" ]; then
	openssl genrsa -out "${KEYS_DIR}/wrong-private.key" 2048 2>/dev/null
	chmod 644 "${KEYS_DIR}/wrong-private.key"
	generated=1
fi

if [ "${generated}" -eq 1 ]; then
	echo "Generated stub provider keys in ${KEYS_DIR}"
else
	echo "Stub provider keys already present."
fi
