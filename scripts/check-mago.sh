#!/usr/bin/env bash
set -euo pipefail

mago_binary="${1:-mago}"

"$mago_binary" --php-version=8.2 --colors=never lint --semantics --reporting-format=count src
"$mago_binary" --php-version=8.2 --colors=never analyze \
    --baseline mago-baseline.toml \
    --retain-code=falsable-return-statement \
    --retain-code=invalid-return-statement \
    --retain-code=undefined-variable \
    --reporting-format=short src
