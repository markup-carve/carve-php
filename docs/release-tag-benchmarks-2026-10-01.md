# Release tag benchmark comparison, 2026-10-01

The candidate `dev-main` is [PR #2812](https://github.com/markup-carve/carve-php/pull/2812) based on main `145a251e087783cae30d79e4f5f5dc3b44ed4124`, including the completed engine maintenance fixes. The PR remains a separate branch; these results do not imply a merge into main. The latest two remote Git tags at measurement time were `0.1.10` and `0.1.9`.

Measured revisions:

- `dev-main`: `da3554430c46130034c256ca447399e9ee329a8b`
- `0.1.10`: `6d94607eaa9d51c9ed782342161beca77b05aaf9`
- `0.1.9`: `d4b53388df891158c9c868177a4213a5e8b2f608`

## Method

Each revision ran in a separate process. Three rounds rotated the order of dev-main and both tags. Each case used seven timed samples per round, for 21 samples per revision and size. Two warmups preceded each case in each round. Medians below pool all 21 samples. The host is x86_64 with PHP 8.5.11, coverage instrumentation disabled (`pcov.enabled=0`), and CLI opcache disabled.

The workload generators and sizes are identical across revisions of this engine. `mixed_document` includes unique headings, emphasis, a reference link, inline code, lists, and pipe tables. Other cases exercise repeated quoted verse fences, literal definition-shaped verse lines, plain paragraphs, or block-cell HTML import. Timing includes in-process conversion.

The source fixtures are generated outside the timed region. Percentages use `100 * (dev / tag - 1)`; negative values mean faster. Small changes should be read against the retained sample variation on this shared host. Every fixture's byte length and output hash remained stable across all three rounds within each revision. All output hashes match both tags.

## Largest cases

- `verse_definitions`, 1024: 694.43 ms; -8.2% versus `0.1.10`, +3103.7% versus `0.1.9`.
- `paragraphs`, 1024: 2.47 ms; -1.9% versus `0.1.10`, +15.3% versus `0.1.9`.
- `html_table`, 1024: 1289.84 ms; +1.3% versus `0.1.10`, +113.9% versus `0.1.9`.
- `mixed_document`, 1024: 481.44 ms; -4.5% versus `0.1.10`, -34.6% versus `0.1.9`.

## All measured differences

| Case | Size | Dev ms | 0.1.10 ms | Change | 0.1.9 ms | Change | Output hashes match both tags |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| verse_definitions | 128 | 15.473 | 16.022 | -3.4% | 2.672 | +479.0% | yes |
| paragraphs | 128 | 0.321 | 0.325 | -1.1% | 0.274 | +17.4% | yes |
| html_table | 128 | 72.733 | 81.476 | -10.7% | 61.914 | +17.5% | yes |
| mixed_document | 128 | 3.673 | 3.799 | -3.3% | 2.996 | +22.6% | yes |
| verse_definitions | 512 | 188.915 | 191.930 | -1.6% | 10.611 | +1680.4% | yes |
| paragraphs | 512 | 1.236 | 1.267 | -2.4% | 1.086 | +13.8% | yes |
| html_table | 512 | 430.769 | 491.013 | -12.3% | 264.793 | +62.7% | yes |
| mixed_document | 512 | 204.106 | 211.231 | -3.4% | 302.776 | -32.6% | yes |
| verse_definitions | 1024 | 694.427 | 756.216 | -8.2% | 21.676 | +3103.7% | yes |
| paragraphs | 1024 | 2.469 | 2.517 | -1.9% | 2.141 | +15.3% | yes |
| html_table | 1024 | 1289.836 | 1273.314 | +1.3% | 602.974 | +113.9% | yes |
| mixed_document | 1024 | 481.439 | 504.220 | -4.5% | 736.182 | -34.6% | yes |

[Raw samples and hashes](../benchmarks/release-tag-results.json) include the measured commits and initial/final host load. [Maintenance measurements](maintenance-measurements-2026-10-01.md) record the static code-quality changes and remaining maintenance debt.

## Reproduce

Create detached worktrees for both tags. Prepare each worktree with a generated Composer autoloader, and the candidate, with the same runtime. The PHP tags used `composer dump-autoload --no-dev`; they have no external runtime package dependencies.

Run from the candidate worktree, substituting absolute paths:

```sh
python3 benchmarks/compare-revisions.py --engine php \
  --harness "$PWD/benchmarks/maintenance.php" \
  --revision dev-main=/absolute/path/to/candidate \
  --revision 0.1.10=/absolute/path/to/latest-tag \
  --revision 0.1.9=/absolute/path/to/previous-tag \
  --output /tmp/release-tag-results.json
```

The comparison wrapper enables the mixed-document case, resolves commits from each repository, rotates revision order, and refuses unstable input bytes or output hashes across rounds.
