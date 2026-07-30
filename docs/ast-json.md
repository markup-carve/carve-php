# AST as JSON

`MarkupCarve\Carve\Ast\AstCodec` encodes a parsed document as plain arrays or
JSON and reads it back.

```php
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
$codec = new AstCodec();

$document = $converter->parse("## Title\n\nText with *bold*.");

$json = $codec->encodeJson($document, JSON_PRETTY_PRINT);
$again = $codec->decodeJson($json);

$converter->render($again); // identical to render($document)
```

## From the CLI

```bash
bin/carve --json README.crv > tree.json     # parse and emit the AST
bin/carve --from-json tree.json             # render a tree back to HTML
bin/carve --from-json --markdown tree.json  # or to any other format
```

`--json` (alias `--ast`) replaces the renderer with the encoder; `--from-json`
replaces the parse step with a decode, so a tree produced by any tool in any
language renders through the same formats as source. Malformed input reports on
stderr and exits 1.

## Why

Until now the AST was reachable only as PHP objects, so anything that is not
"source to HTML" had to render HTML and re-parse it. That is why a
ProseMirror/Tiptap serializer only exists in JavaScript, why `HtmlToCarve` has to
be as large as it is, and why an editor bridge is a design project rather than a
mapping. The tree is what editors, linters, structural diffing and
cross-implementation conformance actually want.

## Shape

A document:

```json
{
  "type": "document",
  "srcByteLength": 25,
  "children": [ ... ]
}
```

There is no version envelope: the shape is spec-defined (PART 12), and §3 forbids
a field the reference does not have.

Any node:

```json
{
  "type": "heading",
  "level": 2,
  "attrs": {"id": "title"},
  "children": [{"type": "text", "value": "Title"}]
}
```

Rules, all of them:

1. **`type`** is the node's `getType()` value: `heading`, `code_block`,
   `table_cell`, `text`. This is the same snake_case vocabulary `Profile` uses
   for allow and deny lists, so the names were already public.
2. **`attrs`** holds the node's attribute map, omitted when empty.
3. **`children`** holds child nodes, omitted when empty.
4. **Every other key** is the node's own declared state: `level` on a heading,
   `language` on a code block, `colspan` on a table cell.
5. **A field is omitted when it holds the node's default**, and a decoder puts
   the default back. The default is the declared property default, or failing
   that the constructor parameter default. Note what this is *not*: omitting
   every falsy value would lose information wherever the default is not falsy -
   a loose list is `tight: false` against a default of `true`, so it is written
   out explicitly.
6. **Node-valued state is encoded like a child.** A div's quoted opener nodes or
   a table caption are nodes, so they use the same shape - no second
   representation anywhere in the format.
7. **Names are the reference's, not this engine's.** `ReferenceShape` maps them
   on the way out: `content` is published as `value`, `destination` as `href`, a
   list's children as `items`. Internals the reference has no field for are not
   exported at all; the decoder recomputes them.

## Field names are spec surface

PART 12 §3 makes the field names normative and takes them from carve-js: a
consumer reading `href` must not have to know which engine produced the tree.
This engine's internals differ, so `src/Ast/ReferenceShape.php` maps between the
two in one table:

| what this engine calls it | what goes on the wire |
|---|---|
| `text.content`, `code.content` | `value` |
| `link.destination` | `href` |
| `image.source` | `src` |
| `code_block.language` | `lang` |
| `footnote.label` | `id` |
| a list's `children` | `items` |
| a table's / row's `children` | `rows` / `cells` |

Three kinds of difference exist, and only the first is a rename. Containers
publish their children under another key. Derived state converts in both
directions: `ordered` is a boolean over an internal `listType` string, `checked`
comes from a task marker, a cell's `header` from its flag.

Internal fields the reference has no counterpart for are **not** exported (§3) -
a div's raw header string, a row's `isHeader`, a fence's width. Each is listed in
`ReferenceShape::INTERNAL_ONLY` so the omission is a decision rather than an
oversight, and each must be *recomputable*, or the §6 round trip would break.
That constraint is what the corpus gate enforces.

The wire shape is pinned by `tests/fixtures/ast-schema.json` plus
`AstCodecSchemaTest`: the full type-to-fields map is a golden file, so a change
fails CI and has to be either reverted or accepted deliberately.

Some fields have no default at all - neither a property nor a constructor one -
so a payload must carry them. Omitting one is an error rather than a guess,
because the alternative was inventing a zero: a heading without `level` used to
render as `<h0>`.

Inspect both, per type:

```php
AstCodec::schema();
// ['heading' => ['fields' => ['level'], 'required' => []],
//  'mention' => ['fields' => ['cssClass', ...], 'required' => ['cssClass', 'destination', 'title']], ...]
```

Five types currently have required fields: `abbreviation`, `citation_group`,
`heading_ref`, `inline_extension`, `mention`.

## Application node types

Extensions and applications define their own node classes. Register them so the
decoder can build them:

```php
AstCodec::register(MyApp\Carve\CalculationBlock::class);
```

Encoding needs no registration (the node reports its own type); only decoding
does. An unregistered type fails loudly rather than silently dropping content.

## Guarantees and limits

- **Round-trip:** every document in the spec corpus survives encode plus decode
  with byte-identical HTML. `AstCodecTest` asserts this over the whole corpus, so
  it is a standing gate rather than a claim.
- **Field names match the spec; positions do not exist yet.** Running the spec
  repo's `npm run ast:check` against `bin/carve --json` reports **23 findings over
  12 documents, every one of them a missing `pos`** - down from 48, with the
  shape findings gone. PART 12 §4 requires `pos` on every node but the root and
  anticipates this state exactly, calling it "a scheduling note": carve-php's
  nodes carry no positions, so the codec has none to emit, and §4 forbids
  inventing them. It also forbids omitting them *silently*, so `--json` writes a
  note to stderr saying the output is not conformant. Tracked in
  [carve-php#478](https://github.com/markup-carve/carve-php/issues/478); position
  tracking is a parser change, not a codec one.
- **A foreign tree is rejected, not decoded wrongly.** The decoder re-encodes what
  it built and compares against the input, so a field it did not understand is an
  error naming the field rather than silent loss. This replaces a real failure:
  a carve-js tree of `Text with *bold*.` used to render `<p><strong></strong></p>`
  and exit 0, because carve-js writes `value` where this codec read `content` and
  the missing field defaulted to empty. Keys this engine cannot produce - `pos` -
  are ignored, so a conformant tree from another engine is still accepted.
- **Not a security boundary.** Decoding builds a tree from whatever it is given;
  treat decoded input exactly like parsed input and apply `SafeMode` and
  `Profile` when rendering. See [security.md](security.md).
