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
(`InvalidArgumentException`), not a skip, unless the `mapping` option gives it one. See
[Application nodes](#application-nodes).

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
editor added), and gives the `pointers` of [Editor positions for findings](#editor-positions-for-findings).

## Application nodes

Pass `mapping` to give the application's own nodes and marks a Carve node. The same table goes to both directions:
`new ProseMirrorToCarve(['mapping' => $mapping])` and `new ProseMirrorRenderer(['mapping' => $mapping])`, and to the
Tiptap kit's `proseMirrorToTree(json, { mapping })` and `treeToProseMirror(tree, { mapping })` in the browser.

A placeholder token (an inline atom) and a `textStyle` mark with a color, saved and loaded:

```php
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$mapping = [
    'nodes' => ['placeholderToken' => ['as' => 'span', 'class' => 'placeholder', 'attributes' => ['name']]],
    'marks' => ['textStyle' => ['as' => 'span', 'class' => 'text-style', 'attributes' => ['color']]],
];
$doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'Dear '],
    ['type' => 'placeholderToken', 'attrs' => ['name' => 'first']],
    ['type' => 'text', 'text' => ', '],
    ['type' => 'text', 'text' => 'red', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#f00']]]],
]]]];

$source = (new ProseMirrorToCarve(['mapping' => $mapping]))->convert($doc);
assert($source === "Dear []{.placeholder name=first}, [red]{.text-style color=#f00}\n");

$loaded = (new ProseMirrorRenderer(['mapping' => $mapping]))->renderSource($source);
assert($loaded['content'][0]['content'] === $doc['content'][0]['content']);
```

A declared entry has:

- `as`: `span` (inline) or `div` (block). A mark is always a `span` around the text it covers.
- `class`: the one class that names the type in Carve.
- `attributes`: the ProseMirror attributes to keep, each as a key/value pair: a string as it is, any other value as
  JSON. On load the value is a string.
- `content`: `true` for a node with content (inlines in a span, blocks in a div). Without it the node is an atom.
- `carve`: `true` when the editor's node type has a `carve` attribute (`addAttributes: () => ({ carve: { default:
  null, rendered: false } })`). The renderer then records a top-level node's place in the loaded document, so
  `toTreeWithOrigins()` reports its origin and the kit's `serializeToCarve(..., { source })` keeps its text after an
  edit elsewhere. Without it the node has no `carve` attribute, which ProseMirror's schema check would reject, and
  a top-level host node counts as new. A callback entry takes `carve` too.

Only a span or div of exactly that shape loads back as the application type: the one class, the listed keys and
nothing else. Any other span or div stays a kit node, so nothing it carries is lost. `ignoredAttributes()` lists an
attribute of a declared node that its entry does not name.

For anything else an entry is a pair of closures. `toTree` gets the ProseMirror node and helpers (`position`,
`'block'` or `'inline'`; `blocks` and `inlines`, which convert the node's `content`) and returns a tree node.
`fromTree` gets the tree node and the same helpers (there `blocks` and `inlines` load tree children) and returns the
ProseMirror node, or null for a tree node that is not its own. For a mark, `toTree` gets the mark and returns the
inline container without `children`, and `fromTree` returns the mark or null. Each class uses its own half:
`ProseMirrorToCarve` calls `toTree` and throws without it, `ProseMirrorRenderer` calls `fromTree` and without it
loads the tree node as a kit node. A type that is neither in the kit nor in `mapping` still throws.

### Kit shapes

A `toTree` closure, or code that rewrites a document before `convert()`, can also produce kit shapes. Every shape
below is input the bridge reads, the same as the output it writes:

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

## Editor positions for findings

A lint or export finding carries the tree path of its node (`Diagnostic::$path`). After `toTree()` or `convert()`,
`pointerFor($path)` gives the JSON pointer of the ProseMirror node or mark that tree node came from, which the editor
turns into a position to mark:

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$doc = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Title']]],
    ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Too deep']]],
]];
$bridge = new ProseMirrorToCarve();
[$finding] = Carve::lint($bridge->convert($doc));
assert($finding->code === 'heading-level-skip' && $finding->path === '/children/1');
assert($bridge->pointerFor($finding->path) === '/content/1');
```

A tree node with no ProseMirror node of its own (a span mark cell, a table section, the content of a `carveBlock`)
gets the pointer of its nearest ancestor that has one. An inline container made from a mark points at that mark on
the first text it covers (`/content/0/content/3/marks/0`). `toTreeWithOrigins()['pointers']` has the whole map, tree
path => pointer; the kit returns the same map from `proseMirrorToTreeWithOrigins()` and resolves a path with
`proseMirrorPointer(pointers, path)`.

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
