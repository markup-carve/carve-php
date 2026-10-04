# Deep-list chain rendering

A singleton list chain repeatedly indented the complete child HTML at each level. The renderer now accumulates indentation for eligible wrappers and indents the body once. Parsing and ownership are unchanged.

The fast path excludes task wrappers, renderer subclasses, list callbacks and static extensions. Preformatted bodies retain recursive wrapping. Mixed markers, list tightness and multiline attributes preserve the recursive output. Depth accounting includes skipped wrappers and restores state after a body exception.

## Paired observations

The [raw observations](list-chain-costs.json) contain 72 rows from two reversed fresh-process rounds. PHP opcache and JIT were disabled. Each fixture/phase warmed for 200 ms and recorded five batches of at least 20 ms. Source fingerprints, worker and runner hashes, CPU samples, wall samples, managed-heap peaks and HTML fingerprints are retained.

Each cell below shows CPU medians in round 0 / round 1. Host noise affects the absolute values. Parse observations also vary although parser source is unchanged; these results do not establish a parser speed improvement.

| List depth | Phase | Baseline CPU ms | Candidate CPU ms |
| ---: | --- | ---: | ---: |
| 48 | parse | 3.101 / 3.113 | 3.152 / 3.099 |
| 48 | render | 0.614 / 0.607 | 0.241 / 0.236 |
| 48 | html | 3.847 / 3.905 | 3.395 / 3.402 |
| 96 | parse | 10.147 / 10.652 | 10.334 / 10.454 |
| 96 | render | 4.511 / 4.173 | 0.483 / 0.474 |
| 96 | html | 15.273 / 14.840 | 11.188 / 11.276 |
| 192 | parse | 37.295 / 38.743 | 38.486 / 38.941 |
| 192 | render | 29.884 / 27.345 | 0.945 / 0.936 |
| 192 | html | 70.540 / 66.763 | 39.636 / 38.893 |

At depth 192, render CPU falls from 29.884 / 27.345 ms to 0.945 / 0.936 ms. All six fixture HTML fingerprints match across both versions, phases and rounds. The large render gain persists in both orders; combined HTML remains dominated by parsing. These fixtures do not establish a general complexity bound or a speedup for ordinary documents.

The baseline is `fba5f377a72771b164b638ef9997b7caf4850ede`. The candidate is the working source identified by `sourceSha256` and `trackedDiffSha256` in the JSON. Benchmark files and tests are excluded from that source fingerprint.

## Reproduce

The PHP worker is included at `scripts/bench-container-worker.php`, copied from `markup-carve/carve-proofs`, `scripts/runtime/container-worker.php` at commit `12f3c152ac8ae2b866c69de13828bb3d1c9b3551`. Its exact SHA-256 is recorded in the JSON. Use clean baseline source and this candidate:

```sh
node scripts/bench-list-chain.mjs \
  /path/to/baseline /path/to/candidate \
  reports/list-chain-costs.json
vendor/bin/phpunit tests/TestCase/Renderer/ListChainTest.php
```

The runner checks requested parse depth, output parity, runtime settings, observation completeness and source stability. HTML hashes are preflight fingerprints alongside phase rows, not hashes of every timed iteration. The worker checks parse/render against combined HTML before and after timing. Corpus conformance, renderer callbacks, safe/XHTML modes and depth recovery are covered separately by the test suite.
