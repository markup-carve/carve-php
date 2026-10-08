# HTML import fanout measurements

Footnote lookup caches shared target counts, backlink lists, inverse candidates and marker classes. Membership sets replace repeated array searches. Backlink parent checks, separator removal and empty-wrapper pruning run in batches. Cached reflection checks let subclasses that inherit the relevant methods use the same paths; overridden hooks retain their previous behavior. Per-document indexes are released after conversion.

## Method

Measured on 2026-10-08 against `a082ae633fe962edb3b39baf7d44e5072c3941f8`. The candidate source hashes and Rust binary hashes are recorded in the [raw results](measurements/html-import-fanout-20261008.json).

Each public API with the Word adapter was measured serially on CPU 13 (AMD Ryzen 9 PRO 7940HS w/ Radeon 780M Graphics). Each size received one warmup and three timed calls. A second round reversed revision and size order, giving six samples per revision and size. The tables use all six samples. JavaScript and PHP collected garbage before each timed call. Report serialization, hashing and file I/O were outside the timer.

Runtimes: v22.22.2; PHP 8.5.11 (cli) (built: Sep 24 2026 13:49:29) (NTS); rustc 1.97.1 (8bab26f4f 2026-07-14). PHP used its default CLI JIT settings with PCOV and Xdebug disabled. These timings use a different PHP configuration from the benchmark site's tracing-JIT core runs.

PHP candidate measurements were refreshed after tightening the subclass hook guard: every size received the same six samples in both size orders, with the original before samples retained. The raw results record that phase separately. PHP ordinary-input and distinct-footnote controls were then repeated with both revisions together because the refresh ran at higher host load; the original control samples are also retained.

## Paired results

The table shows the larger size of each pair. Per-byte growth compares that size with the smaller size: linear work stays near 1x; quadratic work approaches the input-size multiplier. All 22 before/after output and report hashes match.

| Input | Count | API | Before ms | After ms | Speedup | After per-byte growth |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| Long inverse backlink classes | 4,096 | carve | 1597.623 | 648.738 | 2.46x | 1.17x |
| Long inverse reference classes | 4,096 | carve | 2692.836 | 674.497 | 3.99x | 0.88x |
| Shared-body backlinks | 4,096 | carve | 3373.497 | 653.363 | 5.16x | 0.90x |
| Wrapped backlinks | 4,096 | carve | 4514.537 | 890.694 | 5.07x | 1.47x |
| Shared definition wrapper | 8,192 | ast | 12282.505 | 2719.506 | 4.52x | 1.16x |
| Empty note wrappers | 4,096 | ast | 3152.433 | 1452.363 | 2.17x | 0.89x |
| Duplicate reference IDs | 4,096 | carve | 2958.265 | 1010.642 | 2.93x | 1.41x |
| Aliases for one definition | 4,096 | carve | 623.747 | 933.233 | 0.67x | 1.19x |
| Separator siblings | 2,048 | ast | 502.025 | 16.833 | 29.82x | 0.85x |
| Distinct mutual footnotes | 1,024 | carve | 760.550 | 635.359 | 1.20x | 1.01x |
| Ordinary paragraphs | 1,024 | carve | 518.506 | 578.625 | 0.90x | 1.19x |

## Ordinary input

- 256 paragraphs: before 105.543 ms (99.769–118.941); after 121.823 ms (106.648–144.863).
- 1024 paragraphs: before 518.506 ms (475.011–550.461); after 578.625 ms (476.013–640.548).

These short samples include JIT warmup effects. The raw ranges should accompany any claim about ordinary-input overhead.

## Validation

The full suite completed 39,480 tests successfully with PCOV disabled; 95 were skipped. The final subclass-hook repair passed 501 targeted tests (60 skipped). Coding standards and PHPStan passed. All nine timing guards passed, including the inherited-method subclass case. Coverage-enabled unchanged main also exits 139 on DeepNestingTest on this host. All 173 semantic cases match. Claude reviewed the diff; findings were addressed and checked.

## Reproduce

The [fixture generator](measurements/html-import-fanout-fixtures.py) accepts a shape and count. Copy the probe into each checkout when comparing a historical revision. Use distinct Cargo target directories for Rust revisions.

```sh
mkdir -p /tmp/carve-footnote-fixtures
for n in 1024 4096; do
  python3 docs/measurements/html-import-fanout-fixtures.py backs "$n" > "/tmp/carve-footnote-fixtures/backs-$n.html"
done
php -d pcov.enabled=0 -d xdebug.mode=off docs/measurements/html-import-fanout-probe.php "$PWD" backs 1024,4096 carve
```

Pin the process to the same CPU, repeat with reversed revision and size order, and compare output/report hashes. The semantic inputs are in [the fixture file](measurements/html-import-fanout-semantic-fixtures.json).

## Remaining costs

Attribute-heavy HTML still encounters quadratic duplicate attribute checks in upstream parsers. Deep div nesting also makes parse5 repeat scope scans. The deep-scope fixture uses object boundaries to isolate engine-owned ancestor lookup; it does not establish linear parsing of arbitrary deep HTML. These changes do not establish a core parse/render chart speedup.

The relevant upstream code is in [parse5](https://github.com/inikulin/parse5/blob/e65eae9a9dc27f1b7eb71868d245ca83070ccc4a/packages/parse5/lib/tokenizer/index.ts), [html5ever](https://github.com/servo/html5ever/blob/7760920edff08e6dfd4b62affee39d8084e9dc45/html5ever/src/tokenizer/mod.rs), and [Lexbor](https://github.com/lexbor/lexbor/blob/master/source/lexbor/html/tree.c).

The repeated ordinary-paragraph control has 12% to 15% slower medians, with overlapping sample ranges. These measurements do not rule out ordinary-input overhead; the gains above apply to the named fanout cases.
