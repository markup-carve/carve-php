# carve-php

[![CI](https://img.shields.io/github/actions/workflow/status/markup-carve/carve-php/ci.yml?branch=main&style=flat-square)](https://github.com/markup-carve/carve-php/actions)
[![Coverage](https://codecov.io/gh/markup-carve/carve-php/branch/main/graph/badge.svg)](https://codecov.io/gh/markup-carve/carve-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![Total Downloads](https://img.shields.io/packagist/dt/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)

carve-php parses and renders Carve in PHP. It implements Carve spec 0.2: parser, HTML renderer, linter, HTML importer
and canonical writer. Read the [Carve documentation](https://markup-carve.github.io/carve/) for the language.

PHP 8.2 or later. Import (HTML, Markdown, GFM, Djot) needs `ext-dom`; it uses `Dom\HTMLDocument` from PHP 8.4 on and `DOMDocument` before that.

## Install

```sh
composer require markup-carve/carve-php
```

Rendering, formatting, linting and HTML import need nothing else. Markdown, GFM and Djot import use a library you add
yourself:

| Import format | Package |
|---|---|
| `markdown`, `gfm` | `composer require league/commonmark` |
| `djot` | `composer require php-collective/djot` |

Without it, `importDocument()` throws `ImportInputError` and `carve import` exits with `import-input-invalid`, both
naming the package to install.

## Usage

```php
use MarkupCarve\Carve\Carve;

$html = Carve::render("# Title\n\nSome *strong* text.\n");
$source = Carve::importDocument('<p>Hi <em>there</em></p>')->source;
$canonical = Carve::format($source);
```

All entry points are static methods on `MarkupCarve\Carve\Carve`. `$options` is the option map of the specification
(`smartTypography`, `rawHtml`, `extensions`, `urlPolicy`, ...); an unknown or malformed option throws `OptionError`.
Every error a call throws implements `CarveException`.

| Method | Returns |
|---|---|
| `parse($source, $options)` | the tree as a `Node` |
| `parseWithDiagnostics($source, $options)` | `ParseResult`: tree and parse diagnostics |
| `parseWithLayout($source, $options)` | `LayoutResult`: tree and the source-layout record |
| `createEditorSession($source, $options)` | `Ast\EditorSession`: UTF-8 byte-range edits, snapshots and 0.2 AST JSON |
| `supportsIncrementalParagraphReuse()` | whether a plain paragraph edit can reuse the other parsed paragraphs |
| `validateLayout($json, $tree = null)` | the layout record, or `LayoutError` when it is refused |
| `render($sourceOrTree, $options)` | HTML of source, a `Node` or a decoded tree array |
| `tryRenderHtmlStreaming($source, $sink, $options)` | `'complete'`; calls the sink once with the exact HTML of `render()` after a successful render |
| `renderWithDiagnostics($sourceOrTree, $options)` | `RenderResult`: HTML and all diagnostics |
| `toHtml($source, $options)` | HTML |
| `toJson($source, $options)` | the tree as AST JSON |
| `encodeTree($tree)`, `encodeEnvelope($tree)` | the tree as JSON, bare or in its envelope |
| `decodeTree($json, $options)`, `validateTree($tree, $options)` | the tree of AST JSON, or `TreeError` |
| `lint($source, $options)` | lint diagnostics |
| `includeDependencies($sourceOrTree, $options)` | the files the includes of a document read |
| `selectIncludeRegion($source, $name, $mode)` | `['ok' => true, 'start' => ..., 'end' => ...]`: the lines an include with `@region:name` selects from a file, in `carve` or `code` mode |
| `positionOf($source, $offset)` | `['line' => ..., 'column' => ...]` of a UTF-8 byte offset, as the command line counts them |
| `diffTree($a, $b)`, `applyPatch($tree, $patch, $options)` | a JSON Patch (RFC 6902) between two trees, and the tree it gives; `applyPatch` takes `fingerprint` and throws `Json\PatchError` |
| `sourceFingerprint($source)`, `treeFingerprint($tree)` | `sha256:` and the hash of the source bytes or of `encodeTree($tree)` |
| `headingId($text, $options)` | the id `headingIds` generates for a heading with that text, or `null` under `off` |
| `version()`, `SPEC_VERSION`, `NODE_TYPES` | the installed package version, the pinned spec, and the block and inline node types of its schema |
| `importDocument($html, $options)`, `importHtml($html, $options)` | `ImportResult`: tree, AST array, diagnostics and the Carve source |
| `write($tree, $layout = null, $options)` | Carve source of a tree; throws `CarveWriteError` on a loss |
| `writeWithDiagnostics($tree, $layout = null, $options)` | `WriteResult`: source and the loss diagnostics |
| `format($source, $options, $layout = null)` | the canonical form of a document |
| `formatWithDiagnostics($source, $options, $layout = null)` | `WriteResult` of the canonical form |
| `writers()`, `writeTo($target, $tree, $options, $registry = null)` | the output writer registry (`html`, `carve`, `markdown`, `plain`, `ansi`), and a write through it |
| `plainText($nodes)`, `tableGrid($table)`, `safeCodeFence($content)` | host helpers: plain text of nodes, the table grid with spans filled, a fence the content cannot close |

The Markdown, plain-text and ANSI exports are `Export\MarkdownExporter`, `Export\PlainTextExporter` and
`Export\AnsiExporter`; each `export($tree, $options)` returns an `Export\ExportResult` with the text and the losses.

Render options of note: `softBreak` (`newline`, `space` or `br`), `allowLosses` (render-loss codes left out of the
record), `maxLosses` (the cap on kept losses, default 100; the result's `suppressedDiagnostics` counts the rest) and
`codeBlockRenderer`, a `Closure(Node $node, string $defaultHtml): ?string` that returns trusted HTML for a code block,
or `null` to keep the default, and `headingShift` (0 to 5, default 0), which moves every heading down by that many levels
for a page that writes its own title as `<h1>`. The shift runs before every document transform, so options that name
heading levels (`tableOfContents`, `headingPermalinks`, `headingNumbers`) name the shifted levels. A level above 6 is
written as `<div role="heading" aria-level="N">` and reported once as `heading-shift-above-6`; under `roundtrip` each
heading carries `data-carve-source-level`, which the HTML importer reads back. `MarkdownExporter` takes `headingShift`
too and writes a level above 6 as 6; the plain-text and ANSI exports refuse it.

Writer options: `normalize` (ignore the layout record), `alignTables` (pad pipe tables). Import options: `from`
(`html`, `markdown`, `gfm` or `djot`), `rawHtml` (HTML only: keep raw HTML nodes), `adapter` (HTML only: the editor
that wrote the HTML, `generic` by default; `word` and `google-docs` read exported notes as footnotes, C2-M-102),
`strictLosses`, `allowLosses` and `maxLosses`, and `labels` (the labels used to render generated accessible names). Markdown, GFM and Djot go through
the HTML importer: `league/commonmark` (CommonMark 0.31 and the GFM
extensions) and `php-collective/djot`, both optional (see Install), render HTML, which the HTML importer reads. Includes: an
`Include\IncludeResolver` such as `Include\FileResolver::forRoot($root)` in the `includes` option, or an array with
`resolver` and the lowered limits `depth`, `bytes` and `maxDiagnostics` (include failures kept, at most 100).

Extensions (provisional) are passed as `ExtensionDefinition` or `ExtensionProvider` objects in the `extensions` option.
`Extension\Tabs::extensions()` gives the standard tabs extension. Register `carve.tabs` by name and set
`renderers['carve.tabs']` to `['standard' => ['mode' => 'aria']]` for accessible tab controls; the default `css`
mode uses radio controls. `Tabs::extensions(true, 'aria')` registers that renderer directly.

## Command line

```
vendor/bin/carve [render] FILE...           HTML
    --to=html|markdown|plain|ansi             output format
vendor/bin/carve ast FILE...                AST JSON (also --tree)
vendor/bin/carve diagnostics FILE...        diagnostics
vendor/bin/carve lint FILE...               lint findings
vendor/bin/carve fmt FILE...                canonical form (alias: format)
    --check  --diff  --write|-w  --normalize  --align-tables  --from-ast
vendor/bin/carve import --from=html|markdown|gfm|djot [--raw-html] [--list-table] [--adapter=NAME] FILE...
vendor/bin/carve import --from gfm --directory SRC --output DST
```

Every switch `--NAME` also takes the form `--no-NAME`, which turns it off (`--no-sections`, `--no-smart-typography`,
`--no-raw-html`); the last of the two wins. Common flags: `--json`, `--includes`/`--no-includes`, `--include-root=DIR`, `--rules=JSON`, `--platforms=JSON`,
`--option=NAME=JSON`. `render` also takes `--soft-break newline|space|br` and `--heading-shift N` (also with `--to markdown`); `render` and `import` take `--allow-loss CODE`
(repeatable) and `--max-render-losses N`. `lint` also takes `--heading-ids=off|keep-case|lower|ascii|ascii-lower|ascii-strict|ascii-strict-lower`
and `--upgrade`, which turns on the notices about text that 0.1 or Djot read differently (an indented line under a
heading, a bare `_x_`, `~x~` or `=x=` pair, a 0.1 cell alignment prefix, a GFM separator row, a `---` break, a Djot
spelling, and the other rows whose catalog default is `upgrade`). They are off for a 0.2 document and on for one whose
line-1 frontmatter declares `carve: 0.1`.
`render`, `ast`, `lint` and `fmt` take `--extensions=JSON`, a list of standard extension names from the
[catalog](docs/extensions.md) (`render` uses their standard renderers), and `--extension-options=JSON`, their renderer
options keyed by name: `carve render --extensions '["carve.tabs"]' --extension-options '{"carve.tabs":{"mode":"aria"}}'`.
An unknown name, or options for an extension the list does not name, is a usage error.
`-` reads standard input.

Exit codes: `0` done; `1` a lint finding, a file that `fmt --check` would change, or a write loss; `2` a usage or
input error.

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/)
- [Library guide](docs/README.md)
- [API reference](docs/api.md)
- [ProseMirror / Tiptap bridge](docs/prosemirror.md)
- [Migration guide from 0.1](docs/migration.md)

## Development

```
composer test          PHPUnit, the corpus and the clause tests
composer stan          PHPStan level 9
composer cs-check      code style
composer tables-check  generated tables are current
php scripts/writer-gates.php tokens N | lines N | random COUNT [SEED] [K/N]
composer fuzz -- run --seed=N --count=N   property fuzzing (see below)
composer fuzz -- scale                    every operation at 8 times the size: worse than linear fails
composer fuzz -- smoke                    the short fixed-seed run CI does
```

The writer gates parse each generated input, write it and parse it again. A tree that changes, or a second write that
gives other bytes, fails unless the writer reported the loss.

`scripts/fuzz.php` runs random bytes, a token soup, generated documents, mutants of the spec examples and nesting 50 to
5,000 levels deep through parse, render, AST JSON, fmt, lint, the writers and the importers, each input in a worker
under `memory_limit=128M`. A crash, a hang, a dead worker or an operation over 500 ms of CPU time per 32 KB fails the
run; other finds (fmt changes the HTML, fmt is not idempotent, a refusal) are listed by cause with the smallest input.
`composer fuzz -- replay SEED KIND INDEX` prints one input, `minimize SEED KIND INDEX SIGNATURE` shrinks it.

The conformance data in `spec/` is a copy of the specification at the commit named in `spec/PINNED`.

## License

MIT, see [LICENSE](LICENSE).
