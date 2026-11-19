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

PHP 8.2 or later. HTML import needs `ext-dom`; it uses `Dom\HTMLDocument` from PHP 8.4 on and `DOMDocument` before that.

## Install

```sh
composer require markup-carve/carve-php
```

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
| `validateLayout($json, $tree = null)` | the layout record, or `LayoutError` when it is refused |
| `render($sourceOrTree, $options)` | HTML of source, a `Node` or a decoded tree array |
| `renderWithDiagnostics($sourceOrTree, $options)` | `RenderResult`: HTML and all diagnostics |
| `toHtml($source, $options)` | HTML |
| `toJson($source, $options)` | the tree as AST JSON |
| `encodeTree($tree)`, `encodeEnvelope($tree)` | the tree as JSON, bare or in its envelope |
| `decodeTree($json, $options)`, `validateTree($tree, $options)` | the tree of AST JSON, or `TreeError` |
| `lint($source, $options)` | lint diagnostics |
| `includeDependencies($sourceOrTree, $options)` | the files the includes of a document read |
| `importDocument($html, $options)`, `importHtml($html, $options)` | `ImportResult`: tree, AST array, diagnostics and the Carve source |
| `write($tree, $layout = null, $options)` | Carve source of a tree; throws `CarveWriteError` on a loss |
| `writeWithDiagnostics($tree, $layout = null, $options)` | `WriteResult`: source and the loss diagnostics |
| `format($source, $options, $layout = null)` | the canonical form of a document |
| `formatWithDiagnostics($source, $options, $layout = null)` | `WriteResult` of the canonical form |
| `writers()`, `writeTo($target, $tree, $options, $registry = null)` | the output writer registry (`html`, `carve`), and a write through it |

Writer options: `normalize` (ignore the layout record), `alignTables` (pad pipe tables). Import options: `from`
(`html`), `rawHtml` (keep raw HTML nodes) and `strictLosses`. Includes: an `Include\IncludeResolver` such as
`Include\FileResolver::forRoot($root)` in the `includes` option.

Extensions (provisional) are passed as `ExtensionDefinition` or `ExtensionProvider` objects in the `extensions` option.
`Extension\Tabs::extensions()` gives the standard tabs extension; pass `true` to get its renderer as well.

## Command line

```
vendor/bin/carve [render] FILE...           HTML
vendor/bin/carve ast FILE...                AST JSON (also --tree)
vendor/bin/carve diagnostics FILE...        diagnostics
vendor/bin/carve lint FILE...               lint findings
vendor/bin/carve fmt FILE...                canonical form (alias: format)
    --check  --diff  --write|-w  --normalize  --align-tables  --from-ast
vendor/bin/carve import --from=html [--raw-html] FILE...
```

Common flags: `--json`, `--includes`/`--no-includes`, `--include-root=DIR`, `--rules=JSON`, `--platforms=JSON`,
`--option=NAME=JSON`. `-` reads standard input.

Exit codes: `0` done; `1` a lint finding, a file that `fmt --check` would change, or a write loss; `2` a usage or
input error.

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/)
- [Library guide](docs/README.md)
- [API reference](docs/api.md)
- [Migration guide from 0.1](docs/migration.md)

## Development

```
composer test          PHPUnit, the corpus and the clause tests
composer stan          PHPStan level 9
composer cs-check      code style
composer tables-check  generated tables are current
php scripts/writer-gates.php tokens N | lines N | random COUNT [SEED] [K/N]
```

The writer gates parse each generated input, write it and parse it again. A tree that changes, or a second write that
gives other bytes, fails unless the writer reported the loss.

The conformance data in `spec/` is a copy of the specification at the commit named in `spec/PINNED`.

## License

MIT, see [LICENSE](LICENSE).
