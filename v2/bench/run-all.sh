#!/bin/sh
# Engine comparison, JIT off and on, plus peak memory per engine and document.
# php -n drops pcov, which hooks zend_execute_ex, doubles every number and blocks the JIT.
# Usage: sh v2/bench/run-all.sh [ROUNDS] > results.md
set -e
cd "$(dirname "$0")/../.."
ROUNDS=${1:-15}
EXT="-n -d extension=ctype -d extension=mbstring -d extension=intl -d extension=dom -d extension=tokenizer -d memory_limit=4G"
JIT="-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M"
php -v | head -1
echo
echo "## JIT off"
php $EXT v2/bench/bench-engines.php --rounds "$ROUNDS"
echo
echo "## JIT on"
php $EXT $JIT v2/bench/bench-engines.php --rounds "$ROUNDS"
echo
echo "## Peak memory above baseline (MB), one process per run, JIT off"
for doc in bench-commonmark-core 1mb; do
  for engine in carve2 carve01 commonmark djot; do
    for phase in parse html; do
      printf '%s %s %s ' "$doc" "$engine" "$phase"
      php $EXT v2/bench/bench-engines.php --memory "$engine" "$doc" "$phase"
    done
  done
done
