# Nested container costs

Baseline: `b9dec26cf39b522b23ae95054d1863e094763de7`. Candidate source hashes, raw timing
samples and host load are in [the observations](nested-runtime-costs.json).

Two fresh process rounds, baseline-candidate then candidate-baseline. Per fixture/mode: 150ms warmup, seven batches of at least 50ms; parsing includes result disposal, render reuses AST. Shared host; timing observations are not cross-language rankings.

The fixtures use `"> ".repeat(depth) + "end\n"` and `"- ".repeat(depth) + "end\n"`.
Reported output bytes include indentation, which grows with nesting depth.
These results do not establish linear cost in source bytes or a speed ranking
against other language implementations. They cover warm operations, excluding
process startup and fixture construction. PHP uses a clean INI without JIT or
profiling extensions; Rust uses a release build.

| Fixture | Depth | Operation | HTML bytes | Baseline ms, rounds 1 / 2 | Candidate ms, rounds 1 / 2 |
|---|---:|---|---:|---:|---:|
| quote | 48 | parse | 5723 | 1.489 / 1.909 | 1.895 / 4.229 |
| quote | 48 | render | 5723 | 3.913 / 4.321 | 1.439 / 4.459 |
| quote | 48 | html | 5723 | 5.921 / 25.625 | 3.113 / 6.364 |
| quote | 96 | parse | 20651 | 3.995 / 13.646 | 4.960 / 4.202 |
| quote | 96 | render | 20651 | 24.486 / 73.418 | 8.430 / 4.688 |
| quote | 96 | html | 20651 | 28.698 / 138.116 | 10.673 / 8.878 |
| quote | 192 | parse | 78155 | 10.542 / 26.114 | 14.435 / 10.556 |
| quote | 192 | render | 78155 | 179.108 / 205.286 | 19.529 / 69.458 |
| quote | 192 | html | 78155 | 167.994 / 306.858 | 29.238 / 58.031 |
| list | 48 | parse | 19108 | 7.301 / 8.318 | 7.753 / 11.734 |
| list | 48 | render | 19108 | 23.964 / 28.008 | 4.913 / 7.547 |
| list | 48 | html | 19108 | 32.434 / 32.450 | 12.369 / 16.684 |
| list | 96 | parse | 75076 | 19.939 / 30.281 | 30.611 / 63.797 |
| list | 96 | render | 75076 | 153.872 / 306.395 | 38.720 / 84.536 |
| list | 96 | html | 75076 | 189.973 / 256.629 | 45.550 / 106.643 |
| list | 192 | parse | 297604 | 78.797 / 80.613 | 92.805 / 127.723 |
| list | 192 | render | 297604 | 1224.385 / 1278.766 | 248.453 / 174.549 |
| list | 192 | html | 297604 | 1621.588 / 1044.165 | 398.832 / 316.319 |

## Reproduction

Run the same benchmark source against a baseline checkout and a candidate
checkout, reversing their order in the second round. The Rust example must be
copied into the baseline checkout first. The PHP script accepts the checkout
whose Composer autoloader it should use.

```sh
php -n -d extension=mbstring -d extension=ctype tests/benchmark/nested-containers.php /path/to/checkout
```

The benchmark checks parse/render and combined HTML agreement before timing.
Separate corpus comparisons check baseline/candidate HTML byte-for-byte.
