# Carve 0.2 for PHP: library guide

carve-php (`markup-carve/carve-php`) implements Carve 0.2 under the namespace `MarkupCarve\Carve`. Its one entry point is the static class `MarkupCarve\Carve\Carve`; every class it names in a
signature is listed in the [API reference](api.md). Everything else under `MarkupCarve\Carve` is internal. Hosts
moving from 0.1 start with the [migration guide](migration.md).

PHP 8.2 or later. HTML import needs `ext-dom`.

Each example below runs in the test suite (`tests/PublicApi/DocsExamplesTest.php`).

## Render and parse

`render()` takes Carve source or a tree. Options are one array, validated against the option table of the
specification; an unknown name or a bad value throws `OptionError`.

```php
use MarkupCarve\Carve\Carve;

$html = Carve::render("# Title\n\nSome *strong* text.\n", ['sections' => false]);
assert($html === "<h1 id=\"Title\">Title</h1>\n<p>Some <strong>strong</strong> text.</p>");

$tree = Carve::parse("# Title\n");
assert($tree->type === 'document');
assert(Carve::render($tree, ['sections' => false]) === '<h1 id="Title">Title</h1>');
```

## Diagnostics

The `...WithDiagnostics` calls return the result and a list of `Diagnostic` entries (`code`, `severity`, `channel`,
`message`, `path`, byte offsets, `file`; optional `includedBy` is a chain of include-site ranges).

```php
use MarkupCarve\Carve\Carve;

$result = Carve::renderWithDiagnostics('{{ part.crv }}');
assert($result->codes() === ['include-no-resolver', 'include-unresolved']);

$findings = Carve::lint("{#a}\nx\n\n{#a}\ny\n");
assert($findings[0]->code === 'duplicate-id');
```

## Errors

Every error a call throws implements `MarkupCarve\Carve\CarveException`: `OptionError`, `InputError`,
`RenderError`, `Writer\CarveWriteError`, `Json\TreeError`, `Layout\LayoutError` and `Import\ImportInputError`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use MarkupCarve\Carve\OptionError;

try {
    Carve::render('a', ['profile' => 'huge']);
} catch (CarveException $e) {
    assert($e instanceof OptionError);
}
```

## Format, write and import

```php
use MarkupCarve\Carve\Carve;

assert(Carve::format("a  \nb\n") === "a\nb\n");
assert(Carve::write(Carve::parse("Some *x*\n")) === "Some *x*\n");
assert(Carve::importDocument('<p>Hi <em>there</em></p>', ['from' => 'html'])->source === "Hi /there/\n");
assert(Carve::importDocument("# Hello *world*\n", ['from' => 'gfm'])->source === "= Hello /world/\n");
assert(Carve::importDocument("_hi_ *there*\n", ['from' => 'djot'])->source === "/hi/ *there*\n");
```

Markdown (`markdown`, CommonMark 0.31), GFM (`gfm`) and Djot (`djot`) are imported through the HTML importer, as in
carve-js: `league/commonmark` or `php-collective/djot` renders HTML and the HTML importer reads it, so every format
follows the same tree rules. Both libraries are optional Composer suggestions: install `league/commonmark` for
`markdown` and `gfm`, `php-collective/djot` for `djot`. A format whose library is missing throws `ImportInputError`
with the `composer require` command, and HTML import keeps working. The step in between keeps what the library HTML cannot carry: raw HTML and Djot raw
content stay raw nodes, a tight list stays tight when an item holds a second block, Markdown frontmatter (YAML or
TOML), math (`$x$`, `$$`), container directives (`:::note[Title]`) and GitHub alerts (`> [!NOTE]`) become
frontmatter, math, admonitions and divs, and a Djot list keeps a start of `0`. Generated Djot heading ids are left to
the Carve renderer. Known losses of the 0.2 language: a loose list of one item is tight, an empty heading or link is
dropped, reference links become inline links, a code fence keeps only its language, and Djot smart punctuation keeps
its glyph. Findings name no byte range, as the HTML they point into is not the caller's input. BBCode import is
JavaScript only (`@markup-carve/carve/import`).

A Markdown or GFM import refuses with `Import\ImportInputError` (`import-input-invalid` on the command line) when one
inline block (a paragraph across all its lines, a heading or a table row) holds more than 16,384 inline candidate
positions, the places an inline parser of `league/commonmark` could start: brackets, `*`, `_`, a backtick, a
backslash, `$`, `~`, an autolink, an entity, a tag or a line break between two lines. `league/commonmark` keeps 1.3 to
2.7 KB for each one, so without the limit one long paragraph of punctuation passes a 128 MB `memory_limit`; at the
limit it stays under about 40 MB. Code blocks do not count, and each block counts on its own.

## Tree JSON

`encodeTree()` gives the bare tree, `encodeEnvelope()` the envelope for storage, and `decodeTree()` reads either one
back, refusing a tree the reader checks refuse with `Json\TreeError`. Reading an envelope appends the extension
declarations its root lacks to the root `extensions` (C2-A-013), so the decoded tree is valid on its own.

```php
use MarkupCarve\Carve\Carve;

$json = Carve::encodeEnvelope(Carve::parse("a\n"));
$tree = Carve::decodeTree($json);
Carve::validateTree($tree);
assert(Carve::render($tree) === '<p>a</p>');
```

Both calls take an `annotations` option, the annotation-range sidecar `{ranges}` of C2-A-214. Each range has `id`,
`start` and `end`, and may carry a `data` object whose keys are qualified names declared on the tree's root or
envelope (C2-A-029). The reader checks the sidecar and leaves it unchanged.

## Includes

Pass a resolver in the `includes` option: `Include\FileResolver::forRoot()` reads files under an absolute root
(C2-N-012). Its `allowAbsolute` argument defaults to `true`; set it to `false` to reject absolute include paths
before reading. Or implement `Include\IncludeResolver` to read from anywhere else. Without a resolver an include stays
unexpanded and is reported.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Include\IncludeResolver;

$resolver = new class implements IncludeResolver {
    public function resolve(array $request): array
    {
        return ['text' => "Included *text*.\n", 'id' => 'db:' . $request['path']];
    }
};
assert(Carve::render('{{ intro.crv }}', ['includes' => $resolver]) === '<p>Included <strong>text</strong>.</p>');
assert(Carve::includeDependencies('{{ intro.crv }}', ['includes' => $resolver])[0]['file'] === 'db:intro.crv');
```

## Exports: Markdown, plain text and ANSI

`Export\MarkdownExporter`, `Export\PlainTextExporter` and `Export\AnsiExporter` write a tree as Markdown (CommonMark
with the GFM tables, task lists, strikethrough and footnotes), as plain text, or as text styled with ANSI escape
sequences for a terminal. Each `export($tree, $options)` returns an `Export\ExportResult`: the text in `output` and the
losses of the run in `diagnostics`. The tree is not changed. The behavior follows the carve-js exports
(`docs/export.md` in carve-js).

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;

$tree = Carve::parse("= Notes\n\nSee *this* and </#Notes>.\n");
assert((new MarkdownExporter())->export($tree)->output === "# Notes\n\nSee **this** and [Notes](#notes).\n");
assert((new PlainTextExporter())->export($tree)->output === "Notes\n\nSee this and Notes.\n");
assert(str_contains((new AnsiExporter())->export($tree)->output, "\e[1mthis\e[0m"));
assert((new MarkdownExporter())->export(Carve::parse("::: x\nz\n:::\n"))->codes() === ['markdown-container-flattened']);
```

Options: `smartTypography` (default `true`: the glyph of smart punctuation; `false`: the characters as typed),
`strictLosses` (throw `Export\CarveExportError` with the `diagnostics` instead of returning a result that lost
something) and `urlPolicy` (`['deny' => [...]]` or `['allow' => [...]]`; a denied destination is written empty and
reported). `extensions` accepts the same registrations and bundles as `parse`. A registration can use
`withExporter('markdown', $callback)`, `withExporter('plain', $callback)` or `withExporter('ansi', $callback)`.
The callback receives the resolved extension node and an `Export\ExportHost`; `$host->target` names the current
target and `$host->fallback()` writes the node's core fallback through that target. Its returned text replaces
the fallback for that target. A target without a callback still writes the core fallback. An unknown option
throws `OptionError`.

The losses are `render-loss` entries with the pointer of their node: `markdown-container-flattened`,
`markdown-caption-flattened`, `markdown-attributes-dropped`, `fence-title-dropped`, `raw-html-escaped`,
`table-header-row-demoted`, `table-header-cell-demoted` and `list-style-dropped` (Markdown); `ruby-flattened` and
`editorial-comment-flattened` (plain, ANSI); and `table-span-flattened`, `raw-format-dropped`, `destination-denied`,
`include-unresolved` and `derived-text-budget` (all three). The command line writes them with
`carve render --to markdown|plain|ansi`.

## Output writers

`Carve::writers()` returns a registry with the `html` and `carve` targets and the exports under the reserved targets
`markdown`, `plain` and `ansi`, which no other writer may take. A host adds its own `OutputWriter`. An unknown target
throws `OptionError`, whose message lists the known targets.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Output\OutputWriter;

$registry = Carve::writers()->register(new class implements OutputWriter {
    public function target(): string
    {
        return 'count';
    }

    public function write(Node $tree, array $options = []): string
    {
        return (string)count($tree->children ?? []);
    }
});
$tree = Carve::parse("a\n\nb\n");
assert(Carve::writeTo('html', $tree, [], $registry) === "<p>a</p>\n<p>b</p>");
assert(Carve::writeTo('count', $tree, [], $registry) === '2');
```

## Host helpers

`Carve::plainText()` gives the plain text of a node or a node list of a tree (C2-H-143), the same text an extension
renderer gets from `RenderHost::$plainText`, so a host can read element text before rendering. `Carve::tableGrid()`
expands a `table` node for an output without rowspan and colspan: per row its group and, per column, the cell that
occupies the slot (null where no cell reaches). `Carve::safeCodeFence()` gives the shortest fence, three at least,
that no line of the content can close.

```php
use MarkupCarve\Carve\Carve;

$tree = Carve::parse("Ask :product[#SKU-1] now.\n\n| a | < | b |\n| c | d | ^ |\n");
assert(Carve::plainText($tree->children[0]) === 'Ask #SKU-1 now.');
$grid = Carve::tableGrid($tree->children[1]);
assert($grid[0]['cells'][0] === $grid[0]['cells'][1] && $grid[1]['cells'][2] === $grid[0]['cells'][2]);
assert(Carve::safeCodeFence("```\ncode\n```\n") === '````');
```

## Extensions (provisional)

The extension host follows `extensions.md` of the specification and may change in 0.2.x. An extension is an
`ExtensionDefinition`: a qualified name, a version, its slots, a matcher (`div`, `fence` or `inline`) with a transform
that turns the claimed core node into an extension node, and an optional renderer, which is trusted host code. A
package ships its definitions through `ExtensionProvider` and the host passes it in the `extensions` option. The
same option goes to `parse`, `render`, `lint`, `format`, `write` and `decodeTree`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;

$embed = new class implements ExtensionProvider {
    public function definitions(): array
    {
        return [new ExtensionDefinition(
            'media.embed',
            '1',
            ['caption'],
            matcher: ['form' => 'fence', 'name' => 'embed'],
            transform: static function (Claim $claim): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => 'media.embed',
                    'version' => '1',
                    'slots' => ['caption' => []],
                    'props' => ['url' => trim($claim->source['body'] ?? '')],
                    'fallback' => [$claim->copyNode()],
                ];

                return $node;
            },
            renderer: static fn (Node $node, RenderHost $host): string
                => '<iframe src="' . RenderHost::escapeAttribute((string)($node->f['props']['url'] ?? '')) . '"></iframe>',
        )];
    }
};
$source = "```embed\nhttps://example.com/v/1\n```\n";
assert(Carve::render($source, ['extensions' => [$embed]]) === '<iframe src="https://example.com/v/1"></iframe>');
assert(Carve::format($source, ['extensions' => [$embed]]) === $source);
```

A renderer receives a `RenderHost`: child lists, plain text, escaping, `attributes()` for the authored attributes
of a node, collision-free generated ids, the `labels`, the `profile` and the render `mode` (C2-X-033). In
`mode: static` the engine uses the definition's `buildTimeRenderer`, and writes the fallback when there is none
(C2-X-052). Document transforms (C2-X-053) run in order on a copy of the resolved tree: those of the registered
definitions (`documentTransform`) in every render, then the `DocumentTransform` objects passed to
`Carve::renderWithDocumentTransforms()`.

### Standard extensions

The provisional standard extensions are bundles (`Extension\Bundle`, an `ExtensionProvider`) of definitions with
their renderers, and of document transforms where the extension needs one. Pass a bundle in `extensions` and its
`transforms()` to `renderWithDocumentTransforms()`; `Bundle::merge()` combines several. The catalog is in
[extensions.md](extensions.md).

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\CodeCallouts;
use MarkupCarve\Carve\Extension\FencedRender;

$bundle = Bundle::merge(FencedRender::mermaid(), CodeCallouts::bundle());
$source = "```mermaid\ngraph TD; A-->B\n```\n";
$html = Carve::renderWithDocumentTransforms($source, $bundle->transforms(), ['extensions' => [$bundle]])->html;
assert($html === '<pre class="mermaid" role="img" aria-label="mermaid">graph TD; A-->B</pre>');
```

## Command line

The package installs `carve` (`vendor/bin/carve`), the program of tooling.md. See the [README](../README.md) for its
commands and flags.
