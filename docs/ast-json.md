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
a field the reference does not have. The published schema is available at
<https://markup-carve.github.io/carve/ast-schema.json>.

The document root carries exactly `type`, `children`, and `srcByteLength`.
Document content stays in the tree: leading frontmatter is the first child when
present, and footnote definitions are `footnote` block children of the document.
Payloads stored under the older root form are no longer accepted - see
[Upgrading a stored payload](#upgrading-a-stored-payload).

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
| `footnote_ref.label` | `id` |
| a list's `children` | `items` |
| a table's / row's `children` | `rows` / `cells` |

Three kinds of difference exist, and only the first is a rename. Containers
publish their children under another key. Derived state converts in both
directions: `ordered` is a boolean over an internal `listType` string, `checked`
comes from a task marker, a cell's `header` from its flag.

**Two node types differ by NAME, not by field.** profiles.md is explicit that an
`autolink` is its own type rather than a `link` carrying a flag - "folding it
into `link` loses the authored form, so a round-trip could not restore it" - and
likewise `admonition` versus a `div` carrying a class. This engine models both
as the broader class plus a flag, so the codec publishes the canonical name,
reusing the same distinction `Profile::canonicalTypeOf()` already draws:

| authored | wire type | fields |
|---|---|---|
| `<https://example.com>` | `autolink` | `href`, `text`, no children |
| `::: warning "Tip"` | `admonition` | `kind`, `title`, children are the body |

Internal fields the reference has no counterpart for are **not** exported (§3) -
a div's raw header string, a row's `isHeader`, a fence's width. Each is listed in
`ReferenceShape::INTERNAL_ONLY` so the omission is a decision rather than an
oversight, and each must be *recomputable*, or the §6 round trip would break.
That constraint is what the corpus gate enforces.

Deciding what belongs on that list is a question about the reference, not about
this engine: a code block's `header` looks internal but the reference keeps it,
because it is what tells a title written inside the fence apart from one written
on an attribute line above it - both land in `attrs.title`. A div's `header` has
no counterpart and is recomputed from its title nodes.

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

## What an ingest refuses

Decoding validates the **whole payload** against the AST schema
(`resources/ast-schema.json`, vendored from the spec repo) - types and required
fields together, before anything is built. A payload that does not satisfy it is
refused with `AstDecodeException`; nothing is defaulted, dropped, or
reinterpreted. This is PART 12 §12(d), ruled on
[carve#881](https://github.com/markup-carve/carve/issues/881).

What that turns from accepted into refused:

- a root `srcByteLength` that is not a non-negative integer
- a root `children` that is not an array, including `null` - a reader that
  supplies a default has turned a truncated document into an empty one
- a node missing a field the schema requires (`text` without `value`, a
  `paragraph` without `children`, a `pos` without `endOffset`)
- a field of the wrong type: `"value": 7` used to render `<p>7</p>`
- a child that is `null` or a string, which used to surface as a bare PHP
  `TypeError`
- `"attrs": {"class": "x"}` - the rendered HTML calls it `class`, the wire shape
  calls it `classes`, and the schema names only the second
- a `type` outside the vocabulary
- the five spellings that predate PART 12 §7, and the two node types this
  package used to encode that the vocabulary has never held - see below

If you produce Carve AST JSON, validate against `resources/ast-schema.json`
before sending it. Every future addition to the schema is a potential rejection
for a producer that has not caught up; that is what makes the schema the
contract rather than a description of one.

Two things it deliberately does not do. A registered application node type (see
below) and its subtree are outside the schema by construction, so the rule has
nothing to say about them. And a `srcByteLength` that is present but WRONG stays
accepted - it is derivable, nothing in the tree depends on it, and §12(a) is
about presence while (d) is about type and sign.

## Upgrading a stored payload

Five payload shapes this package used to read no longer decode. They predate
PART 12 §7, neither carve-js nor carve-rs ever accepted them, and normalizing
them on every ingest meant reasoning about every future schema addition twice,
once for each shape.

Two more shapes never decoded at all: `caption` and `section` are node types
this package used to ENCODE and the vocabulary has never held, so a payload
carrying one was refused by the engine that wrote it. They are off the wire now,
and the same helper converts them.

What each becomes:

| Stored shape | PART 12 §7 shape |
| --- | --- |
| a root `abbreviations` map, with `abbreviationsBeforeBody` | `abbreviation_def` block nodes, before the body or after it as the flag said |
| a root `frontmatter` object | a leading `frontmatter` block node |
| a root `footnoteDefs` map | trailing `footnote` block nodes, one per label |
| a `footnote` node keyed `id` | the same node keyed `label` |
| a `raw_text` node | the `text` node the encoder already published it as |
| a `caption` node | the `paragraph` it holds inline content as |
| a `section` node | the `div` it wraps blocks as |

Convert each stored payload **once**, with the helper that ships alongside the
removal. It works on the payload alone, so an application that no longer has the
Carve source a payload was parsed from can still migrate:

```php
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;

$payload = StoredPayloadUpgrade::upgrade(json_decode($stored, true));
$document = (new AstCodec())->decode($payload);
```

or, staying in JSON:

```php
$stored = StoredPayloadUpgrade::upgradeJson($stored);
```

`upgradeJson()` returns a payload that needs no conversion **unchanged**, byte
for byte - PHP reads an empty JSON object and an empty array as the same value,
so re-encoding one that needed nothing would rewrite `"attrs": {}` as
`"attrs": []`. A payload that does need converting is re-encoded, and an empty
JSON object in it is written as `[]`; decode it yourself and call `upgrade()` if
that distinction matters to a consumer of yours.

It is idempotent and leaves a payload already in the §7 shape untouched, so it
is safe to run over a whole store, and safe to run again if a migration is
interrupted. Decoding a payload that still carries one of these reports which
spelling it found and names this helper. An application node type registered
with `AstCodec::register()` is left alone, subtree included - register the class
before running the migration, or its own fields are read as nodes.

One caveat, and only one. `raw_text` existed so the writer could reproduce
markup the parser declined, verbatim; it becomes a `text` node, and a `text`
node is escaped on the way back out. That is not a loss the upgrade introduces -
the node was already off the wire, so the second save of such a document
produced the same `text` node. The upgrade brings it forward by one hop.

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
  with byte-identical HTML **and** byte-identical Carve source. `AstCodecTest`
  asserts both over the whole corpus, so it is a standing gate rather than a
  claim.

  Comparing HTML alone is not enough, and that is not theoretical - it passed
  while three constructs were being corrupted. An autolink decoded as a plain
  link renders the same HTML but writes back as `[url](url)`. A task list
  decoded as a bullet list renders the same checkboxes, because the item marker
  drives them, but writes back without `[x]`. A titled admonition rendered the
  same `<aside>` while losing its title. §6 is about the authored form, so the
  Carve renderer is the stricter surface.
- **Source positions are recorded and serialized.** `bin/carve --json` emits
  `pos` on every node it can place, with no flag. In library use, opt in with
  `new BlockParser(trackPositions: true)` and read `Node::getPos()`, which
  returns a `SourceSpan` (all six PART 12 §4 fields) or `null`.

  Null is a real answer, not a gap: §4 forbids emitting a span with invented
  values, so a node the parser cannot place honestly carries none. Two
  invariants are enforced over the whole corpus - a text node's span selects
  exactly its own bytes, and a child's span never falls outside its parent's.

  Offsets and columns count **codepoints**, per §4 - slice with `mb_substr()`,
  not `substr()`.

  MEASURED, WITH ITS PROVENANCE, because a percentage carrying no date reads
  exactly like a fresh one. On **2026-08-18**, over the spec corpus at carve
  [`9616bdc0`](https://github.com/markup-carve/carve/commit/9616bdc0) - 1268
  documents - with carve-php `f30ebd1`: **8562 of 8617 nodes below the root
  carry a `pos`, 99.4%**. The 55 without are 26 `table_cell`, 26 `text` and 3
  `code`, and every one of them falls in a category §4 EXEMPTS - a coalesced
  text run, a reassembled table cell, a verbatim run continued on a `+` line.
  carve-rs `a33c42a` leaves the same 55 over the same corpus, node for node.

  So `pos` is not "present or absent" any more: it is present except where §4
  forbids inventing it. Re-take the measurement rather than trusting this
  paragraph once the date above has aged - the corpus grows by whole categories
  in a day, and a number that was true when written is exactly what makes a
  stale claim look verified.
- **Field names match the spec, and every position finding is one §4 permits.**
  The spec repo's `npm run ast:check` drives `bin/carve --json` over the same
  corpus and reports **57 findings, 5 distinct: 55 waived, 0 outstanding, 2 not
  a position**. The 55 are the §4-exempt categories above, each recorded as
  permitted in the spec repo's `resources/ast-position-waivers.txt`, and
  carve-rs `a33c42a` reports the identical 57 over the same corpus. The other 2
  are TREE findings rather than position ones, on the two documents corpus
  category 367 added the same day: an unterminated fence at a container's
  content column opens no block
  ([carve#1387](https://github.com/markup-carve/carve/issues/1387)), and this
  engine has not landed that yet.

  This bullet used to say the opposite - "positions do not exist yet",
  "carve-php's nodes carry no positions", and a stderr note warning that the
  output was not conformant. Each was true when written; none outlived
  [carve-php#478](https://github.com/markup-carve/carve-php/issues/478), which
  is closed. The bullet above it already said positions were recorded, so the
  page contradicted itself in two adjacent paragraphs
  ([carve#1323](https://github.com/markup-carve/carve/issues/1323)).
- **Abbreviation definitions are nodes.** They used to live in an
  `abbreviations` field on the document here, where the reference emits
  `abbreviation_def` nodes among the document's children. They are nodes here
  too now, placed where they were written, so their position is structural in
  both.
- **A foreign tree is rejected, not decoded wrongly.** The decoder re-encodes what
  it built and compares against the input, so a field it did not understand is an
  error naming the field rather than silent loss. Every such refusal throws
  `MarkupCarve\Carve\Exception\AstDecodeException`, which is what PART 12 §9(b)
  and §11 mean by "a typed, documented failure" - catch that to handle "this
  payload is not a Carve AST" without also catching a bug in your own code. It
  extends `RuntimeException`, so existing catches keep working. This replaces a real failure:
  a carve-js tree of `Text with *bold*.` used to render `<p><strong></strong></p>`
  and exit 0, because carve-js writes `value` where this codec read `content` and
  the missing field defaulted to empty. Keys this engine cannot produce - `pos` -
  are ignored, so a conformant tree from another engine is still accepted.
- **Not a security boundary.** Decoding builds a tree from whatever it is given;
  treat decoded input exactly like parsed input and apply `SafeMode` and
  `Profile` when rendering. See [security.md](security.md).
