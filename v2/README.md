# Carve 0.2 for PHP

The `v2/` tree is the Carve 0.2 engine: parser, HTML renderer, linter, HTML importer and canonical writer. It follows the
next2 specification (freeze candidate 12). Namespace `MarkupCarve\Carve2`, PHP 8.2 or later; `Dom\HTMLDocument` is used
for import from PHP 8.4 on, `DOMDocument` before that.

## API

All entry points are static methods on `MarkupCarve\Carve2\Carve2`. `$options` is the option map of the specification
(`smartTypography`, `rawHtml`, `extensions`, `urlPolicy`, ...); an unknown or malformed option throws `OptionError`.

| Method | Returns |
|---|---|
| `parse($source, $options)` | the tree as a `Node` |
| `parseWithDiagnostics($source, $options)` | `ParseResult`: tree and parse diagnostics |
| `parseWithLayout($source, $options)` | `LayoutResult`: tree and the source-layout record |
| `validateLayout($json, $tree = null)` | the layout record, or `LayoutError` when it is refused |
| `renderWithDiagnostics($source, $options)` | `RenderResult`: HTML and all diagnostics |
| `toHtml($source, $options)` | HTML |
| `toJson($source, $options)` | the tree as AST JSON |
| `lint($source, $options)` | lint diagnostics |
| `includeDependencies($source, $options)` | the files the includes of a document read |
| `importHtml($html, $options)` | `ImportResult`: tree, AST array, diagnostics and the Carve source |
| `write($tree, $layout = null, $options)` | Carve source of an AST array; throws `CarveWriteError` on a loss |
| `writeWithDiagnostics($tree, $layout = null, $options)` | `WriteResult`: source and the loss diagnostics |
| `format($source, $options, $layout = null)` | the canonical form of a document |
| `formatWithDiagnostics($source, $options, $layout = null)` | `WriteResult` of the canonical form |

Writer options: `normalize` (ignore the layout record), `alignTables` (pad pipe tables). Import options: `rawHtml`
(keep raw HTML nodes) and `strictLosses`.

Extensions are passed as `ExtensionDefinition` objects in the `extensions` option. `Extension\Tabs::extensions()` gives
the standard tabs extension; pass `true` to get its renderer as well.

```php
use MarkupCarve\Carve2\Carve2;

$html = Carve2::toHtml("# Title\n\nSome *strong* text.\n");
$source = Carve2::importHtml('<p>Hi <em>there</em></p>')->source;
$canonical = Carve2::format($source);
```

## Command line

```
v2/bin/carve2 [render] FILE...           HTML
v2/bin/carve2 ast FILE...                AST JSON (also --tree)
v2/bin/carve2 diagnostics FILE...        diagnostics
v2/bin/carve2 lint FILE...               lint findings
v2/bin/carve2 fmt FILE...                canonical form (alias: format)
    --check  --diff  --write|-w  --normalize  --align-tables  --from-ast
v2/bin/carve2 import --from=html [--raw-html] FILE...
```

Common flags: `--json`, `--includes`/`--no-includes`, `--include-root=DIR`, `--rules=JSON`, `--platforms=JSON`,
`--option=NAME=JSON`. `-` reads standard input.

Exit codes: `0` done; `1` a lint finding, a file that `fmt --check` would change, or a write loss; `2` a usage or
input error.

## Development

```
composer v2-test          PHPUnit, the corpus and the clause tests
composer v2-stan          PHPStan level 9
composer v2-cs-check      code style
composer v2-tables-check  generated tables are current
php v2/scripts/writer-gates.php tokens N | lines N | random COUNT [SEED] [K/N]
```

The writer gates parse each generated input, write it and parse it again. A tree that changes, or a second write that
gives other bytes, fails unless the writer reported the loss.
