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
  "ast": 1,
  "type": "document",
  "children": [ ... ]
}
```

Any node:

```json
{
  "type": "heading",
  "level": 2,
  "attrs": {"id": "title"},
  "children": [{"type": "text", "content": "Title"}]
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
7. **`ast`** is the encoding version, on the root only. A decoder rejects a
   version it does not know rather than guessing.

## Field names are the contract

Field names come from the node class's declared properties, which is what keeps
the codec complete: a new node type is encodable the day it is added, with no
table to update and no chance of forgetting one.

> They are the contract for *this* codec, not across implementations. The spec
> pins a different set - see the PART 12 bullet under
> [Guarantees and limits](#guarantees-and-limits) before treating this output as
> portable.

The trade-off is that renaming a property changes the wire format. That is pinned
by `tests/fixtures/ast-schema.json` plus `AstCodecSchemaTest`: the full
type-to-fields map is a golden file, so a rename fails CI and has to be either
reverted or accepted with a deliberate `AstCodec::VERSION` bump.

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
- **The spec now pins a shape, and this encoding is not it.** PART 12 of
  `resources/grammar.ebnf` is normative: field names are spec surface and are
  carve-js's, every node except the document root carries `pos`, and an
  implementation whose internals differ is required to map on the way out rather
  than export them. This codec derives field names from node properties, so it
  exports `content` where the reference says `value` and `destination` where the
  reference says `href`, and it emits no `pos` at all. Running the spec repo's
  `npm run ast:check` against `bin/carve --json` reports 48 findings over 12
  documents. Tracked in
  [carve-php#476](https://github.com/markup-carve/carve-php/issues/476) - do not
  treat this output as portable between implementations until that closes.
  Round-trip within carve-php is unaffected.
- **A foreign tree decodes wrongly rather than failing.** Unrecognized keys are
  ignored and `text.content` has a default, so feeding `--from-json` a carve-js
  tree of `Text with *bold*.` renders `<p><strong></strong></p>` and exits 0: the
  text is gone because carve-js writes `value`, not `content`. Until #476 closes,
  only pass this decoder trees that this codec produced.
  Until it closes, the cross-implementation goal behind
  [markup-carve/carve#386](https://github.com/markup-carve/carve/issues/386) -
  carve-js and carve-rs reading the same JSON, and the corpus asserting AST
  equality rather than only HTML equality - is blocked on this encoding, not on
  the spec.
- **Source positions are not included.** PART 12 §4 requires them and anticipates
  this: carve-php's nodes do not carry positions, so the codec has none to emit.
  §4 also says an implementation in that state "MUST NOT emit `pos` with invented
  values, and MUST NOT omit it silently" - hence this bullet. The
  `data-source-line` tier stays a separate rendering concern.
- **Not a security boundary.** Decoding builds a tree from whatever it is given;
  treat decoded input exactly like parsed input and apply `SafeMode` and
  `Profile` when rendering. See [security.md](security.md).
