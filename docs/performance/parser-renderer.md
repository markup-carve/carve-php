# Parser and renderer measurements

The bracket index avoids repeated scans of failed openers. Heading indexing defers paragraph inlines until needed, and native marker checks reject impossible starts before regex matching. The renderer pads clean HTML regions in bulk, preserving preformatted text and multiline attributes. Nested rendering still rebuilds subtree strings at each level.

These measurements compare baseline `c4227e19f3fd095df5e5fde4b572a11cb32a2b70` with candidate `de7f0632cc44f6f1ab8b9dfd438b851f28e19767`, using PHP 8.5.11. Ratios below are candidate CPU time divided by baseline CPU time; lower is faster. Each result is the median of three alternating fresh-process pairs, with five timed batches per process. Startup and output validation are excluded. Parsing, rendering and complete conversion are independent experiments; their timings must not be added.

| Fixture | Phase | Without JIT | Tracing JIT |
| --- | --- | ---: | ---: |
| brackets | parse | 0.098 | 0.122 |
| partial-brackets | parse | 0.097 | 0.135 |
| lists | parse | 1.042 | 0.949 |
| lists | render | 0.623 | 0.958 |
| quotes | html | 0.616 | 0.781 |
| paragraphs | html | 0.890 | 1.014 |
| carve.crv | html | 0.899 | 0.941 |
| medium.crv | parse | 0.848 | 0.925 |
| medium.crv | html | 0.873 | 0.914 |
| large.crv | html | 0.824 | 0.905 |

Without JIT, deep-list parsing measured 4.2% slower across paired ratios of
1.031-1.050, while its rendering used 37.7% less CPU. With tracing JIT,
deep-list parsing had a 5.1% lower median, with paired ratios of 0.885-1.001
that include no improvement; rendering used 4.2% less CPU. These results
retain that tradeoff rather than claiming every phase improved.

These are shared-host measurements, not a cross-engine ranking. Small differences and wide paired ranges should be treated as noise. The proofs runner disables JIT, while the bench runner enables tracing JIT; each worker records the actual runtime state.

All paired runs retained identical input, AST and HTML hashes. The separate [ownership check](ownership-parity.json) retained exact AST and HTML fingerprints across all 472 proofs inputs. That check does not extend the formal model's claims.

The corpus files come from [carve-bench at `eb84fcfa`](https://github.com/markup-carve/carve-bench/tree/eb84fcfa6ed2a7619a92b5347e81e8706b4de59b/corpus).

[Summary, provenance and paired ranges](paired-results.json) and [raw worker batches](paired-results.jsonl) retain the measurements. The [isolated bracket report](brackets.md) measures only that change.

```sh
php -n -d extension=ctype -d extension=mbstring scripts/bench-phases.php CHECKOUT lists render
php -n -d extension=ctype -d extension=mbstring -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=tracing scripts/bench-phases.php CHECKOUT lists render
```
