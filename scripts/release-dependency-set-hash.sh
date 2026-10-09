#!/usr/bin/env bash

set -euo pipefail

manifest="${1:?release manifest path is required}"

jq -cS '
  {
    require: (.require // {}),
    "require-dev": (.["require-dev"] // {}),
    conflict: (.conflict // {}),
    provide: (.provide // {}),
    replace: (.replace // {}),
    suggest: (.suggest // {})
  }
' "$manifest" | tr -d '\n' | sha256sum | cut -d' ' -f1
