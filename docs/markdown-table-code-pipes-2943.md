# Markdown table pipes

A pipe inside a GFM table cell must be escaped, including inside code spans. An unescaped pipe can invalidate the header width or split a body cell. The importer keeps those GFM rules. Issue #2943 was closed on that basis; this change addresses the missing diagnostics and related conversion defects.

The fidelity report now includes warnings with the original source line:

- `markdown-table-code-pipe` suggests escaping the pipe as `\|`.
- `markdown-table-header-mismatch` explains why the source remains a paragraph.
- `markdown-table-extra-cells` identifies nonempty cells omitted under GFM rules.
- `markdown-table-image-alt-pipe` reports a remaining limitation: Carve retains the required table escape in the rendered image alt text.

Reports reset between conversions and retain line numbers after frontmatter and moved reference definitions. The generic `fidelity-unverified` warning remains.

Escaped trailing pipes, table pipe unescaping, reference labels containing pipes and pipe-bearing HTTP(S) autolinks now preserve their GFM meaning. Ordinary quoted tables and quoted tables inside outer list items are converted. Lazy rows belonging to quoted list paragraphs stay prose. This does not cover every quoted-list table arrangement.

Literal spans with separating empty comments protect escaped backticks and backslashes across PHP, JavaScript and Rust readers. The native Carve table parser is unchanged. The body example in #2943 now renders as two separate cells under GFM rules.

## Measurements

The code-span pass indexes matching backtick runs once, including partial opening runs after an escaped first backtick. Unmatched openers no longer rescan every suffix.

Measured against `f009b19063db43f9554ed0be488184eefa33db67` on PHP 8.5.11 with PCOV disabled, pinned to CPU 13. Two rounds reversed revision order; each process used one warmup and three timed calls per size, giving six samples per revision. Hashing was outside the timer. The host was busy, so ordinary-input differences should be read with the sample ranges.

| Probe | Input bytes | Before median ms | After median ms |
| --- | ---: | ---: | ---: |
| Code-span pass | 2,208 | 5.613 | 0.098 |
| Code-span pass | 8,512 | 44.988 | 0.305 |
| Code-span pass | 33,408 | 345.370 | 0.850 |
| Public API, prose | 6,656 | 15.416 | 15.113 |
| Public API, prose | 26,624 | 58.210 | 58.575 |
| Public API, plain table | 2,580 | 8.472 | 8.647 |
| Public API, plain table | 10,260 | 33.632 | 34.515 |

All output hashes match within each probe and size. The largest isolated case improved about 406 times. These pass timings do not predict core benchmark chart results. Public API controls show similar timings with overlapping sample ranges.

[Raw samples, ranges, source hashes and executable probes](measurements/markdown-table-code-pipes-2943.json) are retained. A deterministic regression test bounds backtick scanning by input size; unchanged main fails that bound.

## Validation

The regression set contains 179 tests, including 158 independent cmark-gfm fixtures. All 158 generated Carve outputs match the fixtures through PHP, JavaScript and Rust readers after normalizing generated heading IDs, table scope attributes, inter-tag whitespace and equivalent quote escaping. PHPStan and coding standards pass. Claude review findings were addressed and the affected cases rechecked.

Escaped pipes inside raw HTML have separate assertions against cmark-gfm's table unescaping behavior. The oracle's CommonMark HTML reconstruction retains the original backslash and cannot assess those cases correctly.

References: [GFM tables](https://github.github.com/gfm/#tables-extension-), [cmark-gfm table reader](https://github.com/github/cmark-gfm/blob/master/extensions/table.c).
