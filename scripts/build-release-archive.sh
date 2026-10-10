#!/usr/bin/env bash

set -euo pipefail

source_ref="${1:?source ref is required}"
release_version="${2:?release version is required}"
archive_path="${3:?archive path is required}"
manifest_path="${4:?manifest path is required}"

source_sha="$(git rev-parse --verify "$source_ref^{commit}")"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

git archive --format=tar "$source_sha" | tar -x -C "$workdir"
jq --arg version "$release_version" '.version = $version' "$workdir/composer.json" > "$workdir/composer.json.tmp"
mv "$workdir/composer.json.tmp" "$workdir/composer.json"
cp composer.lock "$workdir/composer.lock"

if find "$workdir" -type f \( -name 'auth.json' -o -name '.env' -o -name '.env.*' \) -print -quit | grep -q .; then
    printf 'release archive contains credentials or environment data\n' >&2
    exit 1
fi

scripts/build-release-manifest.sh "$workdir/composer.json" "$release_version" "$manifest_path"
mkdir -p "$(dirname "$archive_path")"
(cd "$workdir" && zip -qr "$archive_path" .)

test "$(jq -r '.name' "$manifest_path")" = 'seviaflow/api'
test "$(jq -r '.version' "$manifest_path")" = "$release_version"
