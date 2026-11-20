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
- A `soft_break`, the line end inside a wrapped paragraph, is the inline leaf `carveSoftBreak`. The kit renders it
  as `<span data-carve-soft-break> </span>`, so the editor shows a space and the cursor passes it as one character,
  and the way back saves it as `soft_break`. Copy and paste inside the editor keeps it. Plain text pasted from outside
  is lossy: its line ends arrive as spaces, so the pasted paragraph saves as one line.

A ProseMirror node or mark with no Carve node, such as a mark an application extension added, is an error
(`InvalidArgumentException`), not a skip. See [Application nodes on the way in](#application-nodes-on-the-way-in).

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

## Application nodes on the way in

The bridge has no `register()`. An application node or mark the kit has no name for is saved by turning it into a
kit shape before `convert()`. Every shape below is input the bridge reads, the same as the output it writes:

- Any kit node or mark with a `carve` attribute: its keys without a `$` are tree fields, copied onto the Carve node
  as they stand. `attrs` (`classes`, `keyValues`, `id`) is the usual field to fill.
- `carveDiv` (block) and the `carveSpan` mark (inline) are the general containers: give them the application's
  name as a class and its data as key/value pairs.
- `carveBlock` and `carveInline` atoms carry one whole Carve node in `carve`, for a node that is not a div or a
  span (an extension node, a raw block).
- Keys starting with `$` are the kit's bookkeeping, such as `$index` or `$id`. Leave them out of what an application
  builds.

A mention node and a poll widget, mapped before saving:

```php
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$mention = ['type' => 'mention', 'attrs' => ['id' => '7', 'label' => 'Ann']];
$poll = ['type' => 'poll', 'attrs' => ['question' => 'Lunch?']];

$asSpan = static fn (array $node): array => [
    'type' => 'text',
    'text' => $node['attrs']['label'],
    'marks' => [['type' => 'carveSpan', 'attrs' => ['carve' => [
        'attrs' => ['classes' => ['mention'], 'keyValues' => [['data-id', $node['attrs']['id']]]],
    ]]]],
];
$asDiv = static fn (array $node): array => [
    'type' => 'carveDiv',
    'attrs' => ['carve' => ['attrs' => ['classes' => ['poll']]]],
    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $node['attrs']['question']]]]],
];

$doc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi '], $asSpan($mention)]],
    $asDiv($poll),
]];
assert((new ProseMirrorToCarve())->convert($doc) === "Hi [Ann]{.mention data-id=7}\n\n::: poll\nLunch?\n:::\n");
```

To find out which names the kit reads, `ProseMirrorToCarve::carveTypeFor($name)` gives the Carve tree type of a
node or mark name (`'tableHeader'` gives `'table_cell'`), or null for a name the kit does not map. `proseMirrorNames()`
lists them all. `carveBlock` and `carveInline` give null, since they carry a node of any type.

## Content from a stock Tiptap editor

The way back also reads what a Tiptap setup without the kit's own content makes:

- A cell's `colspan` and `rowspan` attributes are the Carve spans: the cell's counts and the `<` and `^` marks of the
  cells it covers. On load the counts go to those attributes, so the editor shows the merged cell. A row span stops
  at the end of its table group, and a cell that spans rows and columns keeps only its columns, since a `^` below a
  cell of more than one column joins nothing.
- In a table without a head, the leading rows whose cells are all `tableHeader` are the head, as they are when the
  written source is parsed.
- A block-level `image` (Tiptap's Image default) is an image paragraph, the tree of `![alt](src)`.

`ignoredAttributes()` lists every attribute the last save did not read, with the JSON pointer of its node or mark.
Examples are the `textAlign` of a TextAlign extension, a link's `target` and the `colwidth` of a resized column. Null
values and the defaults the kit's Link gives every link are not listed. A host that must not lose editor state can
refuse the save:

```php
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$doc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'x']]],
]];
$bridge = new ProseMirrorToCarve();
$source = $bridge->convert($doc);
assert($bridge->ignoredAttributes() === [
    ['path' => '/content/0', 'type' => 'paragraph', 'attribute' => 'textAlign', 'value' => 'center'],
]);
```

The decoded tree of `toTree()` goes to `Carve::render()` and to the Markdown, plain text and ANSI exporters as it
is, with no `Carve::decodeTree()` in between.

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
