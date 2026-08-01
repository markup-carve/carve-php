# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Block-position inline nodes from ProseMirror no longer disappear from
  Carve source.** Tiptap's image extension puts images at document level, and a
  custom editor can do the same with any inline node. The bridge accepted that
  shape directly, leaving an inline as a child of a block container - a tree the
  Carve parser cannot produce. HTML still rendered it, but the canonical source
  writer has no block form for a bare inline, so the content came back empty
  with no dropped or degraded type reported.

  ProseMirror input now wraps each adjacent run of block-position inlines in one
  paragraph before appending it to a document, block quote, list item, or other
  block container. Source-first corpus sweeps could not construct this editor
  shape, which is why it went unnoticed.

- **Frontmatter is no longer claimed after a leading blank line.** The parser
  matched on "first block of `Document`", but a blank line yields no child node,
  so `\n---\n\n---\n` was read as an empty frontmatter fence and the whole
  document rendered to an empty string. `grammar.ebnf` pins
  `document = [frontmatter], {block}, EOF`, so nothing may precede the opener
  except the block-attribute line this engine already attaches to the node.
  carve-js renders the same input as two thematic breaks.

- **`MarkdownToCarve` preserves leading frontmatter instead of destroying it.**
  The converter had no frontmatter rule, so the opening `---` was migrated as a
  thematic break and the closing one as a setext underline: a page opening with
  `title:` / `description:` came back as a rule, a paragraph, and an `##`
  heading, with the metadata gone. Frontmatter now passes through byte-for-byte
  and only the body is converted. The fence is recognized with the parser's own
  open/close rules, including the format label (`---toml`, `--- toml`), and must
  enclose at least one non-blank line.

- **A rule line is no longer consumed as setext heading text on migration.**
  `MarkdownToCarve` turned `---\n---` into `## ---`, which then rendered as an
  `<h2>` whose title smart typography collapsed to an em dash. CommonMark reads
  `***\n---` as two thematic breaks, not a heading, and carve-js has pinned that
  since its own migrator landed.

- **A migrated document opening with a rule no longer vanishes.**
  `MarkdownToCarve` emitted `---\n\n---` unguarded, which Carve reads as an
  empty frontmatter fence. A leading blank now keeps line 0 off `---` so every
  rule stays a rule, matching carve-js.

- **The ProseMirror bridge reports state the editor model cannot hold.** A type
  can map cleanly and still lose something: the NODE survives, one of its fields
  does not. Those losses appeared in neither `droppedTypes()` nor
  `degradedTypes()` - nothing was dropped, and nothing degraded to text - so a
  caller storing documents had no way to find out. Now reported:

  - `autolink` - `<https://example.com>` is a plain link mark in the editor
    model, so it comes back written as `[text](url)`.
  - `link` - a link with an empty label has no text to carry the mark, so it is
    not represented at all.
  - `code` - inline code is a mark; its attributes have nowhere to live.
  - `list` - an alphabetic or roman style comes back numbered, and a marker
    character (`*`, `)`) comes back canonical.

  `MINIMUM_LOSSLESS` in `ProseMirrorCorpusTest` falls from 336 to 329 as a
  result. Those seven documents did not start round-tripping worse; they stopped
  being counted as intact while quietly losing their authored form.

- **The ProseMirror bridge carries four fields the editor schema already
  declared.** Each was left unset on this side, so the value had nowhere to
  live in the editor model and vanished on the way back:

  - **A container's title.** `::: tip "Pro Tip"` came back as `{.tip}` plus a
    bare fence, with the heading gone - content, not spelling. An empty title
    is kept distinct from a missing one, since `::: note ""` suppresses the
    default heading.
  - **A container's typed opener.** `carveDiv` carries a class, not a
    spelling, so a div is now marked typed on the same condition the parser
    uses - which is what carve-grammars' own serializer does with the same
    node. A div authored as `{.custom}` with a single class therefore
    normalizes to `::: custom`; one carrying several classes cannot be spelled
    that way and keeps the attribute block.
  - **An abbreviation's expansion.** The `carveAbbreviation` mark had no
    `title`, so `*[HTML]: HyperText Markup Language` was lost and every
    expansion in the document stopped working.
  - **A semantic span's name.** `:kbd[x]` came back as `:[x]`, which is not
    valid Carve. The schema's `carveSource` exists for exactly this.

  Across the spec example corpus this takes round-trip losses the fidelity
  report does not declare from 55 documents to 32.

- **A footnote keeps its label across the ProseMirror bridge.** Neither
  `carveFootnote` nor `carveFootnoteDefinition` carried the label, so every
  reference and definition reached the editor anonymous: a document with three
  footnotes came back as `See[^], again[^] and[^].` with definitions to match,
  and two references to the same note became indistinguishable from two
  references to different ones.

  The editor node has always declared the attribute - only this side left it
  unset. It is now emitted and read back, so a document with footnotes round
  trips byte-identically instead of losing every binding.

- **The canonical writer stops emitting a PHP 8.5 deprecation.**
  `canonicalizeAst()` called `ReflectionProperty::setAccessible(true)` before
  reading each property. That method has done nothing since PHP 8.1 - reflection
  reads non-public properties without it - and PHP 8.5 deprecates the call
  itself, so every `CarveRenderer` render raised a deprecation on 8.5 while the
  package's own floor is 8.2. The call is removed rather than guarded: there is
  no supported version where it has an effect.

- **The CLI documentation stops describing source positions as missing.**
  The README said carve-php's nodes carry none and that `--json` writes a note
  to stderr saying so. Both stopped being true when `--json` began publishing
  positions; the note is gone and the output is PART 12 §4 conformant.

- **An authored attribute no longer becomes a link's destination in the
  ProseMirror bridge.** Attributes were merged over the structural ones rather
  than around them, so `[safe](https://example.com){href=javascript:steal}`
  reached the editor as a link whose `href` was the authored attribute, not the
  destination the document has. Writing that model back out produced
  `[safe](javascript:steal)` - the round trip rewrote the destination.

  The HTML target has always refused that promotion (it renders
  `href="https://example.com"` and drops the shadowed attribute), so the bridge
  was the one place where an attribute could displace the syntax that owns the
  value. carve-php itself never emitted a live `javascript:` href - the URL
  policy blanks it on the way out - but a consumer rendering the editor model
  directly gets whatever the mark carries.

  Structural values now win for every node that has them (`href`, `src`,
  `title`, `alt`, `colspan`, `rowspan`, `alignment`, `display`, `label`), and a
  shadowed author attribute is dropped exactly as the HTML target drops it.
  Attributes that collide with nothing still reach the editor, so an
  application node keeps its own.

- **A profile that denies nothing now changes nothing, across every corpus
  document.** `ProfileFilter` ran `cleanupEmptyContainers()` as a blanket pass
  over the whole tree, so it pruned containers that were already empty in the
  SOURCE and not only ones the filter had emptied. Six documents rendered
  differently under `Profile::full()`, and the pruned container differed in each
  - an empty `<blockquote>`, an `<aside>`, a `<ul><li>`, and in one case the
  entire rendered output.

  Cleanup is now scoped to the parents the filter actually removed a child from,
  cascading upward exactly as before. A genuinely emptied container is still
  pruned, which matches carve-js: it renders an emptied blockquote as `""` and
  leaves an already-empty container alone.

  This is the general form of the `::: footnotes` bug a structural exemption
  fixed separately: that directive's body is empty BY DEFINITION - its emptiness
  is the syntax for "put the endnotes here" - so it could never survive a pass
  that treats empty as meaningless, and neither could the other six.

  `KNOWN_LOSSY_UNDER_A_FULL_PROFILE` in `ProfileVocabularyTest` is now EMPTY.

- **A profile classifies a div as an admonition because it carries a Tier-1
  class, not because it was opened with a type word.** `::: sidebar` is a
  generic container and now classifies as `div`; `::: note` still classifies as
  `admonition`. The predicate is the one the renderer already used, so
  classification and rendering agree by construction instead of being two rules
  kept in sync by hand - no rendered output changes.

  **Migration:** `denyBlock(['admonition'])` previously stripped *every* named
  div and now strips only Tier-1 callouts (`note`, `tip`, `warning`, `danger`,
  `info`, `success`, `example`, `quote`). To preserve the old behavior exactly,
  deny both `admonition` and `div`. `denyBlock(['div'])` still catches callouts
  through the supertype rule.

- **`carve --json` publishes source positions, so the AST output is PART 12 §4
  conformant.** The machinery landed first and nothing turned it on:
  `trackPositions` defaults to false and no caller passed true, so the code to
  produce positions sat unused while the output carried none and said so. The
  AST format now asks for them; the other formats do not, since tracking costs
  work on every parse and only this one publishes the result (carve-php#478).
- **A heading always publishes its `level`.** Level 1 is this engine's property
  default, so the encoder dropped it - making `# H` and `## H` differ in field
  SET rather than in value, and leaving a consumer to treat the field as
  optional and guess.

### Added

- **Source positions on AST nodes (PART 12 §4), opt-in.**
  `new BlockParser(trackPositions: true)` records a `SourceSpan` on each node,
  read with `Node::getPos()`, and the codec emits it as `pos`. All six fields
  are present or the span is `null` - §4 forbids inventing one, so a node the
  parser cannot place honestly carries none, and `--json` prints a note saying
  the output is not yet conformant rather than omitting silently. Columns and
  offsets count Unicode codepoints (§4), converted once per document from the
  bytes the parser measures. Coverage is ~99% of corpus nodes; the remainder is
  text the parser rewrote, where no span can equal the node's content.

### Fixed

- **A carve-js tree decodes.** carve-js keeps footnote definitions in a
  root-level `footnoteDefs` map rather than as block nodes in `children`, so the
  map was a field this decoder does not produce and the loss check refused the
  payload outright - any carve-js document containing a footnote could not be
  read here at all. The map is now adopted into the block nodes this engine
  uses. Which representation is canonical is still open (carve#408); this only
  makes the exchange work either way.

### Fixed

- **An unresolved footnote reference keeps a trailing attribute block.** It
  became a Text node with the attributes consumed and discarded, so
  `Text[^a]{.ref}.` lost `{.ref}` from the tree entirely and the canonical
  writer emitted `Text[^a].` where carve-js and carve-rs emit
  `Text[^a]{.ref}.`. It is now still a `footnote_ref`, marked unresolved, and
  every target renders it as the literal source exactly as before - only the
  `carve` target's output changes, and it changes to match the other two
  engines. This was the last of the 98 cross-engine divergences in carve#352.

### Changed

- **BREAKING (AST wire): a footnote reference encodes its label as `id`, and an
  inline footnote's body as `inline`.** The rename table keyed `label` -> `id`
  on `footnote`, which is the BLOCK definition, so it never applied to the
  inline `footnote_ref` and the wire carried `label`. An inline footnote's body
  encoded as `children`, where the reference calls it `inline` - it is the
  note's content, not nested structure. Both now match carve-js field for
  field (carve#405).

### Fixed

- **A link label's closing `]` is found past an editorial comment.** The scan
  already skipped code spans, because a `]` inside one is content. An editorial
  comment holds literal content too and was not skipped, so `[{#a]b#}](u)`
  ended the label at the comment's bracket and formed no link - with no
  spelling that worked, since `{# ... #}` resolves no escapes and `\]` puts a
  real backslash in the comment (carve#403).

### Fixed

- **A link reference definition's destination is trimmed of Unicode
  whitespace.** `trim()` only knows ASCII, so
  `[a]: <U+202F>javascript:alert(1)` kept the narrow no-break space in the
  destination. HTML hid it - the scheme probe strips Unicode whitespace to see
  `javascript:` and blanks the href either way - but the ANSI target prints the
  destination to a terminal, where an invisible character is the spoofing shape
  the probe exists to catch. Only the ends are trimmed: whitespace inside the
  destination is part of it. Zero-width characters are not whitespace and stay,
  matching carve#352, carve#404.

### Changed

- **BREAKING (AST): an editorial comment is now a `critic_comment` node.** It
  was a `span` carrying a `critic-comment` class, so this engine's tree
  disagreed with the reference for the same document and nothing keyed by node
  type - a profile, a schema bridge - could name it. It is its own type for the
  reason an autolink is not a link: the two are written differently and a
  formatter has to reproduce which one the author used
  (markup-carve/carve#401). The encoded field is `text`, matching the
  reference.

  Rendered output does not change on any target. HTML still emits
  `<span class="critic-comment">`, which is user-visible styling that
  stylesheets and syntax themes select on, so it does not follow the AST
  vocabulary. Markdown, plain, ANSI and Carve output are byte-identical.

  The ProseMirror bridge gains a `carveCriticComment` mark, joining
  `carveInsert` and `carveDelete`. Without it the node had no mapping and its
  text would have been dropped from the editor model.

  One behavior does change, and removes a divergence: an editorial comment no
  longer contributes to a generated heading id. `# Title {#note#} tail`
  produced `Title-note-tail` here and `Title-tail` in the reference, because the
  slugger walked into the span and folded the commentary into the slug. An
  aside is not part of the heading, so the reference is right.

### Fixed

- **A full profile no longer drops a substitution.** `Profile::full()` denied
  `{~old~>new~}` and rendered it as nothing, losing both texts, because
  `substitution` was never registered in the profile vocabulary and an
  unregistered type is denied rather than allowed. Found while registering
  `critic_comment`, which needed the same entry. Two corpus documents render
  correctly under a full profile that did not before.

### Fixed

- **A document full of comment-fence openers with distinct widths no longer
  rescans itself per opener.** The closer lookahead added with the comment-fence
  tail fix (#471) scanned to the end of the line set for every `%%%` opener.
  Where every opener carries a different width no line can close any other, so
  every scan ran the whole way. It is now answered from a fence width to
  last-index map built in one pass. A closer must match the opener width exactly,
  so any later line of that width IS a valid closer, which makes the map exact
  rather than an approximation.

  The per-width negative cache that shipped with #471 is removed: its hit
  condition is a second opener of the same width after a proven-no-closer point,
  and a second line of the same width is itself the closer for the first, so the
  state is unreachable. Those lines were the entire `codecov/patch` gap on #471 -
  coverage was pointing at unreachable code, not at a missing test.

  The perf test guarding it repeated a single width, where line two simply closes
  line one, so it never reached the lookahead at all and passed regardless of what
  that code did. The replacement uses distinct widths, measures cost per byte, and
  fails against the old scan. Three tests for the block-quote lookahead paths
  cover what the removed branches left untested.

- **A `%%%` comment opener with trailing text no longer leaks the comment body
  and drops the next block.** `%%% html` and `%%% notes` were not accepted as
  fence lines, so the `%%` line-comment rule ate the opener, the body rendered
  as an ordinary paragraph, and the following `%%%` opened an unterminated block
  that swallowed the rest of the document. A comment fence is now a delimiter
  plus an insignificant tail: only the leading run of `%` is structural, so
  `%%% TODO` opens and `%%% end` closes. Percent fences carry no info string - a
  raw block is a code fence with `=FORMAT` - so `%%% html` is a comment and its
  body stays hidden.

  An opener with no matching closer ahead now opens nothing and degrades to a
  line comment, so following blocks still render. The closer also matches on
  **exact** delimiter length now: `%%%%` no longer closes a `%%%` block, which is
  what PART 9 section 2 always required and what carve-js does. The opener's
  tail is kept as the body's first line so the writer round-trips it; a closer's
  tail is dropped (#463, PART 9 §28).

- **`DjotToCarve`, `HtmlToCarve` and `BbcodeToCarve` no longer turn plain input
  text into Carve markup either.** The same defect the Markdown converter had
  below: `a {,y,} b` came out as a subscript through all three, and `a %%c%% b`
  lost its text entirely because `%%` opens a comment. The escaper is now a
  shared `EscapesCarveConstructs` trait, with a per-caller opt-out for
  delimiters the source language owns - Djot keeps `~x~` and `^x^`, which it
  converts to a subscript and a superscript, and the braced `=`/`+`/`-` forms it
  carries verbatim. HTML escapes text nodes only, never a generated construct,
  an attribute, a URL or `pre`/`code` content; BBCode escapes plain text with
  tags and their parameters protected, so `[color=red]` and `[url=…]` are
  untouched.
- **`MarkdownToCarve` no longer turns plain Markdown text into Carve markup.**
  CommonMark defines no `/…/`, `=…=`, single-`~…~`, `{^…^}`, `{,…,}` or `%%…%%`
  syntax, so all of those are literal text in Markdown - and the converter passed
  them through for Carve to parse as markup. `a {,y,} b` came out as a
  subscript, `a /it/ b` as emphasis, and `a %%c%% b` lost the text entirely,
  because `%%` opens a comment. The first delimiter of each construct is now
  escaped, which is the same rule #420 applied to the bare dollar pair: the
  converter must not introduce a construct that was not in the input. Escaping
  happens after code spans, links and URLs are protected and before the
  Markdown rewrites run, so `**b**`, `_em_`, `~~s~~`, `==h==` and the HTML
  inline tags still convert, and `a/b/c`, `1/2`, `x = y`, `~5` and `50%` are
  left alone. Note the behavior change: Markdown that contained Carve inline
  syntax and previously passed through verbatim is now escaped.
- **Tables are written in the native header form** (`=` cells plus per-cell
  alignment markers) instead of a GFM delimiter row, and a line block's indent
  is written back as spaces rather than a literal non-breaking space. Both are
  round-trip fixes: a delimiter row's alignment applies to the whole column
  while alignment belongs to each cell, and a real nbsp re-parses as literal
  text rather than as indentation. Closes carve#359 for this engine; the
  `carve` output is byte-identical to carve-js and carve-rs on every affected
  corpus case.
- **`autolink` and `admonition` are deniable by name** (carve#362). Neither is a
  node class of its own here - an autolink is a `Link` carrying a flag, an
  admonition a `Div` carrying one - so the profile filter matched the broader
  name and a profile naming the narrower one matched nothing, silently: a host
  could deny autolinks, get no error and no violation, and still emit them. The
  canonical name is now resolved from the node rather than its class. Both stay
  covered by the broader name, so denying `link` still strips autolinks and
  denying `div` still strips admonitions.

- **The Markdown renderer no longer de-escapes underscores inside verbatim
  content.** The intraword-underscore cleanup matched a literal `\_` anywhere in
  the assembled document, so a backslash the author wrote was rewritten along
  with the escapes the renderer added: `` `a\_b` `` came back as `` `a_b` ``, and
  the same happened in fenced code blocks, link destinations, image sources and
  escaped raw HTML. Each of those dropped a byte the parser had kept - a code
  span does not process escapes, so its content carries the backslash literally.
  The cleanup now decides on a sentinel only the text escaper emits, so it sees
  exactly the escapes the renderer wrote (carve-js#400).

- **The canonical writer reproduces a line block as a line block** (carve#359).
  It emitted a bare colon fence and tagged the node with a `line-block` class,
  so a formatted line block re-parsed as an ordinary div. The rendered HTML
  matched, which is why nothing caught it, but the node type changed across a
  format round trip: `parse(fmt(x)) == parse(x)` did not hold, and a profile
  denying `line_block` stopped matching after `fmt`.

  The writer now emits the `::: |` opener from the grammar, and drops the
  explicit hard-break backslash inside a line block - the container already
  makes every newline a hard break, so emitting both doubled them.

  All seven line-block corpus cases now round-trip; four did not before.

### Added

- **`MarkdownRenderer::setAttributeFallback()`**: keep attributes Markdown cannot
  spell as raw HTML instead of dropping them.

  The Markdown target degraded an inline mark to a raw `<mark>` but dropped a
  container's and an image's attributes outright, so `{=x=}` survived an export
  while `{#id .class data-*}` did not. Since `::: class` plus an attribute block
  is the only vehicle Carve offers for an application-specific block, "export and
  re-import" was data loss for the one construct that carries application state
  (carve-php#458).

  `AttributeFallback::Html` renders an attributed container as a `<div ...>`
  wrapper - blank lines around a body that is still Markdown - and an attributed
  image as an `<img ...>` tag. An attribute-less container gets no wrapper, and
  the bold title/label lines the renderer already surfaced stay, inside it. A
  `src` / `alt` / `title` attribute the tag already spells is not emitted a
  second time - a duplicate attribute is invalid HTML and the second copy is
  inert.

  `AttributeFallback::Drop` remains the default and its output is byte-identical
  to before, so a consumer rendering divs to Markdown today sees no change.

  The raw HTML is built by the HTML renderer's own attribute code, not a second
  copy: names go through the same validation (`on*` handlers, the `srcdoc` /
  `formaction` sinks, and the identifier check that closed a name-level bypass),
  values through the same hardening (the full URL denylist, CSS `expression(...)`)
  and the same attribute-context escaping. The image `src` gets the denylist the
  Markdown destination already gets - a raw tag is the more direct sink of the
  two, so it cannot be the laxer one.

- **`MarkdownRenderer::setSmartTypography()`**: render smart typography as the
  author's source run instead of the resolved glyph.

  Smart typography is a presentation choice - right for a person reading the
  output, usually wrong for a machine reading it. A corpus searched by a
  language model does not want `...` silently replaced by an ellipsis, and the
  consumer cannot undo the substitution. Now that smart typography is an AST
  node carrying both halves, the renderer can emit either.

  `SmartTypographyMode::Glyph` stays the default, so nothing changes unless a
  caller opts in. Only the Markdown renderer is affected.

  This covers typography only. Escaping - HTML metacharacters and Markdown
  metacharacters - is a separate concern with its own security rationale and is
  untouched: `company_id` still renders as `company\_id` in both modes.

### Added

- **`MarkdownHabitLinter` and a `carve lint` command**: reports Markdown habits
  that parse as valid Carve but render as something the author did not intend.

  Carve diverges from Markdown deliberately, so `**bold**`, `__bold__` and
  `~~struck~~` render as literal punctuation and a heading swallows the line
  beneath it. None of that is an error - the document is valid, it just says
  something else - so `getWarnings()` stays empty and `fmt --check` passes. A
  writer coming from Markdown, or a language model whose training makes the
  Markdown reading the strong prior, gets no signal at all.

  Only forms that are never meaningful Carve are reported. `*x*` and `_x_` are
  deliberately NOT flagged: they are correct Carve for strong and underline, and
  warning on them would punish authors writing the language properly. Verbatim
  spans and fenced blocks are skipped.

  `carve lint [files...]` prints `file:line:column rule message` and exits
  non-zero when anything is found, so it drops into CI or an agent loop.

### Changed

- **`MarkdownToCarve` math conversion is now opt-in**. Plain CommonMark treats
  dollar runs as literal text, so the converter no longer rewrites paired
  dollars by default. Pass `convertMath: true` for Markdown flavours that use
  `$...$` and `$$...$$` as math delimiters.

- **Smart typography is represented as AST nodes instead of character
  substitution** (carve#339). A `SmartPunctuation` inline node carries both the
  resolved kind and the author's source run, so the Carve renderer reproduces
  what was written (`...`, `->`, `--`, `"`) instead of normalizing it to the
  glyph. `fmt` is no longer lossy on smart typography; `to_html(fmt(x))` still
  equals `to_html(x)` and `fmt` stays idempotent.

  Rendered output is unchanged for every other target: HTML, Markdown, plain
  text and ANSI all resolve the node back to the same glyph, verified
  byte-identical against the pinned spec corpus and against a quote matrix
  diffed with the previous implementation. Quote glyphs stay locale-aware -
  they are resolved during parsing, as before, so `SmartQuotesExtension` and
  its locale sets behave exactly as they did.

  Covers all fifteen transforms: the ellipsis, the eleven operators (`<->`,
  `->`, `<-`, `=>`, `!=`, `<=`, `>=`, `+-`, `(c)`, `(r)`, `(tm)`), the em/en
  dash ladder, and the quote directions.

  Parser tests that asserted the old internal shape (a glyph inside the `Text`
  node) now assert the node instead. No test asserting rendered output changed.

### Added

- `DetailsExtension` accepts a `defaultSummary` constructor argument for the
  fallback `<summary>` label of a title-less `::: details` block. The label was
  previously the hardcoded English `Details` with no override, so a non-English
  document had no way to name its own disclosures. The default is unchanged and
  a quoted opener title still wins; the custom label is HTML-escaped.

## [0.1.3] - 2026-07-27

### Added

- Bumped the pinned spec corpus to carve `9c5f53a`, adding conformance coverage
  for categories 143-162: definition-list block openers, strict column-0 rules,
  the dash-run ladder, unresolved footnote-ref attributes, tight-item trailing
  text, indented-literal blocks, and list-looseness pins.
- **SVG `img` fence** (Tier-3, opt-in, off by default) (#382, #392): an
  `` ```img `` block renders a sanitized SVG instead of showing the source.
  Sandbox by default - the sanitized SVG is encoded into a `data:image/svg+xml`
  `<img>` the browser isolates (no script, no fetch, no DOM access); a host may
  opt into a live inline `<svg>` for `currentColor` / CSS theming. When no
  `{alt=…}` is given, the alt text falls back to the SVG's `<title>`.
- **Inline literal** via the `` !`…` `` prefix (#378): a `!` immediately before a
  verbatim backtick span renders its content as escaped prose with no `<code>`
  wrapper, so notation that collides with the bare emphasis delimiters (phonemic
  `/kaet/`, glob patterns, paths) needs no per-character escaping. Mirrors the
  `$`-math prefix; a trailing `{…}` is the ordinary attribute block.
- **PlantUML fenced-render preset**, and the static renderers map is opened so a
  custom `FencedRender` fence word can be made static-capable without a spec
  change (#376).
- **Opt-in source-line tracking** for editor scroll-sync (`sourceLines: true`):
  1-based `data-source-line` anchors stamped on block elements, nested blocks,
  list items and endnote entries (#348, #353, #361, #366).
- An HTML `symbols` render map for trusted `:name:` replacements, wrapping
  attributed symbols in a `<span>` while leaving unmapped symbols literal.

### Changed

- **BREAKING**: the bare `^sup^` and `,sub,` delimiters were dropped -
  superscript and subscript are braced-only (`{^text^}` / `{,text,}`) (#337).
- Symbol parsing aligned with the pinned carve#261 spec: names start with an
  alphanumeric character, allow `+` after the first character, and only open at
  the start of text or after a non-word character. No AST rename was needed
  because carve-php already used `Symbol` (#338).
- The citation group node type is spelled in snake_case, matching the spec
  vocabulary (#383).

### Fixed

- **The formatter (`carve fmt`) no longer loosens a tight list item that has
  more than one child block.** A tight item whose blocks were, for example, a
  paragraph, a fenced code block, and trailing text was emitted with blank lines
  around the inner block, which loosened the item on re-parse so
  `toHtml(fmt(x))` diverged from `toHtml(x)` (corpus category 162). Adjacent
  blocks in a tight item are now joined with a single newline; a nested-list
  child keeps its existing blank separator and indent handling, so an outer item
  wrapping a nested list (category 142) stays byte-stable and idempotent.
- **Trailing text after a closed block in a tight list item now renders bare**
  instead of being wrapped in a spurious `<p>`. In a tight item, text that
  follows a fenced code block, a `:::` div, or an admonition is part of the
  item's inline content and matches the item's tightness - carve-js and the
  executable-spec oracle render it bare (corpus category 162), while carve-php
  wrapped it. A loose item still wraps every paragraph. As a side effect, a
  tight item led by an attributed paragraph now indents its following blocks
  correctly, also matching carve-js.
- **An unresolved footnote reference followed by an attribute block now stays
  literal** instead of forming an inline span. With no matching `[^a]:`
  definition, `Text[^a]{.ref}.` rendered `Text<span class="ref">^a</span>.` -
  the outlier versus carve-js, carve-rs, and the executable-spec oracle, which
  keep the reference literal and drop the orphan attribute (`Text[^a].`). The
  unresolved reference is no longer a valid host, so a trailing `{...}` neither
  attaches nor forms a span (an empty or invalid block still renders literally).
  Resolved references and legitimate bracketed spans are unaffected.
- **A colon-fence opener whose body sits below a list item's content column no
  longer forms a div** (strict content-column rule). `- ::: note\n - x\n :::`
  opens `:::` at the item content column (2), but its body and closer sit at
  column 1, below it; the admonition therefore does not form and the whole run
  is literal text inside the `<li>`, instead of the dedented lines
  reconstructing an admonition. Matches carve-js and the executable-spec oracle.
- **An outer list item that owns an internal blank line before its own attached
  block now renders loose** (carve#322). In `- a\n  - b\n\n   > q` the blank
  precedes `> q`, which is dedented below the nested list's content column and so
  attaches to the OUTER item; that item is loose and wraps its first paragraph
  (`<li><p>a</p>…`). Nested-item looseness still does not propagate to the outer
  item (corpus 142). Matches carve-js and the oracle.
- **A table row (or other block opener) below the content column of an INDENTED
  list item no longer escapes to a document paragraph** (carve#295). The item's
  content column now includes the marker's own indentation (`    1. ` is column
  7, not 3), so a `| x |` at column 2 folds as lazy text instead of ending the
  item. A block opener dedented below an indented marker interrupts only at
  column 0; between column 0 and the content column it is lazy text.
- **Nested verbatim inline spans no longer get their content re-indented**
  (carve#295). A multi-line `<code>`, math, inline-literal or raw-inline span -
  e.g. a fence folded to lazy inline code inside a list item - kept its literal
  newlines flush instead of being padded by the surrounding block indentation,
  matching the carve-js reference. The four verbatim renderers now share one
  newline-guard so they cannot drift apart.
- **A definition at a mismatched indent no longer splits its definition list**
  (carve#295). When a `:  def` line sits at a lower column than its `:: term`,
  the definition still belongs to the def-list instead of stranding as a
  document-level paragraph: a bare `:  def` is not an independent block opener,
  so it never interrupts the item and the whole `<dl>` stays together, matching
  carve-js.
- **Definition lists and tables are first-class block openers in list items**
  (carve#295). A `::` definition-list term now interrupts a list at column 0 and
  nests as a whole `<dl>` at an item's content column, instead of splitting the
  two-line marker across the item and a stray paragraph. A table below an item's
  content column now folds ALL its rows as lazy text rather than folding the
  first row and splitting the rest off as a document-level paragraph. This
  brings carve-php byte-identical to carve-js and carve-rs across the full
  list-continuation matrix.
- **Post-blank list continuation uses the content-column model** (carve#295). A
  block opener (quote, heading, fence, thematic break) or a sublist marker
  belongs to a list item only when it reaches the item's content column (marker
  width + separator: `- ` -> 2, `1. ` -> 3). Below the content column it no
  longer attaches at any indent past the marker (the previous djot-ish
  behavior): after a blank line the item ends and the block parses at document
  level, and with no blank line the line lazily continues the item's paragraph
  as text. Above the content column a block opener folds in as lazy paragraph
  text rather than a real block. Applies to both the blank and no-blank paths;
  the `HtmlToCarve` reverse converter now indents nested lists to the parent's
  content column so round-trips stay stable.
- **Paragraph trailing-whitespace stripping moved to the source layer.** The
  normative rule strips whitespace at the end of a paragraph's final line
  *before rendering* (corpus 102), but carve-php applied it to the rendered
  output. A renderer cannot tell whitespace the author typed from spaces a
  construct legitimately produced, so a paragraph whose entire content was an
  all-space verbatim span was emptied - `` !`  ` `` rendered `<p></p>` where
  carve-js and carve-rs both render `<p>  </p>`. The strip now runs on the
  paragraph source before inline parsing, which also removes the special case
  that was needed for a dropped raw-format span. The character class is
  unchanged (space and tab), and a trailing NBSP remains content.
- **Never pad all-space verbatim content in `carve fmt`.** A verbatim span whose
  content is entirely spaces was padded by the serializer even though the parser
  leaves it unstripped, so every fmt pass grew the span by two spaces
  (`` ` ` `` → `` `   ` `` → `` `     ` ``) and broke both formatter guarantees,
  `toHtml(fmt(x)) === toHtml(x)` and `fmt(fmt(x)) === fmt(x)`. Math was worse: it
  took no strip at all while still being padded, so `` $` x ` `` grew on every
  pass. Math now takes the same single-space strip as a code span (carve-js and
  carve-rs parity), the serializer pads exactly where the parser strips, and the
  code span, math and inline literal scanners share one `stripVerbatimPadding`
  helper so they cannot drift apart.
- Extension attributes render in source order rather than class-first (#384).
- A fenced-code delimiter sits at its container's content column, so fences
  inside list items and quotes parse correctly (#377); the definition prepass
  tracks list content columns, fixing a nested-fence limitation (#380).
- An unterminated fence no longer absorbs the source's final newline (#374).
- A definition marker requires a literal space after the colon, not a tab, and
  tab-separated footnote-definition lines are no longer swallowed (#371, #372).
- A sublist marker at the content column interrupts an open continuation
  paragraph, and a footnote definition requires an inline body (#370).
- A blank line may separate a term from its definition (djot parity) (#355); a
  multi-line term folds continuation lines like a heading instead of dropping
  them (#354); definition descriptions support lazy continuation (#350) and the
  `:  +` first-block form (#352); definition and footnote bodies continue like
  list items (#345).
- A thematic break is a contiguous column-zero run only (#363).
- A trailing backslash at end of input is a hard break, and a bare same-level
  `#` continues a heading (#362).
- A leading attribute line attaches to a bare block image (#335).
- Heading and caption trailing-whitespace handling corrected (#333, #334).
- A figure caption renders on its own line in the non-HTML renderers, matching
  carve-js and carve-rs (#323).
- Tight list items stay unwrapped when source lines are enabled (#365).
- Tabs keep the quoted opener title inside the panel, and the quoted opener
  header is separated from the `title` attribute on divs (#324, #326); opener
  titles render as inline content (#330).
- Untrusted comment and minimal presets get a default `maxLength` cap (#349).
- The formatter preserves the authored list marker (bullet character and ordered
  delimiter) (#369), keeps verbatim content byte-exact through document
  normalization (#359), and round-trips symbols correctly (#340).
- Bounded several worst-case quadratic scans (unclosed constructs, emphasis
  openers, link destinations) so pathological input parses in near-linear time
  (#341, #343, #344).

## [0.1.2] - 2026-07-12

### Security

- Gate two ungated raw-HTML paths in the `HtmlToCarve` reverse converter that
  let untrusted HTML emit live `{=html}` passthrough (XSS); honored only with
  `trustedRoundTrip` (#293)
- Neutralize raw-HTML blocks minted from foreign code fences (XSS) (#298)
- Harden `::: toc` / `::: footnotes` placement: per-render output budget
  (amplification bound), Trojan-Source bidi controls stripped from TOC link
  text, renderer state protected against exceptions (#292)
- Linear nested `[quote]` parsing in `BbcodeToCarve` (was O(n^2) DoS) (#302)

### Added

- Opt-in `collapsible` option on `TableOfContentsExtension` (#297)

### Fixed

- Cross-implementation parity with carve-js / carve-rs (byte-verified):
  bare `{}` stays literal, class dedup, literal backslash in URLs (#294);
  math/table/block-attr/fmt divergences (#301); unclosed code span and raw
  image alt data loss (#299); separator-shaped rows never promoted to table
  headers (#317); dropped raw-format span keeps its leading space (#316);
  block images in blockquotes/lists (#319, #320); unresolved reference image
  formats verbatim (#321); permalink wrapper + wikilink attribute order (#312);
  empty details/spoiler body keeps a blank line (#311); `{% %}` is literal
  text (#313)
- Forward footnote reference inside a footnote body resolves (#300)
- TOC entry text trims the space left by an excluded section number (#305);
  trailing newline after injected `::: toc` nav (#310); genuine trailing empty
  cells in non-HTML tables (#308)
- `:index[term]` marker no longer feeds the heading slug (#306); glossary div
  wrapper kept on non-definition-list bodies (#307)
- `DjotToCarve` no longer corrupts footnote labels and pre-braced forms

## [0.1.1] - 2026-07-04

### Added

- `::: toc` and `::: footnotes` placement directives, including
  container-nested headings in `::: toc` placement (#288, #289)

### Fixed

- Extension-generated ids deduplicate against the document id namespace (#287)
- Heading auto-id deduplication skips reserved suffix candidates (#286)

## [0.1.0] - 2026-07-02

Initial release of **carve-php**, a PHP parser and renderer for the
[Carve](https://github.com/markup-carve/carve) markup language. Install via
Composer: `composer require markup-carve/carve-php`.

### Core parsing and rendering

- Block and inline parser producing a typed AST via `CarveConverter`
- Full Tier-1 feature set: headings (H1-H6), paragraphs, emphasis (`/italic/`,
  `*bold*`, `_underline_`, `~strikethrough~`, `^super^`, `,sub,`, `=highlight=`,
  `/*bold-italic*/`), blockquotes with attribution captions, unordered and ordered
  lists, task lists, tables (with colspan/rowspan), inline code and fenced code
  blocks, images (inline and block with captions), horizontal rules, hard breaks,
  YAML frontmatter, admonitions (`::: note`/`tip`/`warning`/`danger`), abbreviations
  (`*[ABBR]:`), mentions (`@user`), hashtags (`#tag`), display and inline math
  (`$$`/`` $` ``), inline extensions (`:type[...]`), attribute blocks (`{#id .class
  key=val}`), raw HTML passthrough (`=html`), comment lines (`%%`), and reference
  links/images
- Inline footnotes (`^[...]`) and block footnote definitions
- Editorial / critic markup (`{+ +}` insert, `{- -}` delete,
  `{~ old~>new ~}` substitute, `{= =}` highlight, `{# #}` comment)
- Smart typography: curly quotes, em/en dashes, ellipsis
- Reference definitions collected inside list items and containers
- HTML renderer via `(new CarveConverter())->convert()`
- Markdown renderer (`CarveConverter::markdown()->convert()`), plain-text
  renderer (`CarveConverter::plainText()->convert()`), ANSI-colored renderer
  (`CarveConverter::ansi()->convert()`)
- Static render mode (`setRenderMode(RenderMode::STATIC)`) for self-contained
  HTML; interactive constructs degrade gracefully
- Profiles API for named capability sets

### Extension API

- Parse-stage inline and block matchers (`addInlineMatcher`, `addBlockMatcher`,
  `addInlinePattern`, `addBlockPattern`) with priority and trigger-char fast path
- `MatcherContext` exposes reference/footnote/abbreviation tables and recursive
  parse helpers (`parseInlines`, `parseBlocks`)
- Render hooks and `before_render` / `after_parse` AST transforms
- `PlusBulletExtension` and other bundled extensions documented in
  `docs/extensions.md`

### Tier-2 opt-in extensions

- `mathBlock` - fenced ` ```math ` block rendered as `<div class="math display">`
  with author-attribute passthrough (core `$$` display math is always-on Tier-1)
- `citations` - `[@key]` reference citations with typed locators, explicit
  suffixes, and integral/group-level markers (§22)
- `codeCallouts` - annotated callout markers inside fenced code blocks

### Tier-3 opt-in extensions

- citations `bibliography` option - supplying a CSL-JSON pool renders a
  cite-ordered `<ol>` with back-links (a citations capability, not a standalone
  extension)
- `glossary` - `::: glossary` blocks with `:term[word]` inline markers linking to
  `gloss-{slug}` anchors
- `index` - `:index[term]` invisible span markers with a sorted `::: index` block
  collecting back-links
- `headingNumbers` - opt-in section auto-numbering and numbered `</#id>`
  cross-references via `<span class="section-number">`
- `colorSwatch` - `:color[value]` inline color preview chip; CSS named-color
  validation; configurable position, shape, tint; auto-contrast label variant;
  documented in the static-render demo
- `spoiler` - `:spoiler[text]` inline and `::: spoiler` block as native
  `<details class="spoiler">`
- `details` - `::: details "Title"` as HTML5 `<details>/<summary>`
- `fencedRender` - generic client-render factory (Mermaid preset included) with
  text and json content modes
- `listTable` - `::: list-table` nested-list-to-table with header-rows/cols and
  span markers (`^`/`<`)
- `tableOfContents`, `headingPermalinks`, `autolink`, `externalLinks`,
  `wikilinks`, `tabNormalize` - standard document-enhancement extensions

### Converters

- `HtmlToCarve` - round-trip converter from HTML back to Carve source; gated
  behind `trustedRoundTrip` (disabled by default) to prevent `data-djot-src`
  XSS when handling untrusted HTML
- `DjotToCarve`, `MarkdownToCarve`, `BbcodeToCarve` - migration converters with
  output-byte budget guards against DoS amplification

### CLI

- `bin/carve` binary reading Carve from a file or stdin
- `--html` (default) / `--markdown` (`--md`) / `--plain` / `--ansi` format
  selection
- `-o FILE` output to file; `--warnings` / `--strict` parse-warning reporting;
  `--xhtml` and `--safe` for HTML-output tuning
- `carve fmt` - canonical formatter (semantic-preserving, `-w` in-place,
  `--check` CI gate)

### Security (always-on, §25-§26)

- URL scheme denylist covering `javascript:`, `data:`, `vbscript:`, and OS
  protocol-handler schemes
- Dangerous attribute stripping (`on*`, `srcdoc`, `formaction`) on all elements
- Linear attribute-value regex replacing PCRE JIT path that could silently drop
  attributes on long values
- CSS `expression()` and `url()` neutralization in style attributes
- Trojan-Source hardening: NFC normalization of heading/footnote ids; bidi and
  zero-width Unicode control characters stripped from text and code content (§26)
- Uniform nesting depth cap of 200
- `HtmlToCarve` `data-djot-src` XSS closed (P0); `trustedRoundTrip` default-off
- Output-byte budgets on all reverse converters against amplification DoS

[Unreleased]: https://github.com/markup-carve/carve-php/compare/0.1.0...HEAD
[0.1.0]: https://github.com/markup-carve/carve-php/releases/tag/0.1.0
