#!/usr/bin/env bash
set -euo pipefail

mago_binary="${1:-mago}"
if [[ "$mago_binary" == */* ]]; then
    mago_binary="$(realpath "$mago_binary")"
fi
cd "$(dirname "$0")/.."

version="$("$mago_binary" --version)"
if [[ "$version" != 'mago 1.58.2' ]]; then
    printf 'Expected Mago 1.58.2, got %s\n' "$version" >&2
    exit 2
fi

"$mago_binary" --colors=never lint --semantics --reporting-format=count
"$mago_binary" --colors=never analyze \
    --baseline mago-baseline.toml \
    --retain-code=falsable-return-statement \
    --retain-code=invalid-return-statement \
    --retain-code=undefined-variable \
    --fail-on-out-of-sync-baseline \
    --reporting-format=short
