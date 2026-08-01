# ProseMirror / Tiptap bridge

Carve AST to a ProseMirror document and back, without a Node runtime.

```php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$document = (new CarveConverter())->parse($source);

$renderer = new ProseMirrorRenderer();
$json = $renderer->renderJson($document);      // hand this to a Tiptap editor

$back = (new ProseMirrorToCarve())->convertJson($json);
(new CarveConverter())->render($back);          // HTML
CarveConverter::carve()->getRenderer()->render($back);  // Carve source
```

The point of the pair: an editor in the browser, and PHP rendering the stored
document to HTML, PDF or DOCX in queue workers and CLI commands where no Node
runtime exists.

## This is the import route; the HTML path is the fallback

Before this bridge existed, the way into an editor was to render Carve to HTML
and let Tiptap's `parseHTML` re-derive the document. That still works and is
still worth keeping - it is how you load content an extension understands but
this engine has no node for. It is no longer the recommended route, for one
reason: fidelity there is bounded by what each extension's `parseHTML` claims,
so an attribute no extension declares is dropped **silently**. For a stored
document format, silent attribute loss on load is the failure you cannot
recover from, because nothing records that it happened.

The bridge builds from the AST instead, and reports what it could not carry -
`droppedTypes()` for content that is gone, `degradedTypes()` for a node type
that is gone while its text survives. Prefer it for anything you intend to
store and read back.

## Where the names come from

Node and mark names are **not** defined here. They come from
`resources/prosemirror-schema-map.json`, a copy of the map published by
[carve-grammars](https://github.com/markup-carve/carve-grammars), which owns the
`CarveKit` schema and the serializer. Restating the mapping in each engine is how
implementations drift - carve-php once emitted `citation-group` while everything
else spelled it with underscores.

Refresh the copy from upstream and bump the `commit` in its `_provenance` block.
`ProseMirrorCorpusTest` fails if this engine grows a node type the map has no
decision for.

## What the editor model cannot hold

Roughly a third of Carve's node types have no ProseMirror equivalent. The bridge
never guesses; it reports, in two categories:

```php
$renderer->droppedTypes();    // ['comment' => 'comments are not represented …']
$renderer->degradedTypes();   // ['soft_break' => 'a soft break is whitespace …']
```

- **Dropped** - the content is gone: comments, figures with captions, frontmatter,
  cross-reference links, line blocks, inline footnotes, raw passthrough.
- **Degraded** - the node type is gone but the text survives: a soft break becomes
  a space, a smart quote becomes its glyph, an escaped character becomes the
  character. Dropping these instead would run words together or lose a character.

An application storing documents should assert both are empty rather than trust
them:

```php
$json = $renderer->renderJson($document);
if ($renderer->droppedTypes() !== []) {
    throw new RuntimeException('editor cannot hold: ' . implode(', ', array_keys($renderer->droppedTypes())));
}
```

Going the other way, an unknown ProseMirror name is an **error**, not a skip: an
editor that grew a node nobody mapped is exactly where silent loss is worst.

## Fidelity

`ProseMirrorCorpusTest` sweeps the whole spec corpus. The strict gate is narrow on
purpose: a document whose types the editor model fully covers must round-trip to
**byte-identical HTML**. Documents that lose something are allowed to differ,
because they must.

Current state, and both numbers are ratchets:

| | count |
|---|---|
| corpus documents | 501 |
| fully covered, byte-identical HTML | 336 |
| fully covered but differing (each one a bug worth fixing) | 29 |
| threw | 0 |

## Application node types

An application's own editor node survives as an attributed container - no library
change needed:

```
{#calc-1 .calculation data-label="Wärmebedarf" data-unit=kWh}
::: calculation
42
:::
```

becomes a `carveDiv` carrying `data-label` and `data-unit` in its attrs, and comes
back with them intact. Nodes that subclass `Node` instead can be registered with
`AstCodec::register()` for the JSON codec; the bridge itself needs the upstream map
to know the name, so a genuinely new editor node belongs in carve-grammars first.

## Shape differences worth knowing

- **Marks.** Carve nests emphasis as elements (`Strong > Text`); ProseMirror hangs
  marks off the text node. Coming back, adjacent runs of the same mark are merged,
  or `*bold with /italic/ inside*` would return as three `<strong>` elements.
- **Content-bearing nodes.** `code` is a mark in ProseMirror but a node holding its
  text in Carve; a code block likewise. Both are translated explicitly.
- **Tables.** ProseMirror marks header *cells*; Carve also flags the *row*, which
  is what puts it in `<thead>`. A table caption is state on the table, not a child,
  so it travels as a leading `carveCaption` node.
- **Lists.** Looseness is content, not styling: without carrying `tight`, a loose
  list comes back tight and its items lose their paragraphs.
- **Mention labels.** Tiptap's mention extension stores a display name, and a
  mention name is ASCII with interior dots and nothing else - so a label an
  editor produced (`Mark Scherer`, `o'brien`) is written as the link form,
  `[Mark Scherer](/u/42){.mention}`, which keeps the label, the href and the
  class. Attributes and markup inside the label take the same route, for the same
  reason. It reads back as a **link carrying `class="mention"`**, not as a
  `carveMention` node, and nothing is reported dropped or degraded: an editor
  that builds its mentions from a node type needs a parse rule on the class,
  while one that styles by class already matches.
