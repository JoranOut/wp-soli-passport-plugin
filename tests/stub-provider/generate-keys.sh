#!/bin/bash
#
# Generate the stub provider's RSA signing keypair.
#
# The keys are local test material and are deliberately NOT committed: the stub
# regenerates them on demand, and nothing outside the test environment trusts them.

set -e

KEYS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/keys"

if [ -f "${KEYS_DIR}/private.key" ] && [ -f "${KEYS_DIR}/public.key" ]; then
	echo "Stub provider keys already present."
	exit 0
fi

mkdir -p "${KEYS_DIR}"

openssl genrsa -out "${KEYS_DIR}/private.key" 2048 2>/dev/null
openssl rsa -in "${KEYS_DIR}/private.key" -pubout -out "${KEYS_DIR}/public.key" 2>/dev/null
chmod 644 "${KEYS_DIR}/private.key" "${KEYS_DIR}/public.key"

echo "Generated stub provider keys in ${KEYS_DIR}"
