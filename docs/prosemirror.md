# ProseMirror / Tiptap bridge

Carve to a ProseMirror document and back, in PHP, without a Node runtime.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$source = "# Notes\n\nSome *bold* text.\n";

$json = (new ProseMirrorRenderer())->renderJson(Carve::parse($source)); // editor.commands.setContent(JSON.parse(json))

$back = (new ProseMirrorToCarve())->convert($json);                    // editor.getJSON(), as JSON or decoded
assert($back === Carve::format($source));
```

The pair lets a PHP application edit documents in a Tiptap editor in the browser and store, render or convert them
on the server: in queue workers, CLI commands and request handlers where no Node runtime exists.

## The schema

The bridge writes and reads the schema of the Carve 0.2 Tiptap kit in
[carve-grammars](https://github.com/markup-carve/carve-grammars) (`tiptap/tree.js`, editor extensions in
`tiptap/extensions.js`). It is a port of that file, function for function, so a document the browser kit loads and one
this bridge renders are the same JSON, and either side reads what the other saved.

- Each ProseMirror node and mark keeps the tree fields the editor does not own in a `carve` attribute. Attributes,
  list markers, table sections, footnote keys and the rest travel there, so the way back rebuilds each node exactly.
- Inline containers become marks (`bold`, `italic`, `link`, `carveSpan`, ...) carrying an instance id and depth, so
  two abutting spans stay apart and nesting order holds.
- A node the editor content model cannot hold becomes an atom, `carveBlock` or `carveInline`, carrying the whole node.
  Nothing is dropped: `ProseMirrorToCarve::toTree()` of a rendered document gives the tree back.
- Keys of `carve` that start with `$` are the kit's own bookkeeping. No tree field starts with `$`.

A ProseMirror node or mark with no Carve node, such as a mark an application extension added, is an error
(`InvalidArgumentException`), not a skip.

`tests/fixtures/prosemirror/kit-wire.json` holds what `tree.js` produces at the commit named in its `_provenance`,
for one input per node and mark type, and `KitWireTest` checks both directions against it. Refresh it when the kit
changes, and bump the commit.

## Editor documents

Content the editor makes carries no `carve` attribute and gets the Carve defaults: a new list is tight with `-` or `.`
markers, a new table row lands in the section of the row before it, an empty trailing paragraph is dropped.

```php
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$doc = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
    ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
    ]],
]];
assert((new ProseMirrorToCarve())->convert($doc) === "== Title\n\n*bold*\n");
```

`convert()` writes with `Carve::write()` and takes its options. `toTree()` returns the tree as decoded AST JSON, and
`toTreeWithOrigins()` also says, for each top-level block, which loaded block it came from (`null` for a block the
editor added).

## Destinations the editor would resolve

The editor renders a link mark's `href` and an image's `src` into the page. A destination the URL policy denies
(`javascript:`, `data:` and the rest of the default deny list, or the host's `urlPolicy`) is `null` there and kept in
`carve`, so the editor never resolves it and the round trip still gives the same tree. `blanked()` lists the
destinations of the last `render()`:

```php
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;

$renderer = new ProseMirrorRenderer(['urlPolicy' => ['allow' => ['https']]]);
$renderer->renderSource('[ok](https://a.example) [no](javascript:alert(1))');
assert($renderer->blanked() === ['javascript:alert(1)']);
```

Every other field lives in the `carve` attribute, which the kit stores as data (`data-carve`) and never turns into
a live URL or HTML attribute.

## Fidelity

`RoundTripTest` checks Carve to ProseMirror JSON and back on every conformance input of the pinned spec and on 1,000
documents generated from them (nested in quotes and list items): the tree comes back the same, and `convert()` writes
the same source as `Carve::write()` of the parsed tree. `EditorDocumentsTest` does the same for every Carve input of
the 0.1 bridge tests.

## Moving from the 0.1 bridge

The class names are the same; the schema and the calls changed. See the ProseMirror section of
[the migration guide](migration.md).
