#!/usr/bin/env bash

set -euo pipefail

source_manifest="${1:?source composer.json is required}"
release_version="${2:?release version is required}"
output_manifest="${3:?output manifest path is required}"

jq -e --arg version "$release_version" '
  if type != "object" or (.name | type) != "string" then
    error("composer manifest must be an object with a string name")
  else
    .version = $version
    | {
      name,
      version,
      type,
      license,
      require,
      autoload,
      extra,
      "require-dev": .["require-dev"],
      "autoload-dev": .["autoload-dev"],
      conflict,
      provide,
      replace,
      suggest
    }
  end
' "$source_manifest" > "$output_manifest"
