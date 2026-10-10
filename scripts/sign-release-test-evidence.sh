#!/usr/bin/env bash

set -euo pipefail

payload_path="${1:?unsigned evidence path is required}"
output_path="${2:?signed evidence path is required}"
: "${SEVIAFLOW_RELEASE_ATTESTATION_KEY:?SEVIAFLOW_RELEASE_ATTESTATION_KEY is required}"
: "${SEVIAFLOW_RELEASE_ATTESTATION_KEY_ID:?SEVIAFLOW_RELEASE_ATTESTATION_KEY_ID is required}"

jq -e 'type == "object" and (.completed_at | type) == "string" and (.completed_at | length) > 0' "$payload_path" >/dev/null
canonical="$(jq -cS 'del(.attestation)' "$payload_path")"
payload_hash="$(printf '%s' "$canonical" | sha256sum | cut -d' ' -f1)"
signature="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$SEVIAFLOW_RELEASE_ATTESTATION_KEY" -binary | od -An -tx1 | tr -d ' \n')"

jq -cS \
    --arg key_id "$SEVIAFLOW_RELEASE_ATTESTATION_KEY_ID" \
    --arg payload_hash "$payload_hash" \
    --arg signature "$signature" \
    '. + {attestation: {type: "hmac-sha256", key_id: $key_id, payload_hash: $payload_hash, signature: $signature}}' \
    "$payload_path" > "$output_path"
