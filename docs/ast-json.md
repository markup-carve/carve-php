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
ProseMirror/Tiptap serializer existed only in JavaScript, why `HtmlToCarve` has to
be as large as it is, and why an editor bridge was a design project rather than a
mapping. The tree is what editors, linters, structural diffing and
cross-implementation conformance want.

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
Payloads stored under the older root form are no longer accepted. Applications
that still hold them must run `StoredPayloadUpgrade::upgrade()` or
`upgradeJson()` with a pre-removal carve-php release before upgrading.

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
| a list's `children` | `items` |
| a table's / row's `children` | `rows` / `cells` |

Three kinds of difference exist, and only the first is a rename. Containers
publish their children under another key. Derived state converts in both
directions: `ordered` is a boolean over an internal `listType` string, `checked`
comes from a task marker, a cell's `header` from its flag. A task item's raw
marker stays internal - it holds `X` and the default `[ ]`, neither of which the
wire spells - and what the author chose rides as `taskState` beside `checked`.

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
//  'citation' => ['fields' => ['key', ...], 'required' => ['key', 'suppressAuthor']], ...]
```

Six types currently have required fields: `abbreviation`, `citation`,
`citation_group`, `heading_ref`, `inline_extension`, `ruby`.

Ruby annotations use ordered `pairs`. Each pair has a nonempty `base` inline
array and an `annotation` inline array, which may be empty. HTML and Markdown
render ruby with `<ruby>`, `<rt>`, and generated `<rp>` elements. Carve, plain
text, and ANSI render each pair as `base(annotation)` and report one
`ruby-flattened` loss per ruby node. The CLI accepts `--allow-loss ruby-flattened`
when that fallback is intentional.

## Interchange-only shapes

Some shapes have no Carve 0.1 source spelling. No parse produces them; they
arrive from a format bridge, an importer or an editing API, and a canonical
Carve writer cannot spell them back.

- `small_caps` (PART 12 §28) wraps inline `children`. HTML and Markdown write
  `<span class="smallcaps">`, merging the node's other attributes into the class
  slot where the author put it. Plain text and ANSI write the children without
  touching their letter case. The Carve writer drops the wrapper and keeps
  `attrs` on an ordinary attributed span, so `[Nato]{#n .org}` comes back out.
- `section` (PART 12 §30) wraps the blocks it encloses and may carry `level`,
  the heading level the SOURCE FORMAT stated - not what the nesting implies. An
  importer reading HTML5 `<section>`, JATS `sec` or DocBook keeps its nesting
  through the codec. HTML renders the explicit wrapper. The Carve writer
  flattens it back to its headings and reports `structure-unspellable`.
- `table_cell.blocks` (PART 12 §27) holds block content in place of inline
  `children`. HTML renders those blocks in the cell. Carve, Markdown, plain
  text and ANSI flatten them to one line; the Carve writer reports
  `field-unspellable` for `blocks`.
- `line_block.lines` (PART 12 §36) holds line-end JSON Pointers for each stanza.
  It preserves a boundary inside an inline run without copying or splitting
  that run.
- `math.label` and `math.number` (PART 12 §29) carry a display equation's
  authored numbering prefix and the number resolution assigns beside it. A
  number needs a label and a `display: true` node; either without the other is
  refused. Nothing in this engine assigns a number yet: no Carve source spells
  a label, so PART 9R R5a has nothing to count.
- `block_extension` (PART 12 §33) carries a globally qualified `name`, a
  REQUIRED `fallback` block, and optionally `version` and `payload`. The
  fallback is what the document means to a reader that does not implement the
  extension, so every target renders it and the ProseMirror bridge puts it in
  the node's place and reports the substitution. The fallback is also the node's
  single child, so a walk over the tree reaches it without knowing the type.
  `payload` is opaque: a `type` key inside `payload.value` is data, and no core
  target renders any of it.

Named `:::` containers for `bibliography`, `footnotes`, `glossary`, `index`,
`references` and `toc` publish `directive` (PART 12 §35). These are source
spelled; an unknown named kind publishes `admonition`.

To collect source conversion diagnostics, call
`CarveRenderer::beginConversionDiagnosticCollection($maximum)` before rendering
and `finishConversionDiagnosticCollection()` afterwards. The report carries
`diagnostics`, `totalDiagnostics` and `truncated`, following the spec's
`conversion-diagnostics.schema.json`. The CLI writes the same report with
`--carve --report-conversion-diagnostics FILE`. It is separate from render
losses.

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
contract rather than a description of one. The standalone citation exception
below remains after schema validation.

Two things it deliberately does not do. A registered application node type (see
below) and its subtree are outside the schema by construction, so the rule has
nothing to say about them. And a `srcByteLength` that is present but WRONG stays
accepted - it is derivable, nothing in the tree depends on it, and §12(a) is
about presence while (d) is about type and sign.

### A citation is only ever an item of a group

`AstCodec::decode()` and `decodeJson()` refuse a bare `citation` node with
`Standalone citation nodes are not supported`. A group containing the item
decodes and round trips.

That is the language's rule rather than a limit of this engine. CARVE-P12-059
states that a citation occurs in `citation_group.items` and nowhere else: the
inline dispatch does not name it, no source can spell one, and no clause defines
what a bare citation would render to. All three engines refuse the payload at
decode
([carve#2229](https://github.com/markup-carve/carve/pull/2229),
[carve-js#1976](https://github.com/markup-carve/carve-js/pull/1976)).

PHP represents parsed citations as item maps inside `citation_group.items`, so
the item is not an independently constructible node here either.

The schema copy under `tests/spec` still lists `citation` in `inlineNode` until
the pin moves past that ruling
([carve-php#2254](https://github.com/markup-carve/carve-php/pull/2254)); the
refusal above does not depend on it.

## What an ingest replaces

One thing is rewritten rather than refused: **every U+0000 in a string value
becomes U+FFFD**, before the value is read for anything else. That is PART 12
§21, and it is what the parser already does to Carve source (PART 0 INPUT),
which is why PART 9 §29 leaves the character out of the content class. An AST is
a second door into the same renderers, so an ingested document renders like the
same document written as source.

It is not a repair, which is why it does not join the refusal list above. §11
and §12 refuse structure a producer got wrong; this is the replacement the parse
boundary already performs on the identical string, and refusing would make an
ingested document stricter than the same document written as source.

The subject is the **decoded value**. RFC 8259 forbids an unescaped U+0000
inside a string, so a raw control byte in JSON text is still a
`JsonException: Control character error` from `decodeJson()` - unchanged, and
not a Carve rule. What the clause reaches is the `\u0000` escape and the value
a host hands to `AstCodec::decode()`, which takes an array and has no JSON layer
at all.

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

  Comparing HTML alone is not enough: it passed while three constructs were
  being corrupted. An autolink decoded as a plain
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
  values, so a node the parser cannot place accurately carries none. Two
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

  `bin/carve --json` writes no conformance note to stderr: positions are part
  of its default output
  ([carve-php#478](https://github.com/markup-carve/carve-php/issues/478)).
- **Abbreviation definitions are nodes.** As in the reference, they are
  `abbreviation_def` nodes among the document's children, placed where they
  were written, so their position is structural in both.
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
