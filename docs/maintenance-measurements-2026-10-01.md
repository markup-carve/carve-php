# Engine maintenance measurements, 2026-10-01

Historical main baseline: `76fbd0c00ccd175037852b983482b29137b9c7ca`. Candidate: `quality/measured-maintenance-20261001`.

The PR was rebased onto main `145a251e087783cae30d79e4f5f5dc3b44ed4124`. Intervening commits changed release versions and notes; production parser and importer code did not change.

## Maintenance and verification

| Measure | Before | After |
| --- | ---: | ---: |
| Manual reset assignments in heading reparse | 20 | 0 |
| Session fields reset through the shared fresh-session path | 20 | 27 |
| Broad imported-node list annotations across four importer files | 51 | 2 |

Normal parsing and heading reparse now create a fresh session through the same reset method. The old reparse omitted seven fields, including discovered footnote bodies, abbreviation positions, and parser-local node identities.

Imported nodes require a string discriminator. Paragraph, container, code block, figure group, table row/cell, figure, MathML, and document shapes describe their known fields. The two remaining broad list annotations belong to the helper for typeless records, including ruby pairs. General variant payloads still use mixed values; this change does not claim a closed union for every imported node.

Runtime narrowing checks validate a last node directly instead of rechecking an accumulated list. Measurements caught that accidental quadratic validation during implementation; it was removed before this report.

Validation: 38,692 tests, 681,093 assertions, and 95 skips; PHPStan reports zero errors. Coding-standard checks passed for changed production files and the benchmark harness. Existing heading-reparse and HTML-import tests cover the changed paths.

## Timing measurements

Two warmups and seven timed samples per case. The table shows median milliseconds for this engine. Both revisions run the same harness and inputs on this host. [maintenance-results.json](../benchmarks/maintenance-results.json) retains medians, minima, input bytes, and SHA-256 output hashes.

| Case | Size | Before ms | After ms | After / before | Same output hash |
| --- | ---: | ---: | ---: | ---: | --- |
| verse_definitions | 128 | 39.818 | 37.844 | 0.950 | yes |
| paragraphs | 128 | 0.692 | 0.698 | 1.008 | yes |
| html_table | 128 | 160.106 | 148.046 | 0.925 | yes |
| verse_definitions | 512 | 499.769 | 497.527 | 0.996 | yes |
| paragraphs | 512 | 2.729 | 2.714 | 0.995 | yes |
| html_table | 512 | 869.801 | 932.743 | 1.072 | yes |
| verse_definitions | 1024 | 1879.019 | 1910.388 | 1.017 | yes |
| paragraphs | 1024 | 5.411 | 5.574 | 1.030 | yes |
| html_table | 1024 | 2386.480 | 2416.204 | 1.012 | yes |

Wall-clock results are local medians from a shared host. They are not CI thresholds or release performance guarantees. Compare before and after within this engine. Output hashes distinguish equivalent-output workloads from corrected behavior. Conversion runs in process. The 512-row table median increased 7.2%, while the 1,024-row median increased 1.2%; stricter validation has a cost. These runs do not establish a uniform speedup.

## Reproduce

Install locked dependencies in both worktrees and pass each absolute worktree path to the candidate harness.

```sh
php benchmarks/maintenance.php /absolute/path/to/worktree
```

## Remaining maintenance

The five cases in [verse-oracle-cases.json](../benchmarks/verse-oracle-cases.json) include source and rendered results from all engines and executable spec commit `e12ed741313c16185375e6f17b0cc6fe3e4366c2`. TypeScript and Rust match all five cases after trimming outer whitespace. PHP matches the definition-after-verse case and disagrees on four existing verse cases. The prose grammar describes fence openers inside verse as ordinary text, while the executable oracle protects colon closers inside closed opaque spans. The TypeScript and Rust changes follow the executable oracle; that grammar disagreement remains explicit.

The baseline already scales poorly on repeated verse definitions and large block-cell HTML tables. This PR preserves that scaling. The oracle comparison also exposes existing PHP disagreements on lazy-list verse and opaque spans inside verse; the fresh-session and importer changes do not alter those parser rules. Adjacent HTML definition lists still merge a growing accumulator and need a separate performance fix.
