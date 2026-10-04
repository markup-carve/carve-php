# Deep-list chain rendering

A singleton list chain repeatedly indented the complete child HTML at each level. The renderer now accumulates indentation for eligible wrappers and indents the body once. Parsing and ownership are unchanged.

The fast path excludes task wrappers, renderer subclasses, list callbacks and static extensions. Preformatted bodies use the shared HTML layout writer with separate list and item indentation scopes. Mixed markers, list tightness and multiline attributes preserve the recursive output. Depth accounting includes skipped wrappers and restores state after a body exception.

## Paired observations

The [raw observations](list-chain-costs.json) contain 72 rows from two reversed fresh-process rounds. PHP opcache and JIT were disabled. Each fixture/phase warmed for 200 ms and recorded five batches of at least 20 ms. Source fingerprints, worker and runner hashes, CPU samples, wall samples, managed-heap peaks and HTML fingerprints are retained.

Each cell below shows CPU medians in round 0 / round 1. Host noise affects the absolute values. Parse observations also vary although parser source is unchanged; these results do not establish a parser speed improvement.

| List depth | Phase | Baseline CPU ms | Candidate CPU ms |
| ---: | --- | ---: | ---: |
| 48 | parse | 3.000 / 3.124 | 2.937 / 2.987 |
| 48 | render | 0.443 / 0.423 | 0.236 / 0.238 |
| 48 | html | 3.460 / 3.491 | 3.312 / 3.334 |
| 96 | parse | 10.682 / 10.377 | 10.195 / 10.208 |
| 96 | render | 0.900 / 0.849 | 0.461 / 0.512 |
| 96 | html | 11.537 / 10.771 | 10.549 / 10.834 |
| 192 | parse | 35.970 / 37.671 | 36.240 / 36.210 |
| 192 | render | 1.823 / 1.735 | 0.937 / 0.970 |
| 192 | html | 38.329 / 37.952 | 36.850 / 37.490 |

At depth 192, render CPU falls from 1.823 / 1.735 ms to 0.937 / 0.970 ms against main after #2849. All six fixture HTML fingerprints match across both versions, phases and rounds. Combined HTML remains dominated by parsing. Deep-list parsing still grows faster than linearly in these fixtures. These measurements do not establish a general complexity bound or an ordinary-document speedup.

The baseline is `2a90c1603009943d77869df361c2bfc0d12be860`. The candidate working source is identified by `sourceSha256` and `trackedDiffSha256` in the JSON. Benchmark files and tests are excluded from that source fingerprint.

## Reproduce

The PHP worker is included at `scripts/bench-container-worker.php`, copied from `markup-carve/carve-proofs`, `scripts/runtime/container-worker.php` at commit `12f3c152ac8ae2b866c69de13828bb3d1c9b3551`. Its exact SHA-256 is recorded in the JSON. Use clean baseline source and this candidate:

```sh
node scripts/bench-list-chain.mjs \
  /path/to/baseline /path/to/candidate \
  reports/list-chain-costs.json
vendor/bin/phpunit tests/TestCase/Renderer/ListChainTest.php
```

The runner checks requested parse depth, output parity, runtime settings, observation completeness and source stability. HTML hashes are preflight fingerprints alongside phase rows, not hashes of every timed iteration. The worker checks parse/render against combined HTML before and after timing. Corpus conformance, renderer callbacks, safe/XHTML modes and depth recovery are covered separately by the test suite.
