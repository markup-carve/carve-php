# Unmatched bracket scans

The parser now indexes bracket pairs and failed openers once per run. Nested
pairs keep the same depth limit, and escapes, code spans and comments use the
same scanner rules. A cached last-closer check skips runs without any possible
closing bracket. Recursive inline parsing restores the enclosing scan caches.

Six alternating fresh-process pairs against main `c4227e19f` measured median
candidate/baseline CPU-time ratios of 0.1125 for 1,024 unmatched openers and
0.1050 when a trailing closer was present, about 89% and 90% less CPU time.
Every pair retained identical AST and HTML fingerprints. The separate medium
mixed-corpus control measured 1.0206, roughly flat. These shared-host results
support the malformed-input improvement, not a general throughput claim.
PHP 8.5.11 ran without opcache or JIT.

[Raw batches and source hashes](brackets-pairs.json) retain each process separately.
The scaling regressions exercise `parse()` directly so a conversion facade
cannot hide the parser's cost. Both fail on the baseline and pass on this code.

```sh
php -n -d extension=ctype -d extension=mbstring scripts/bench-phases.php CHECKOUT partial-brackets parse
```

The worker also accepts `brackets`, `lists`, `quotes`, `paragraphs`, or a fixture
path, and measures `parse`, `render`, or `html` in separate processes.
