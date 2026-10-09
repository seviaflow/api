#!/usr/bin/env bash

set -euo pipefail

artifact_hash="${1:?artifact hash is required}"
dependency_set_hash="${2:?dependency set hash is required}"
release_version="${3:?release version is required}"
output_path="${4:?output path is required}"
: "${GITHUB_SHA:?GITHUB_SHA is required}"
: "${GITHUB_REF:?GITHUB_REF is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
: "${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}"
: "${GITHUB_SERVER_URL:?GITHUB_SERVER_URL is required}"
: "${SEVIAFLOW_RELEASE_ATTESTATION_KEY:?SEVIAFLOW_RELEASE_ATTESTATION_KEY is required}"

lock_hash="$(sha256sum composer.lock | cut -d' ' -f1)"
completed_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
php_version="$(php -r 'echo PHP_VERSION;')"
composer_version="$(composer --version --no-ansi | awk 'NR == 1 {print $3}')"
payload="$(jq -cnS \
  --arg repository "$GITHUB_REPOSITORY" \
  --arg workflow_ref "$GITHUB_REPOSITORY/.github/workflows/release.yml@$GITHUB_SHA" \
  --arg run_id "$GITHUB_RUN_ID" \
  --arg run_attempt "$GITHUB_RUN_ATTEMPT" \
  --arg run_url "$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID" \
  --arg source "$GITHUB_SHA" \
  --arg source_ref "$GITHUB_REF" \
  --arg artifact "$artifact_hash" \
  --arg dependencies "$dependency_set_hash" \
  --arg lock "$lock_hash" \
  --arg php "$php_version" \
  --arg composer "$composer_version" \
  --arg completed "$completed_at" \
  --arg version "$release_version" \
  '{
    schema_version: 1,
    provider: "github-actions",
    pipeline: "seviaflow-release-v2",
    repository: $repository,
    workflow_path: ".github/workflows/release.yml",
    workflow_ref: $workflow_ref,
    run_id: $run_id,
    run_attempt: ($run_attempt | tonumber),
    run_url: $run_url,
    source_revision: $source,
    source_ref: $source_ref,
    artifact_hash: $artifact,
    dependency_set_hash: $dependencies,
    composer_lock_hash: $lock,
    profile: {os: "ubuntu-latest", php: $php, composer: $composer},
    completed_at: $completed,
    test_suites: [{name: "composer-check", conclusion: "success", source_revision: $source, artifact_hash: $artifact, dependency_set_hash: $dependencies, composer_lock_hash: $lock, completed_at: $completed}],
    release_version: $version
  }')"
payload_hash="$(printf '%s' "$payload" | sha256sum | cut -d' ' -f1)"
signature="$(printf '%s' "$payload" | openssl dgst -sha256 -hmac "$SEVIAFLOW_RELEASE_ATTESTATION_KEY" -binary | od -An -tx1 | tr -d ' \n')"

jq -cn \
  --argjson payload "$payload" \
  --arg key_id "${SEVIAFLOW_RELEASE_ATTESTATION_KEY_ID:-release-attestation-v1}" \
  --arg payload_hash "$payload_hash" \
  --arg signature "$signature" \
  '$payload + {attestation: {type: "hmac-sha256", key_id: $key_id, payload_hash: $payload_hash, signature: $signature}}' > "$output_path"
