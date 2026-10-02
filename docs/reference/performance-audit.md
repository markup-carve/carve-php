# Parser performance audit

Compared with `2037b6e2cb3a` on PHP 8.5.11, using a clean CLI configuration with ctype and mbstring. Each result is the median of 20 CPU-time samples per variant from four alternating fresh processes pinned to CPU 13. Ratios below show CPU throughput relative to the baseline.

| Workload | JIT off | Tracing JIT |
| --- | ---: | ---: |
| 10,000 distinct prose marker probes | 1.35× | 1.25× |
| Streaming plain text | 1.16× | 1.09× |
| Streaming escaped UTF-8 text | 1.11× | 1.10× |
| Unicode headings and paragraphs | 3.27× | 3.22× |
| Simple block images | 9.84× | 10.89× |
| Tight star list | 20.81× | 27.79× |
| Default core control | 0.99× | 0.99× |

The shared host was busy. Wall times and memory peaks are retained in the [raw results](../../tests/benchmark/parser-audit-results.json), along with input, output, and source hashes. The control results do not support a general core speedup claim. Long-cell numbers measure the table helpers, and streaming numbers measure the output buffer.

Ordinary prose is rejected before the marker cache. Subclasses still bypass the cache, and bullet-setting changes keep their existing invalidation behavior. Streaming escapes process 4,096 input bytes per call; emitted chunks remain valid UTF-8 and at most 4,096 bytes.

The default layout also accepts plain Unicode letters, marks, and numbers, simple standalone block images, and flat star lists. Unicode formatting, special spaces, emoji, image titles/attributes, and nested star lists retain AST fallback. The ASCII heading-ID extension falls back for non-ASCII source so its configured transliteration stays authoritative.

The pinned corpus gains seven accepted documents, taking the layout count from 52 to 59. Every accepted source matches the AST renderer with default and supported extension configurations. Images have their own acceptance counter and preserve Carve's block output without a paragraph wrapper.

Validation: 38,919 tests and 682,389 assertions pass, with 95 skips. Streaming tests check exact joined output, chunk limits, UTF-8 boundaries, and rejection before sink writes. PHPStan, PHPCS, and Claude review pass.

Run from the candidate checkout after installing its development dependencies:

```sh
git worktree add --detach /tmp/carve-audit-base 2037b6e2cb3a
python3 tests/benchmark/compare-parser-audit.py --engine carve \
  --baseline /tmp/carve-audit-base --cpu 13 --output /tmp/carve-audit.json
```

Choose an available CPU with `--cpu`. The baseline only supplies `src`; its vendor directory is unused. The driver checks source stability and exact output hashes across variants.
