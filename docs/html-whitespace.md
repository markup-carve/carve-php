# HTML whitespace differences

An empty raw HTML block occupies a line of its own in the rendered HTML, and so
does a raw block whose payload is a single blank line. PART 2 `raw_block` says
zero payload lines contribute nothing and one blank payload line contributes one
newline, and forbids encoding those two source shapes identically.

For example:

`````carve
- a

  ```=html
  ```
`````

renders as `<ul>\n  <li>a\n    \n  </li>\n</ul>\n`, with the item's closing tag
on its own line. Writing a blank line inside the fence adds one more newline.

This page used to declare a whitespace divergence from the spec oracle for
[#2697](https://github.com/markup-carve/carve-php/issues/2697), following the
[scope ruling on #2680](https://github.com/markup-carve/carve-php/issues/2680#issuecomment-5880084576).
The renderer omitted an empty raw block's rendering, so the item closed on the
same line as its text. That divergence is closed:
[markup-carve/carve#2574](https://github.com/markup-carve/carve/pull/2574) pinned
the two payload shapes as corpus category 521, which no longer permits the
collapse, and [#2714](https://github.com/markup-carve/carve-php/issues/2714)
carried it here.

## Measurements

`tests/fixtures/empty-list-block-spacing.json` records 36 sources and their PHP
HTML, oracle HTML, formatted source and HTML, and HTML-import output. The oracle
results were checked at spec `3e2da233` and the repository's `9b938e8a` pin.

The grid combines a plain item, a two-deep item, and an item inside an explicitly
marked block quote with both raw fence characters. Payloads are absent, one
column below the opener, or at its column. The final fence is at column zero or the
opener's column; only the latter can close a raw block still inside the item.
A column-zero fence after an empty or at-column payload opens a code block
outside the list. Quote prefixes are retained on every line. This is a separate
grid from the issue's 120 shapes; it excludes lazy quote continuation and
footnote bodies covered by [#2610](https://github.com/markup-carve/carve-php/issues/2610).

- All 36 cases match the oracle after trimming the final newline. The 24
  absent/below-column payload cases used to differ from it in whitespace; a
  payload below the opener ends the raw block and remains escaped text outside it.
- All 36 sources reach a formatter fixed point after one pass.
- Importing PHP HTML and oracle HTML produces identical Carve source in every
  case. Importing HTML rendered after formatting produces that same source.

Formatting an empty raw block adds a blank payload line. Its rendered HTML can
therefore change in whitespace on the first pass, even though formatting is
idempotent. HTML import cannot reconstruct an empty raw block from either HTML
string, since neither contains an element representing it. Identical import
results establish that the block's line causes no import loss in this grid; they
do not establish lossless source recovery or equivalence for arbitrary HTML.

Run the regression and recheck the oracle captures with:

```sh
vendor/bin/phpunit tests/TestCase/Renderer/EmptyListBlockSpacingTest.php
node scripts/check-empty-list-spacing.mjs
```

The oracle check uses the spec checkout's Node dependencies. Pass another spec
checkout as the script's first argument to compare that revision. Re-run the
oracle check when bumping the spec pin.
