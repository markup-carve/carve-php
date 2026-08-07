# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`HtmlRenderer::getSmartTypography()`** (carve-php#1033). The setter's
  counterpart, so an extension that derives its own display text at render time -
  a table-of-contents entry, for one - can honor the mode the renderer was
  configured with instead of materializing glyphs of its own. See
  [`README.md`](README.md).

- **Two platform autolink lint rules, off by default** (carve-php#1005,
  specified in markup-carve/carve#959, reference implementation
  markup-carve/carve-js#856). `MarkdownHabitLinter::lint()` takes a second
  argument, `['platforms' => ['github']]`, and `carve lint` takes a repeatable
  `--platform` flag. With a host named, the linter reports the at-word and
  hash-number tokens that host re-linkifies out of published output - a mention
  that notifies an uninvolved person, an issue reference that posts a backlink
  on something unrelated - under the ids `platform-mention-token` and
  `platform-issue-reference`. Neither can fire unless a host is named, and an
  unknown name is ignored on the API but refused on the command line, because a
  misspelt flag reporting nothing looks exactly like a clean document. They read
  prose and inline code spans, and never read fenced code blocks, raw blocks,
  comments, frontmatter, link reference and abbreviation definitions, an
  unreferenced footnote definition, or a link destination. See
  [`docs/lint.md`](docs/lint.md).

### Fixed

- **The canonical writer spells the frontmatter format token out** (carve-php#1044,
  PART 11 §6b, ruled in markup-carve/carve#961 and written in
  markup-carve/carve#977). `carve fmt` now writes `---yaml` where it used to
  write a bare `---`. `yaml` was the one format written without its token -
  `---toml` and `---json` were already spelled out - so the DEFAULT format was
  the single case where the writer did not say what it had parsed. The grammar
  uses the word "canonical" for the exact string `---yaml`, and this is the
  canonical writer. A bare `---` remains legal input and still opens `yaml`
  frontmatter; only what the writer emits moves.

- **The canonical writer puts a blank line where an empty container body would
  be** (carve-php#1042, ruled in markup-carve/carve#961). For every container
  shape, including the bare `:::` div, `carve fmt` now writes the opener, a
  blank line and the closer where it used to glue the opener straight to the
  closer. PART 10 §4 settled the same question one layer out, for the HTML
  target, and chose the blank line; §4's bare-div exception is deliberately not
  imported, because that clause says in its own words that the exception "has no
  principle behind it". carve-js and carve-rs already wrote the blank line, so
  this closes seven of the nine remaining `carve`-target divergences between the
  three engines.

  The glue was a workaround for a parser defect one layer down, fixed with it: a
  blank line inside an OPEN `:::` div ended the enclosing list item's
  collection, so the closer below it read as a fresh bare-div opener and

  ```
  - item
    ::: note

    :::
  ```

  published a spurious empty `<div>` beside the aside. A blank line inside an
  open CODE fence was already read correctly; only the div was short of the
  case. Rendered HTML is unchanged for all 830 corpus documents.

- **`whitespace` is a space or a tab everywhere a heading or a caption asks**
  (carve-php#1038, tracked at markup-carve/carve#963). PART 1 defines
  `whitespace = ' ' | '\t'` and every other invisible character is CONTENT, but
  this engine spelled that one definition three ways. The heading's trailing
  trim used PHP's default `rtrim` charlist, which is wider - it also takes a
  VERTICAL TAB (U+000B) - so a heading was the one construct that dropped a
  trailing U+000B where the identical paragraph kept it. The heading's and the
  caption's emptiness gates spelled it as PCRE `\S`, which reads a vertical tab
  AND a FORM FEED (U+000C) as whitespace, so `# ` or `^ ` followed by one of
  them was refused outright and published as a paragraph - while corpus
  `268-trailing-whitespace-on-a-content-line-is-dropped-7` already pins a form
  feed as content on a paragraph line. All three now use the one definition, and
  so do the eleven recognizer copies of those gates that decide whether a
  heading or a caption starts at all (paragraph interruption, inside a div,
  inside a block quote, the heading-id pre-scan, and the heading-id preservation
  pass the Djot and Markdown converters run). A heading keeps a trailing
  vertical tab and a form feed, still drops a trailing space and tab run, and a
  heading or a caption whose whole content is one such character is a heading or
  a caption. carve-rs reads every one of these documents the same way.

- **A caption line drops its trailing whitespace, in the AST as well as in the
  HTML** (carve-php#1037, tracked at markup-carve/carve#963). PART 2 NO TRAILING
  WHITESPACE applies to every content line, and a caption line is one: corpus
  268 pins the rule for a paragraph, a heading, a list item, a block quote and a
  definition, and its table-caption row is what caught this. The parser applied
  the rule to the source for all of those and to nothing for a caption, so the
  published AST carried `"Cap "` where carve-js and carve-rs carry `"Cap"`. The
  rendered HTML looked right only because `HtmlRenderer` trimmed its own output
  at the three places it writes a caption, which also ate the content of an
  all-space inline literal - `^ x !` plus a backtick-space-space-backtick
  published `<caption>x</caption>` where the identical paragraph published
  `<p>x   </p>` - and swallowed the newline a trailing hard break emits. The
  rule now applies once, to the caption's source text, so all four caption hosts
  (table, image, code block, block quote) and every render target agree, an
  all-space literal survives, and a hard break ends a caption exactly as it ends
  a paragraph. Consumers reading `text.value` out of a caption see one fewer
  trailing space; the caption's text span shrinks with it, so the span still
  slices to the value.

- **A numbered cross-reference label and a table-of-contents entry keep the
  target heading's source run** (carve-php#1033, specified in
  markup-carve/carve#957). PART 9R R4 binds every consumer that derives display
  text from a heading, not the cross-reference alone. With smart typography in
  `Source` mode, `HeadingNumbersExtension` rendered
  `Section 1 - The "quoted" - heading` with resolved glyphs one line under a
  heading showing the run the author typed, and both table-of-contents
  extensions did the same. The numbered label now splices the heading's inline
  NODES, because that transform runs before any renderer and a string would
  answer the mode question permanently; the two TOC extensions ask the tracker
  with the renderer's own mode, because they build their text at render time.
  Default (glyph) output is unchanged, the label word, the number and the
  separator around them are still the extension's own, and a heading's markup is
  still stripped rather than spliced into the label.

- **A definition-body line indented one or two columns ends the body instead of
  folding in as lazy text** (carve-php#1035, specified in
  markup-carve/carve#932). `definition_indent` puts the body's content column at
  3, and BELOW that column the body ENDS and the line is classified in the
  surviving context - the same thing "below the content column" means for a list
  item and for a footnote body. So `:: t` / `:  body` / ` > q` now closes the
  `dl` and renders `<p>&gt; q</p>` rather than `<dd>body\n&gt; q</dd>`. Folding
  such a line in gave a sub-column indent the PAST-the-column band's meaning,
  which made indentation depth mean two different things one column apart.
  Column 0 is unchanged, because `lazy_continuation_line` is written for a
  flush-left line and still picks a non-opener up there; column 3 still opens a
  block inside the `dd`; column 4 and beyond is still lazy text. **Breaking:** a
  document indenting a definition continuation by one or two spaces gets a
  separate block instead of a folded line.

- **A fence opened on a `:  ` definition-body marker line no longer swallows
  lines below the body's content column** (carve-php#1031, specified in
  markup-carve/carve#956). `:: t` / `` :  ``` `` / `body` / `` ``` `` gave a `dd`
  holding a code block with `body` in it. The line supplies none of the body's
  indentation, so PART 9 section 24's walk stops at the definition entry, the
  fenced body is never reached, and S4's lazy branch has no open PARAGRAPH to
  fold into - a verbatim body is not one. The containers close, the `dd` holds an
  EMPTY code block, and the tail re-parses at document level, byte for byte the
  answer the list and block-quote spellings already gave. A line AT or past the
  body's column is still the fence's content, and a fence that has CLOSED still
  takes a lazy line into the reopened paragraph. A CLOSED fence with no paragraph
  after it now ends the body too, which is the same rule stated once: S4's lazy
  branch wants an open paragraph, and a finished code block is not one either.

- **A reference definition whose trailing `{...}` block is not `attributes` is a
  paragraph** (carve-php#1025, specified in markup-carve/carve#933).
  `[a]: /u {#}`, `[a]: /u { }`, `[a]: /u {=}`, `[a]: /u {}` and
  `[a]: /u {.a\}b}` no longer define, so an `[a][]` under one of them renders as
  literal source text and the braces the author wrote stay on the page. The slot
  names the `attributes` production, and a balanced block that production does
  not accept is leftover content, which the end-of-line anchor turns into prose.
  Previously the block was peeled off by a balance scan before anything
  validated it, so a rejected block was discarded and the line still defined -
  with the author's metadata gone from the output. A VALID block still defines
  and still transfers its attributes. **Breaking:** a document relying on one of
  these lines resolves one fewer reference.

- **An explicit `[text][label]` no longer reaches the heading index**
  (carve-php#1029, specified in markup-carve/carve#742). PART 9R R1's implicit
  heading fallback is scoped to the COLLAPSED `[text][]` and to nothing else, at
  any spelling, folded or exact. So `[q][Getting Started]` under a
  `# Getting Started` renders as literal source text instead of linking to
  `#Getting-Started`. The asymmetry is the one R1 already states: a collapsed
  label is the author quoting prose from elsewhere in the document, which is why
  its matching is loose, and an explicit label is an identifier the author wrote
  twice and can keep identical. An identifier that names nothing names nothing.
  The collapsed form still reaches the index, and an explicit label naming a real
  `[label]: url` definition still resolves. **Breaking:** a document whose
  `[text][Some Heading]` resolves today renders the bracketed run literally.

- **The ANSI target keeps a code block's verbatim content.** `AnsiRenderer`
  split the block on `rtrim()`, which drops the terminating newline but also
  takes the trailing space on the block's last line and every blank line at its
  end. Both are content the author typed inside a fence, and this engine's HTML,
  plain-text, Markdown and canonical writers all keep them - so one code block
  rendered two ways depending on the target asked for. Only the terminator is
  dropped now.

- **A table column claimed by a `<` or `^` span survives on the plain-text and
  ANSI targets.** `TableLayout::expand()` returns `null` both for a column a span
  marker claimed and for padding that squares off a genuinely short row, and the
  two text writers trimmed trailing `null`s without telling them apart. A row
  whose last column was covered by a span lost it: the plain target wrote one
  cell for a row spanning two, and the ANSI target drew a row narrower than its
  own box border. A span marker is a cell the author typed, so the row is not
  short.

- **The published AST schema names every type and field the encoder emits**
  (carve-php#1015). `AstCodec::schema()` derives its map by reflecting over node
  properties, which sees the property walk in `encodeNode()` and nothing else -
  so three code paths that write outside it were invisible to it. The retypes at
  the top of `encodeNode()` publish a bare-URL link as `autolink`, a typed div as
  `admonition` and a `#tag` mention as `tag`, none of which has a node class to
  reflect over, so all three were emitted and absent from the schema entirely.
  `derivedFields()` and the shape passes at the end write another ten fields the
  map did not list, among them an admonition's `kind`, a mention's `user`, a
  list's `ordered` and a table cell's `header`. An application validating a
  payload against `AstCodec::schema()` was therefore told that a type or a field
  it had just been sent does not exist. The map now covers all of them, derived
  from `ReferenceShape::TYPE_ALIASES` and a new `AstCodec::HAND_WRITTEN_FIELDS`
  so the encoder and the schema read one declaration. No encoder output changed;
  the schema caught up with it. The opposite direction, types the schema named
  that its own decoder refused, was closed for `caption` and `section` in
  carve-php#1002 and no longer has any entry.

- **A block-quote marker with no space after it defines nothing** (carve-php#961).
  The definition prepasses and the prepass fence tracker read a LOOSER
  block-quote marker rule than the block parser: theirs made the space after `>`
  optional, the block parser's requires it, and `blockquote_line = '>',
  (newline | (space, inline_content, newline))` in the grammar says the block
  parser was right. So the two disagreed about which lines are quoted, and a
  definition harvested by one and refused by the other went both ways at once.
  `>[r]: /u` printed as a paragraph AND resolved `[link][r]` off it; `>> [r]: /u`
  did the same. The mirror also happened: the tracker counted `> >``` ` as a
  fence two quotes deep, so `> > [r]: /u` under it was skipped as fenced content
  while the block parser emptied the line as a real quoted definition, and the
  document showed neither the definition nor the link. There is now one rule.
  A nested quote is written `> > `, and a fence inside one `> > ``` `. No
  document in the 810-file spec corpus renders differently.

- **A collapsed heading reference keeps its spelling through the ProseMirror
  bridge** (carve-php#1006). The `link` mark carried the destination and nothing
  about the reference, so `[*bold* heading][]` came back as
  `[*bold* heading](#bold-heading)`, baking a generated id into the source on
  every pass. The mark now carries the reference and the writer restores it,
  re-derived against the document's own heading index so an editor that retypes
  the link text or repoints its destination keeps that edit. A `[text][label]`
  reference is deliberately not carried - it resolves against a definition the
  editor model does not hold - and is reported through `degradedTypes()`
  instead.

- **A resolved cross-reference keeps the target heading's source run**
  (carve-php#994, ruled on markup-carve/carve#952). PART 9R R4 makes the label
  of a `</#id>` the target heading's inline NODES cloned, and the difference
  from a rendered string is the whole point: a node carries the run the author
  typed, a string does not. This engine flattened the heading to a glyph string
  at id-tracking time, so smart typography's SOURCE mode could not recover it on
  the plain-text, Markdown or ANSI target - and no renderer change could reach
  it, because the label was already gone before any renderer ran. `# The
  "quoted" -- heading` with a cross-reference to it now emits the typed quotes
  and double hyphen in source mode on every target, and the glyphs at the
  default. The HEADING ID is unchanged: it is still slugged from the glyph, so
  `Don't` keeps giving `Don-t`. A cross-reference INSIDE a link, which is
  rewritten before any renderer sees it, carries the label as nodes for the same
  reason, so rendering one parsed document at both modes gives each render its
  own answer.

- **A fence opened on a list marker line does not reach past the item's content
  column** (carve-php#991, ruled on markup-carve/carve#950). PART 9 §24's STEP
  walk is driven by the indentation a line SUPPLIES: for `- ` + a code fence
  opener followed by a flush-left line, S1 stops at the ITEM, so S2 FENCED BODY
  never fires and S4 governs - and its lazy branch continues an open PARAGRAPH,
  which a verbatim body is not. The item therefore holds an EMPTY code block and
  the residue re-parses at document level, which is the answer the BLOCK QUOTE
  spelling of the same shape already got here. This engine used to keep
  collecting into the fence on the reasoning that an unterminated fence runs to
  end of input by §28 - which it does, inside the container that opened it. A
  body AT the content column is unchanged. A tilde fence, a body one column in,
  a body that already collected a line at the content column, a fence opened on
  a CONTINUATION line, and an item's post-blank nested content all follow the
  same rule.

- **An inline attribute block's interior is space-only** (carve-php#985, ruled
  on markup-carve/carve#906). PART 4 spells every whitespace slot of the INLINE
  attribute block `space`, which is one character: the run after `{`, the run
  between two attributes, the run before `}`, the boundary after an unquoted
  value, and the blessed empty block `{ }`. All five sit after the first
  non-whitespace character of their line, which is where PART 7's rule already
  says a tab is not syntax. A tab at any of them makes the block unrecognized
  and its braces show, so `*x*{.a` + TAB + `.b}` is literal text now. A tab
  inside a QUOTED value is content and does not move. The block-attribute LINE
  is deliberately not narrowed - it is the one construct whose interior can hold
  a leading indentation run - so `{` + TAB + `.a` + TAB + `.b` + TAB + `}` and a
  tab-indented continuation line both still work.

### Changed

- **An ingest validates the whole payload against the AST schema**
  (carve-php#979, ruled on markup-carve/carve#881). PART 12 §12(d) makes
  `resources/ast-schema.json` the contract at decode - types and required fields
  together - and it is now vendored and consulted rather than described. Trees
  this codec used to accept are refused with the typed `AstDecodeException`: a
  root `srcByteLength` that is a string or negative, a `children` that is `null`
  and was read as an empty document, a `text.value` that is the number 7 and
  rendered `<p>7</p>`, a `pos` missing a field or carrying an extra one, and
  `attrs` written as `{"class": "x"}`. Two shapes that used to escape as a bare
  PHP `TypeError` - a child that is `null` and a child that is a string - are
  refused as the same typed error, which §9(b) already required. Producers
  should validate against the schema before sending. A node type registered with
  `AstCodec::register()` is outside the schema by construction and is exempt.
  See docs/ast-json.md.

### Removed

- **BREAKING: the five pre-PART 12 §7 payload shapes no longer decode**
  (carve-php#1002). `AstCodec::decode()` used to normalize five spellings that
  predate §7 before judging the payload: a root `abbreviations` map with its
  `abbreviationsBeforeBody` flag, a root `frontmatter` object, a root
  `footnoteDefs` map, a `footnote` node keyed `id` rather than `label`, and this
  engine's internal `raw_text` node. All five are refused now, with a typed
  `AstDecodeException` naming the spelling it found. Neither carve-js nor
  carve-rs ever accepted them, so tolerating them kept a cross-engine divergence
  in what an ingest accepts, and every schema addition had to be reasoned about
  twice - once per shape.

  **Migration, and it ships in this same release:**
  `MarkupCarve\Carve\Ast\StoredPayloadUpgrade::upgrade()` converts a stored
  payload in any of the five shapes into the §7 shape, and
  `::upgradeJson()` does the same JSON in, JSON out. Both work on the payload
  alone - an application holding stored payloads may no longer have the Carve
  they were parsed from. The conversion is idempotent and leaves a payload
  already in the §7 shape untouched, so it is safe to run over a whole store,
  and it converts the two node types below as well. One caveat: `raw_text`
  becomes the `text` node the encoder already published it as, and a `text` node
  is escaped when written back out. See docs/ast-json.md.

- **BREAKING: `caption` and `section` are no longer encoded** (carve-php#1002).
  `AstCodec` published two node types PART 12's vocabulary does not name, so a
  document round-tripped through this engine's own codec produced a payload its
  own decoder refused - reachable through the ProseMirror bridge, which builds
  both. A `section` is now published as the `div` it wraps blocks as, and a
  `caption` that reached the wire as a node - a figure's and a table's are
  already published as the reference publishes them, an inline-content FIELD -
  as the `paragraph` it holds inline content as. Both types also leave
  `AstCodec::schema()`, so a consumer validating against it is no longer told
  about types the published schema rejects. A payload already stored with either
  never decoded - the engine that wrote it refused it - and
  `StoredPayloadUpgrade::upgrade()` converts those too.

### Fixed

- **An autolink body excludes format and control characters** (carve-php#983,
  ruled on markup-carve/carve#844 and markup-carve/carve#860). PART 3's
  `url_char` now admits, outside ASCII, any character that is not whitespace,
  not a format character (General_Category Cf) and not a control character (Cc).
  This engine already linked an internationalized domain and had the first half;
  it also linked a host carrying a byte order mark, a zero-width space, a word
  joiner or a U+0001, which the second half excludes. A format character is
  invisible by definition, so a host carrying one renders as the host without it
  and links somewhere else - a spoofing surface rather than an authoring
  convenience. `<https://e` + U+FEFF + `.com/>` is literal text now. The ASCII
  half of the production is written as the enumeration the grammar gives rather
  than as a negated class, which is what excludes the control characters; the
  nine punctuation exclusions are unchanged, `link_destination` is a different
  production and is unchanged, and `scheme` stays ASCII.

### Fixed

- **A span begins at the construct's opening markup** (carve-php#978, ruled on
  markup-carve/carve#913). PART 12 §4 puts the markup that OPENS a construct
  inside its `pos`, so a span round-trips to the source that produced it.
  Anything nested in a container was published starting at the CONTAINER's
  prefix instead: a heading in a block quote began at the `>`, a fenced block in
  a list item at the `-`, and a `- +` item - whose body is flush left - at the
  table that is its body rather than at its own marker line. Over the spec
  corpus this was 33 wrong spans across nine node types, now none. Positions are
  an opt-in parse option, so nothing changes for a parse that does not request
  them.

- **A quoted attribute value stops at the newline on a block-attribute line**
  (carve-php#986). `quoted_value` excludes a newline in both of its
  alternatives, and `block_attributes` reads the same production, so a break
  inside the quotes is neither content nor a separator: it ends the production
  and the whole block is unrecognized. `{k="a` + `b"}` is a paragraph now, where
  this engine used to accept it and COLLAPSE the newline to a space - which no
  production in either normative file describes. The inline form was already
  correct here. A block attribute may still span lines: `continuation` admits a
  break BETWEEN two attributes, so `{.a` + `.b}` is still one block, and a blank
  line still ends the attempt.

- **A dedented line folds into a quoted paragraph the parser actually built**
  (carve-php#969). The lazy-continuation tracker decided "this line holds no
  paragraph" with its own copy of the block-quote marker walk, and the copy was
  looser than the rule the parser applies two functions away. Two shapes came
  out of the disagreement, and in both the parser builds a paragraph while the
  tracker reported none, so a following column-0 line failed to fold into a
  paragraph that was right there: `><SP><SP>>` (two spaces between the markers, which
  is ONE marker and the content `>`), and `> <VT>` - the copy trimmed on PHP's
  default charlist, which holds a vertical tab and not a form feed, so two lines
  of the same shape got opposite answers. A blank line holds spaces and tabs and
  nothing else, so neither character is padding here. `>` and `> >` with any
  amount of space or tab padding still hold no paragraph and still do not fold.

- **A block-attribute block spans any number of lines** (carve-php#954). Both
  normative files admit one line break per attribute separator with no limit -
  `attr_separator = (whitespace | continuation), opt_ws` in
  `resources/grammar.ebnf`, `battrSp = " " | "\t" | "\n"` under a star in
  `resources/carve-core.ohm` - and carve-js, carve-rs and the executable spec
  all read `{.a` + `.b` + `.c}` as one block. This engine capped it at a single
  break, because the continuation branch required the line to be INDENTED, which
  is not what `continuation` says: the indent lives in the optional `opt_ws`. A
  two-line block worked only because its second line met the CLOSING branch, so
  the cap became visible from the third line onward, and `{` + `.a` + `}` did
  not work at all. Two things move with it, both inside the same production: a
  blank line now ends the attempt as PART 15 A5 says (a line of spaces or tabs
  IS a blank line, and used to be accepted as interior padding), and a
  continuation line that is not a valid attribute list on its own ends it too -
  a line break falls between attributes and never inside one, so `{.a` +
  `# heading` + `.b}` is literal text. Inside a QUOTED value a line break is
  part of the value, so its lines are exempt from that rule and a value spanning
  several lines parses exactly as it did - for single-quoted values as well as
  double-quoted ones, and across a backslash-escaped quote.

- **The paragraph a capped container degrades to places its text runs**
  (carve-php#965). carve-php#946 placed that paragraph and its soft breaks; its
  `text` runs were still published with no `pos`, which was the whole of this
  engine's outstanding column in the spec's `resources/ast-position-waivers.txt`.
  That is not the PART 12 §4 exemption - §4 permits omitting `pos` on a
  reassembled node and names them, and a degraded run is none of them: it is a
  contiguous slice of exactly one source line, which carve-js publishes and whose
  span passes the slice rule. The runs are now placed from line geometry, the
  same way the soft breaks already were, and only where the source proves the
  mapping: one run per group line, each run's text a suffix of its source line
  (which is what recovers the stripped container prefix), and all or nothing per
  paragraph. A paragraph whose inline shape does not match its lines - smart
  typography splitting a run, a trailing backslash making a hard break - places
  nothing rather than guessing, because §4 rates a wrong span worse than an
  absent one.

- **The link and image title slots take a space** (carve-php#962).
  `link_title` is spelled `space` in `resources/grammar.ebnf` and
  `image_title = link_title` inherits it, so PART 7 applies: the slot sits after
  the first non-whitespace character of the line, and from there onward a tab is
  not syntax. The fallback is not a link without a title - the quoted run is left
  unconsumed and lands inside a destination that admits no whitespace, so the
  bracket run stays literal text and the run the author typed survives:
  `[t](/u<TAB>"T")` renders as `<p>[t](/u<TAB>&ldquo;T&rdquo;)</p>`. The site used
  the regex `\s` class with no `/u` modifier, i.e. `[ \t\n\r\f\v]`, so a
  vertical tab, a form feed and a line break filled the slot too and all of them
  stop now. One narrowing covers both tails, since the same block reads the link
  tail and the image tail. Cardinality is unchanged: a run of spaces still fills
  the slot. A reference definition's title is read elsewhere and is untouched.

- **Every table-cell padding slot takes a space** (carve-php#960). PART 7
  decides the terminal by position, and every one of these slots sits after the
  row's opening pipe: `delimiter_cell`, `header_cell` and `data_cell` at both
  ends, plus `rowspan_marker` and `colspan_marker`. A non-space run there is not
  a rejection - it stops being padding and becomes ordinary cell content, so
  `|<TAB>a |<TAB>b |` now renders two cells whose text still starts with the
  tab. At `delimiter_cell` the failure is structural on top of that: the line is
  no longer a delimiter row, so no header is promoted, no alignment is assigned,
  and the `---` run is content that smart typography renders as an em dash. A
  tab beside a `^` or `<` likewise makes the cell ordinary content and the span
  does not happen. Five sites decided this through three different character
  classes - the content strip used `trim()`, whose charlist admits a tab and a
  vertical tab but not a form feed; the delimiter-row test used the regex `\s`
  class, which in PCRE is `[ \t\n\r\f\v]`, so a form feed satisfied a
  delimiter cell while already surviving a data cell; and a continuation row's
  cells are `data_cell`s padded in a second place, so `+<TAB>x | y |` joined the
  tab away, while a cell carrying a glued `{...}` attribute block stripped its
  leading slot in a third place before the content strip ever ran. Cardinality
  is unchanged: these slots are a run of spaces and stay one.

- **A nested container no longer re-measures every body line's whole
  indentation run at every level** (markup-carve/carve#752). The indentation
  gate walked a line's entire leading whitespace before comparing the result
  against a column two or three wide, and every enclosing level repeated the
  walk over the same run - `O(depth)` character work per line per level. On the
  deepest ladder a conforming document can reach (depth 200, 40,600 bytes,
  `MAX_NESTING_DEPTH` is 200) the gate walked 2,707,196 characters, 98.5% of
  them at one call site; it now walks 100,097, and its counted growth per depth
  doubling is 4.00x against the document's own 3.94x where it was 7.88x.
  Parsing that ladder went from 82.4 ms to 49.7 ms on an idle machine, and its
  growth per depth doubling from 5.10x to 4.07x - which is the document's own
  3.94x within measurement error, so the parse of a deep list is now linear in
  its size. The gate takes the column its caller is comparing against and stops
  there. Every parse result is unchanged: the whole spec corpus renders
  byte-identically on all five targets (HTML, AST JSON, Markdown, Carve, plain
  text), the same SHA-256 over 675 documents before and after.

- **The code fence, frontmatter and raw-block openers take a space in every
  slot** (carve-php#951). PART 7 decides the terminal by position, so the code
  fence's slot before its info string, `code_fence_info`'s own `"header"` and
  `[label]` sub-slots, the frontmatter format slot and the raw block's
  `=FORMAT` slot are all spelled `space`. Each admitted a different width, for
  a different reason: the code fence read its slot with `trim()`, whose
  charlist also holds a vertical tab; frontmatter spelled it `[ \t]*`; the raw
  block used the regex `\s` class, which in PCRE is `[ \t\n\r\f\v]`, so a form
  feed opened one too. ```` ```<TAB>js ````, ```` ``` js<TAB>"T" ````,
  `---<TAB>yaml` and ```` ```<TAB>=html ```` are ordinary prose now. The two
  code-fence metadata slots become run tests rather than first-character
  tests, so ```` ``` js<SP><TAB>"T" ```` no longer keeps its title. Cardinality
  is unchanged: a run of spaces still fills every one of these slots.

- **Every whitespace slot on a colon-fence opener is a literal space**
  (carve-php#941, carve-php#948). PART 7 decides the terminal by position: a
  tab is syntax only in a line's leading indentation run, and every slot on
  this line sits after the fence run. So `:::<TAB>note` is prose, and so are
  `::: note<TAB>"Title"`, `::: note<TAB>[lbl]` and `::: note "T"<TAB>[lbl]`,
  which used to open an admonition and keep their metadata. The separator is
  a run test rather than a first-character test, so `:::<SP><TAB>note` is
  prose too. The code fence, frontmatter and raw-block openers are a separate
  question, tracked as carve-php#951.

- **A `+` continuation marker with trailing whitespace is recognized**
  (carve-php#929). §17 L3 says "a line whose only content is `+`", and trailing
  whitespace is not content - but the test was spelled four ways across seven
  sites, so whether a trailing space broke the marker depended on which code
  path a document happened to reach. One predicate now, with each caller
  keeping its own column check.

- **A `+` continuation marker works whatever the item already holds**
  (carve-php#925). §17 L3 conditions the marker on its column and on nothing
  else, but this engine recognized it in a tight item and ignored it once the
  item held a blank line - so the marker came out as literal text inside the
  paragraph it was meant to end, and the block it should have attached was
  folded in with it.

- **The writer keeps a `+` continuation marker before an attached paragraph**
  (carve#861). §17 L3 attaches the following block to the item, so `- a` / `+`
  / `b` holds two blocks - but the writer dropped the marker and indented the
  paragraph, which re-parses as a lazy continuation of the one above it, so
  `carve fmt` returned a document saying something else. A paragraph is the
  only attached kind affected: a fence, quote, heading, table, div or thematic
  break cannot fold into an open paragraph.

- **Two markers that reach the same column are one list, however they got
  there** (carve-php#890). PART 9 §24 C1 makes indentation a column claim, so
  a space-plus-tab and four spaces both put a marker at column 4 - but
  dedenting to a content column consumed a straddling TAB whole and dropped the
  columns past it, so one marker arrived at the nested parse still indented and
  the other flush, and a third list opened between them. The same fix makes a
  tab-indented block opener under a list item agree with its space spelling: a
  `>` one column past the content column is text either way.

- **A raw block's interior lines are passed through verbatim** (carve-php#907,
  ruled at carve#800). ```` ```=html ```` means the bytes reach the target
  unchanged, but this renderer indents block output line by line after the
  fact, so every line of a multi-line raw block gained its container's columns.
  Inside a `<pre>` those columns are content, so the rendered code block said
  something the source did not. The opening position is still indented, which
  is where a block goes; carve-js and carve-rs read it the same way.

- **A spoiler is revealed in `static` mode, instead of staying a collapsed
  disclosure** (markup-carve/carve#843). `SpoilerExtension` did not implement
  `StaticRenderExtensionInterface`, so `mode: "static"` produced output
  byte-identical to the interactive form: `<details class="spoiler">` with no
  `open`. A print or PDF engine renders that collapsed, so the body never
  reached the page - silent content loss on exactly the path
  `docs/graceful-degradation.md` exists to rule out. Both forms now degrade the
  way carve-js and carve-rs already did: the block flattens to
  `<section class="spoiler spoiler-revealed">` with the title as an
  `<h3 class="spoiler-title">` and any grouping `[label]` kept as a caption, and
  the inline form gains the `spoiler-revealed` class.

- **A field the schema REQUIRES is published even when it holds the default**
  (carve-php#915). The encoder omits a field carrying the node's default, which
  is right for an optional field and is how the payload stays small - but for a
  required one the result is not a smaller equivalent tree, it is a tree the
  format rejects. An emptied `definition_description` published without
  `children`, which `npm run ast:check` reports as a schema violation. Five
  more were latent the same way and are fixed in the same pass:
  `definition_term.children`, `footnote.label`, `footnote.children`,
  `citation_group.items` and `citation_group.raw`.

### Added

- **`AstDecodeException` for every AST-decoding refusal** (carve-php#912). The
  decoder already refused a foreign root, an unknown node type, a missing
  required field, a wrong encoding version, an over-deep payload and a property
  the schema does not name, naming what was wrong each time - but it threw a
  bare `RuntimeException`, so a caller could not tell "this payload is not a
  Carve AST" apart from a bug in their own code. PART 12 §9(b) and §11 require
  a typed failure. The new class extends `RuntimeException`, so existing
  catches keep working.

### Fixed

- **`fmt` keeps attributes written in BOTH authored positions** (carve-php#839).
  `class` is the one attribute that merges rather than replaces, so a `{.lead}`
  line above an image and a `{.trail}` block at the reference arrive on the node
  as the single string `lead trail` - equal to neither source. The subtraction
  that keeps the writer from repeating what the reference already states compared
  whole values, so it removed nothing and the line came out `{.lead .trail}`
  beside a reference that already said `.trail`, growing by one duplicate per
  pass. Class tokens now subtract per token, and a class on the DEFINITION no
  longer hides the reference's own when both are present.

- **`fmt` keeps an `#id` on a definition's attribute block** (carve-php#831). The
  definition node recorded its slot order with raw keys (`id`, `class`) where every
  other node uses the marker spelling (`#id`, `.class`), and the writer's raw-`id`
  branch returns early on purpose so a key cannot be written twice - so the id was
  dropped from the line entirely. The corpus document that carries it has the
  reference site override the id, so the HTML was identical and the engine's own
  round trip stayed green.
- **`fmt` keeps a block-attribute line above a captionless reference image.** A
  reference image is written as its authored `rawRef`, which cannot carry an
  attribute block that came from the line ABOVE it, so `{#f}` was lost outright -
  this one did break `toHtml(fmt(x)) == toHtml(x)`. Written back as the line it
  came from, which is where carve-js and carve-rs keep it.
- **A definition's attributes are actually subtracted at the reference site now.**
  `renderAttrsExcept()` subtracted through a node COPY, and both
  `setAttributes()` and `setAttributesWithOrder()` merge into what the node
  already holds - so every removed key went straight back and the subtraction
  could not fire on any input. It renders the attribute list directly instead.

- **`fmt` keeps a lone table span marker padded.** Glued to the opening pipe,
  `<` is also the LEFT-ALIGNMENT sigil, and the two readings differ: the
  executable spec reads `|<|` as alignment on an empty cell where all three
  engines read a colspan (carve#710). The writer was turning the unambiguous
  `| < |` the author wrote into the ambiguous form, so a table formatted here
  and read anywhere else could change meaning. `^` takes the same shape; a cell
  attribute stays glued to the pipe, where the grammar puts it.

- **The canonical writer escapes a caption marker only where a caption can
  form** (PART 11 §2, #758). A `^ ` at the start of a block line opens a
  caption only when the block before it can HOST one - a table, a code block, a
  block quote, or a paragraph holding nothing but an image or display math. The
  writer escaped it from the line position alone, so `para` / `^ cap` came back
  as `\^ cap` for a construct that cannot form there, and an unresolved
  reference image - which cannot host a caption either (#751) - took the escape
  with it. carve-js already emitted the minimal form; carve-rs has the same
  defect (markup-carve/carve-rs#565). Where the caption WOULD form the escape
  is load-bearing and stays.

### Fixed

- **A floating attribute does not cross a list-item boundary** (PART 9 §15 A2a
  and A4, #757). `- a` / blank / `  {.c}` / `- b` put `class="c"` on the SECOND
  item's paragraph here: the pending-attribute run is parser state, so an
  attribute written inside one item that found no block there simply survived
  into the next item's parse. A2a floats to the next VISIBLE block and A4 drops
  a run that reaches the end with nothing to attach to - the item boundary is
  such an end, and an attribute reaching into the next item would make a `{…}`
  line's effect depend on where the list happens to break. carve-js and the
  executable spec both drop it; the verdict is markup-carve/carve-js#620. An
  attribute and its target INSIDE one item are unaffected.

### Fixed

- **An unresolved reference image is not a figure** (#751). `![a][nope]` with a
  caption line under it was promoted to a `<figure>`: the label resolves to
  nothing, so every writer emits the author's source text and there is no
  rendered image for a caption to attach to. carve-js and carve-rs both decline
  the promotion. The writer then made it worse - the figure came back out as
  `![a]()`, losing both the label and the destination, so
  `to_html(fmt(x)) != to_html(x)` inside this engine alone. Declining the
  promotion fixes both halves, because the inline path already emits the
  authored source PART 12 §3a keeps in `rawRef`.

  The caption line's INTERRUPTION test had to move with it: a paragraph the
  caption cannot attach to must not be split by it either, or `^ cap` became
  its own paragraph where the other two engines fold it in. A resolved image -
  inline or by reference - still becomes a figure.

- **A marker-line colon fence needs its body at the content column** (PART 9
  §24 C3, #748). `- ::: note` with a flush-left `body` built an admonition
  here; §24 C3 puts a line below the item's content column outside the item
  body, where with no blank it lazily continues the item's paragraph - so the
  opener is literal text and takes the following lines with it, which is what
  carve-js, carve-rs and the executable spec all render. The item's collected
  stream had lost that geometry: the opener sat at the stream's own column 0
  with the body under it, exactly the shape the div parser builds a container
  from. A body AT the content column, with or without a blank line before it,
  still nests as before.

### Fixed

- **The canonical writer escapes a colon only where one can open something**
  (PART 11 §2 and §4, #743). A colon opens a construct at the START of a line -
  `::` a definition term, `:::` a div - and mid-line it is ordinary
  punctuation, so escaping every colon produced `\^ Figure 1\: moon` for the
  indented caption in `158-indented-image-and-caption-stay-literal`. The caret
  escape there is real; the colon rode along with it, and once the caret is
  escaped the line is a paragraph and nothing reads the colon. carve-rs emits
  the same bytes as this engine now, and the spec's fmt fixture pins it.

### Fixed

- **An invisible construct in a list item does not loosen it** (PART 9 §17 L1,
  markup-carve/carve#621, #744). L1 loosens an item that holds a
  blank-line-separated second PARAGRAPH, and a comment or a definition renders
  nothing at all - so `- a` / blank / `  %% note` came back as
  `<li><p>a</p></li>` here, an item wrapped in `<p>` because of a line the
  reader never sees. This engine was the only one loosening for BOTH shapes.

  L1's other clause is untouched: an item FOLLOWED by a blank line before the
  next sibling marker is loose either way, and an invisible line in that gap
  does not fill it - `- a` / blank / `  %% note` / `- b` stays loose. The
  corpus pins the pair as `87-compact-list-blocks-4`/`-5` against `-6`.

  The post-blank looseness test now routes through the shared predicate instead
  of a second spelling of it.

### Fixed

- **A definition below every content column folds instead of vanishing**
  (#721, PART 0 S4). One column in, a definition opens nothing - it reaches
  neither the sub-list's content column nor the outer item's - so with the
  item's paragraph open it is lazy text, which is what this engine already did
  for a marker, a heading, a quote and a table row. A definition was the one
  kind still being CONSUMED: the collector pushed the line trimmed, which put
  it at the item's own column 0, where the block parser skips it as an
  already-extracted definition and renders nothing. `- - a` / ` [^f]: x` lost
  the second line outright, and the same held for a link reference definition,
  an abbreviation definition and a `%%` comment. Matches carve-rs and the
  executable spec.

### Added

- **A reference definition carries trailing attributes** (PART 9 §16, PART 9R
  R1, markup-carve/carve#612).

  ~~~
  [ex]: https://example.com {.external}
  ~~~

  attributes the DEFINITION, and every link resolving `ex` gets them. The
  link's own attributes override per key under the §15 A3 merge - definition
  list first, link list second - so `[ex]: /u {.external #a}` with
  `[E][ex]{.internal #b}` renders `class="external internal" id="b"`: classes
  accumulate in source order, the repeated key takes the link's value.

  The block is SCANNED rather than regex-matched, because a value may hold a
  `}` inside quotes and a lazy `\{[^}]*\}` drops every attribute on the line
  silently. It must be preceded by whitespace and end the line, so
  `[a]: /u{.x}` keeps the braces in the destination. The definition's SOURCE
  ORDER survives onto the link, which needed the ordered attribute parser:
  the plain one hoists `class` to the front.

  This is the slot R1 always assumed - the `linkDefs` symbol table has an
  `attrs` field the production could not fill - and it replaces the spelling
  §15 A2a took away in the same release.

- **A resolved crossref publishes its destination** (PART 12 §3a,
  markup-carve/carve#614, #735). `</#intro>` serializes as
  `{"type":"heading_ref","target":"intro","href":"#Intro"}`: `target` keeps the
  id the author wrote, `href` carries the id it resolved to, case-preserved
  through the case-insensitive fallback. An unresolved crossref keeps `target`
  and no `href` - the absent field is what says it did not resolve.

  Only the authored half was published before, so a consumer decoding the tree
  had to rebuild the heading-id table, apply the fallback and handle the
  not-found case just to render a crossref - the recomputation §5 exists to
  prevent. Rendered output is unchanged on every target.

  The stamp runs in the AST codec rather than on the render path, because the
  tree is serialized without rendering. It sets `href` and nothing else: the
  rest of the cross-reference pass rewrites the tree for rendering (flattening
  nested links, turning a quoted crossref into text), which the AST must not
  show.

### Changed

- **BREAKING: an attribute line above a reference definition floats past it**
  (PART 9 §15 A2a, markup-carve/carve#529, #702). Pending attributes attach to
  the next VISIBLE block, and a definition renders nothing, so

  ~~~
  {#i}
  [f]: u

  e
  ~~~

  is `<p id="i">e</p>`. This engine used to hand those attributes to the
  DEFINITION instead, and every link resolving that label carried them - so
  `{.external}` above `[ex]: …` classed the link and never reached the block
  the author wrote it above. The behavior was carve-php's alone; carve-js drops
  such attributes entirely and the executable spec already floats them. The
  clause names all five invisible kinds, and this engine already floated past
  the other three.

  The replacement is the definition's OWN line, added in the same release
  below: `[ex]: /u {.external}`. Anything relying on the old line-above form
  moves the block onto the definition line.

- **BREAKING: a renderer refuses at its ceiling instead of truncating** (PART 9
  §25, markup-carve/carve#548, #702). Reaching the recursion ceiling now throws
  `RenderDepthExceededException`, a typed failure naming the bound and the
  renderer, where every renderer used to return what it had produced so far -
  the nested markers with the BODY dropped, a document that looks complete and
  is not, with nothing in the return value to say so. The clause is explicit
  that this makes a renderer FALLIBLE in implementations whose signature says
  it cannot fail, and prefers that to a caller told nothing.

  No document `parse()` produces can reach a ceiling: the block cap is 200, the
  inline cap 100, and the ceilings sit above their sum (232 for the canonical
  writer, which counts block and inline depth separately; 512 for the HTML,
  Markdown, plain-text and ANSI renderers, which share one counter). What
  refuses is a tree built through the API or decoded from JSON - where the
  caller built it and can act on the failure. The CLI reports the refusal on
  stderr and exits 1 rather than writing a partial document.

### Fixed

- **A below-column line folds at every depth, not only one column in**
  (PART 9 §24 C3, carve#603). The dedented-opener fold forwarded the line with
  its own indentation, which two columns in REACHED the sub-list's content
  column inside the re-parsed stream and opened a list there - `-   x` /
  `    - a` / `  - b` nested `b` under `a`, as it did in all three engines. A
  folded line now carries exactly one column, which reaches no content column
  at all. At the content column a marker still opens a sublist, and at the base
  column it is still a sibling.

- **The canonical writer stops escaping a caret that opens nothing** (PART 11
  §2, markup-carve/carve#581, #702). §2 escapes a character IF AND ONLY IF
  omitting the escape would change the re-parsed AST, and a lone `^` opens
  nothing now that superscript is braced-only - so `}^p` was written `}\^p`
  for a construct the language no longer has, and `a ^sup^ b` gained two
  backslashes per pass. The caret keeps its escape exactly where it abuts a
  shape that still reads it: `^[` (inline footnote) and the `{^` / `^}`
  delimiters of a braced superscript. A caption-shaped `^ ` at the start of a
  line is decided by the block writer and is unchanged. carve-rs escapes the
  same carets this engine used to; carve-js additionally escapes the `}`.

- **An empty word-class div keeps its blank body line** (PART 10 §4,
  markup-carve/carve#570, #702). A container whose body renders nothing keeps a
  blank line where the body would be; the one exception is a BARE `:::` div,
  which closes on the next line. This engine applied the exception to both, so
  `::: b` / `:::` came out `<div class="b">` + `</div>` on consecutive lines
  where carve-js and carve-rs keep the blank line between them. The split is on
  the OPENER's spelling, not on whether the div ends up with a class: `{.b}`
  above a bare `:::` stays compact, which is what the other two do as well. Of
  the four shapes this clause names, the word-class div was the only one
  nothing in the corpus pinned - which is why it drifted.

- **An over-cap opener groups by the ordinary paragraph rule** (PART 9 §25,
  markup-carve/carve#547, #702). Past MAX_NESTING_DEPTH an opener degrades to
  literal text, and the clause is explicit that a flattened opener is ORDINARY
  PARAGRAPH TEXT: consecutive over-cap openers and the text after them form ONE
  paragraph, ending at the first blank line, with no trailing newline before
  `</p>`. The degrade path handed the whole remainder to a single paragraph, so
  the document's trailing newline stayed inside it (`x` + newline + `</p>`) and
  a blank line - which ends a paragraph everywhere else - was swallowed along
  with everything after it. carve-rs also keeps the blank line inside the
  paragraph; that half is unreported against it so far.

- **A dedented opener after a following-line sub-list folds as text** (#706).
  A line below BOTH the sub-list's content column and the outer item's opens
  nothing under the strict content-column rule, so while a paragraph is open it
  is a lazy line (PART 0 S4). This collector ended the item on it instead: with
  `- x` / `  - a` / ` - b` both lists closed and `- b` re-opened as a NEW
  top-level list. The marker-line collector already folded the same shape
  (#693), so the engine disagreed with itself about one line. The folded line
  keeps its OWN indentation, so the nested parse decides from the column - the
  same mechanism #693 uses one level up. A marker at the sub-list's marker
  column or at the base column still opens a sibling, and a blank line still
  leaves nothing to fold into. A dedented heading, quote, table row and
  colon-fence opener move with the marker; an indented CODE FENCE and a
  floating attribute line in that position are unchanged here and still differ
  from carve-js.

- **A marker-line sub-list holds an open paragraph like any other** (PART 0 S4,
  #693). `- - a` / `b` ended the list and made `b` a document paragraph, where
  the same lines with the sub-list on its own line already folded `b` into the
  sub-item - so the engine disagreed with itself, and with carve-js, carve-rs
  and the executable spec. The combined stream the marker-line branch collects
  now tracks its trailing block, so a dedented line folds only while a
  paragraph is open; a blank line, a closed fence, a sibling marker or a
  base-column block opener still ends the item.

- **A block boundary no longer depends on a line starting with a pipe** (#683).
  The block-start test accepted ANY line beginning with `|` as a table, while
  the paragraph-interruption test beside it validated the row first. So a
  column-0 `|` after a list item detached from the item, where `*`, `-` and `x`
  all attached - one shape answered two ways for no reason but the character.
  The row is validated in both places now (as carve-js does), so a pipe in
  prose is prose and `| a |` still opens a table. Validation accepts exactly
  what the table parser accepts, continuation path included: a row that opens a
  code span (`| ``a |`) becomes a row once a `+` row closes the span, and it
  still breaks out of the item. A blank line followed by a bare `|` inside an
  item now loosens it, for the same reason - the pipe is prose, so the item has
  two blocks - which is also what carve-js does. What a column-0 line after a
  container SHOULD do is still open across the engines
  (markup-carve/carve#561); this only makes this engine answer it consistently.

- **The definition pre-pass reads a container's closer at the depth it opened
  at** (#685). The scan stripped every leading `>` before testing for a closer,
  so inside `> ``` ` a nested `> > ``` ` - which is quoted code content, not a
  closer - ended the guarded region, and a `> [^a]: note` after it registered a
  footnote from inside a code block. The same depth-blind test governed the line
  block guarded in #691, so both now compare at the depth the region opened at,
  and a line that leaves the blockquote ends the region with it. The line-block
  guard moves onto the shared `parseLineBlockOpener` / `isDivFenceCloser`
  helpers in the process, so the pre-pass and the real parser agree on what
  opens and closes one instead of each carrying its own pattern.

  A footnote definition inside a line block was already literal as of #691;
  this does not change that answer, only how the region is tracked. Whether a
  line block's body should register definitions at all - link references still
  do, in all three engines - is markup-carve/carve#557.

- **A sub-list lead does not exempt its item from the looseness rule** (PART 9
  §11, #681). An item whose content begins with another list marker is
  collected as one combined stream, and that path never ran the looseness scan
  the plain path runs - so `- - a` / blank / `  b` stayed tight where `- x` /
  blank / `  b` went loose, on the same blank line. The outer item holds two
  blocks either way. Content at or past the SUB-LIST's own content column still
  belongs to the sub-list and does not propagate its looseness outwards, which
  is the case the corpus already covered and why this looked covered too.

- **An unresolved reference is a link node, not reverted source** (PART 12 §3a,
  #624). `[missing][nope]` with nothing defining the label was flattened to an
  internal `raw_text` node, a type the AST vocabulary does not have: five corpus
  documents serialized to JSON the schema rejects, and none of them could
  satisfy §6, because a decoder had nothing to rebuild the node from. The
  reference now stays a `link` (an `image` for `![alt][nope]`) carrying `ref`
  and the new `rawRef` field with the verbatim source, which is what carve-js
  publishes; every writer renders that source, so no rendered output changes.
  The node count matches the other engines too, where `![alt][nope]` used to
  split into two nodes and `[a][nope]{.c}` into three. Nothing produces
  `raw_text` any more; the codec still decodes a stored payload that names it.
  The resolved-form spelling of `ref` and `href` is markup-carve/carve#524.

- **The canonical writer keeps a heading-derived reference in its authored
  form.** A reference resolved against a heading (PART 11 R1) has no
  `[label]: url` line, so `[getting started][]` is the only record of what the
  author wrote - and `fmt` replaced it with `[getting started](#Getting-Started)`,
  baking a generated id into the source and doing it again on every pass. This
  was the last document on which the three engines disagreed about the canonical
  target apart from the abbreviation hoist (carve#478). An EXPLICIT definition
  still writes the resolved link, which is what all three engines do there.

- **The decoder stops recording a source slot for a structural class.** An
  admonition's kind class is derived from the opener word, and the parser
  records no slot for it - but `applyDerivedFields` wrote it with
  `setAttribute()`, which records one unconditionally. So
  `decode(encode(parse(x)))` came back with `attrs.order` = `["title",
  ".class"]` where `parse(x)` had `["title"]`, breaking PART 12 §6 for
  `42-admonitions-5` (an attribute line carrying `title=` above an opener
  title). HTML and Carve output were identical, which is why every existing
  round-trip check stayed green; a corpus-wide §6 comparison now covers it.

- **The canonical writer emits a line block's medial gap as plain spaces.** A
  line block preserves an inner or trailing run of two or more columns the way
  it preserves the indent (PART 9 §23), but the writer only routed the LEADING
  run back to plain spaces. Off the parser that looked correct by accident: the
  gap is a text node of its own there, so it started at offset 0 and matched the
  leading-run rule. On a coalesced tree - what a JSON round trip produces, and
  what any programmatically built document looks like - the run sits mid-node
  and came back escaped, so `Two roads    diverged` was written as
  `Two roads\ \ \ \ diverged`: the same HTML, a different document. A LONE inner
  sentinel is still written as `\ `, because only an escaped space can produce
  one.

- **A citation-key line no longer interrupts a paragraph.** `[@key]: …` is not a
  link reference definition here - `@` is excluded from a label so the line
  stays with `CitationsExtension` - but the interruption predicate matched it
  anyway, so a hard-wrapped prose line followed by a bibliography entry ended
  the paragraph and the entry reappeared as a second, visible one. carve-js and
  carve-rs continued the paragraph. The predicate now accepts exactly what the
  definition parser accepts, and the two interruption sites share it so they
  cannot drift apart again.

- **`fmt` no longer splits a heading it was given.** A heading ends at the
  newline, so its text must not contain one. No parse builds such a heading, but
  PART 12 lets an ingested AST put any inline in one, break nodes included -
  writing that out verbatim closed the heading and re-parsed the remainder as a
  following block, moving text out of the title. Breaks now collapse to a single
  space. Only an odd run of backslashes before the newline is a hard break's
  marker, so a literal backslash ending a line survives.

### Changed

- **BREAKING: a heading ends at the newline** (spec markup-carve/carve#451,
  markup-carve/carve#434). Nothing folds into a heading any more - neither a
  plain line nor a same-count `#` line - so `# Title` with prose beneath is a
  heading plus a paragraph, and its id comes from the heading line alone
  (`Title`, not `Title-Some-text`). Two documents that relied on the fold change
  meaning; everything with a blank line after the heading is unaffected.

  The fold was a silent corruption for anyone arriving from Markdown: the title
  text and the derived id were both wrong, `</#id>` cross-references and TOC
  anchors broke, and the intended body paragraph disappeared into the title with
  nothing to report. Lazy continuation now means one thing across the language -
  it continues an open paragraph - and a heading is not a paragraph.

### Removed

- **The `heading-lazy-continuation` lint rule.** It reported the fold above;
  with the fold gone the rule describes behavior the engine no longer has.
  `MarkdownHabitLinter` keeps its doubled-delimiter rules.

### Fixed

- **An implicit `[Heading][]` reference now reaches a heading inside a list
  item, and declines a quoted one on purpose** (#572, spec PART 11 R1). The
  explicit `[text][Label]` form, which resolves against headings too, had the
  same hole and is fixed with it.

  The index came from a line-based pre-scan matching `^#{1,6}` at column 0, so
  which headings it found came down to source indentation: a div's inner lines
  start at column 0 and were indexed, a list item's are indented and were not,
  and a blockquote's carry `>` and were not. Two of those three answers were
  right and all three were accidents - this engine had never implemented R1's
  blockquote rule, it just never saw past the prefix.

  The index is now built from the parsed tree by `HeadingReferenceCollector`,
  which asks what the rule asks: does this heading have a blockquote ANCESTOR,
  in either nesting order. Because references resolve during inline parsing and
  the tree does not exist until parsing finishes, a document whose reference
  needs a heading the first pass could not reach is parsed a second time with
  the index seeded. A document without one is unaffected; one that needs the
  second pass costs about 2x.

  Found by the combinatorial check in markup-carve/carve#452.

### Added

- **`ProseMirrorToCarve::register()`**, so an application's own editor nodes
  convert. The published map is CarveKit's vocabulary, and a name outside it
  was rejected - right for a typo, and wrong for a node like
  `placeholderToken`, whose name cannot go upstream because nobody else has it.
  "In the map" and "throw" were the only two states; this is the third, the
  same door `AstCodec::register()` and `CarveConverter::addExtension()` already
  open on the other surfaces.

  ~~~ php
  $converter->register('placeholderToken', function (array $data): Node {
      $span = new Span();
      $span->addClass('placeholder');

      return $span;
  });
  ~~~

  The factory returns the node SHELL, exactly where `instantiate()` sits, so
  attributes and children come from the normal path - an application gets
  `data-*` passthrough and nested content without reimplementing either. A node
  answering `InlineNode` is treated as inline, so both kinds work.

  Registration is per converter INSTANCE rather than static: two converters in
  one process do not share a vocabulary, and a test cannot leak into the next.
  An unregistered name still throws, so nothing becomes silent.

### Fixed

- **A heading inside a list item or a definition resolves an implicit
  `[Heading][]` reference** (markup-carve/carve-php#572, PART 11 R1). The rule
  puts headings in divs, admonitions, list items and definitions in the index
  and excludes only a blockquote ancestor - another document's headings are not
  the author's to reference. This engine indexed by scanning source lines for a
  `#` at column 0, which answers a different question, so it was wrong in both
  directions: a heading inside a list item was missed because it is indented,
  and a `#` line inside a CODE FENCE was indexed because it is not, giving a
  reference that resolved to an id no element carried.

  Two false warnings came with the miss and are gone with it: the reference was
  reported undefined, and a plain `[x](#H)` link to the very same heading was
  reported as a broken anchor.

  The index is now taken from parsed block structure rather than from a line
  scan, by the same document-order walk the renderer already uses to resolve
  heading ids - so the two agree by construction instead of by mirroring each
  other. That costs one extra block parse, and only for a document that could
  use the index at all: `[text][ref]` and `[text][]` both contain `][`, and
  without one the index is built and never read. Across the spec corpus 14 of
  504 documents qualify.

- **A destination-less mention keeps its attributes, and the source renders as
  the node did** (markup-carve/carve-php#567). An attribute set on a `Mention`
  reached the AST and the HTML and then vanished from Carve source: a bare
  `@alice` has nowhere to hang one, since the parser leaves a trailing `{.x}`
  outside the node, and the link form needs a destination.

  The writer now spells out the form such a mention RENDERS as -
  `[*\@alice*]{.mention #x}` - so `toHtml(fmt(x)) == toHtml(x)` holds byte for
  byte. Three pieces are each load-bearing: `*…*` supplies the `<strong>` that a
  mention with no URL template renders (corpus-pinned, so it is the target
  rather than a choice); the escaped sigil keeps the label as text instead of
  re-parsing as a second mention inside the span; and the class is written
  first, because a span renders its attributes in source order.

  What it costs: re-parsing gives a span holding strong text rather than a
  Mention node, so the node TYPE does not survive - the HTML and every value do.
  Four shapes have no exact spelling and keep the bracketed fallback, which
  loses nothing but gains a wrapper `<span>`: markup inside the label, an empty
  label, a label padded with whitespace, and a mention with no css class.

  Reachable only from a programmatically built tree or the ProseMirror bridge -
  a stock Tiptap mention carries its `id` this way - so no parsed document
  changes.

- **An unresolved reference publishes as `text`, not as `raw_text`**
  (markup-carve/carve-php#531, PART 12 §5). `raw_text` holds markup the parser
  DECLINED - the `[a][]` of an unresolved reference - which the Carve writer
  needs so it can reproduce the source verbatim instead of escaping brackets it
  never interpreted. §5 excludes such a node from the wire, the published schema
  has no entry for it, and both other engines emit three text nodes for
  `see [a][] here`. This engine published a fourth type nothing accounted for.

  The mapping is on the way OUT only, which is what §1 licenses. The live tree
  still holds the node, so `fmt` is unchanged and every corpus document still
  formats to its authored bytes. What is lost is the authored form AFTER a round
  trip through the JSON: `[a][]` comes back as `\[a\]\[\]` for four corpus
  documents, named in the round-trip gate so a new loss and a fixed one both
  fail it. HTML is unchanged everywhere.

  `AstCodec::schema()` no longer advertises the type either. It is built by
  reflection over the node classes, so an internal one was published by default;
  `AstCodec::NOT_ON_THE_WIRE` now names the exception in one place. Decoding
  still accepts a payload that carries the node - this engine wrote such
  payloads and a stored document cannot be recalled - so `AstCodec::VERSION` is
  unchanged.

  With this and markup-carve/carve-php#557, no conformance finding against the
  published schema remains for this engine over the whole corpus. The 26 that
  are left are all missing positions (PART 12 §4).

- **The canonical writer stops inventing a mention name.** `escapeName()` was
  named escape and DELETED: every character outside `[\w.-]` was dropped, so a
  mention labelled `o'brien` was written as `@obrien` and `Mark Scherer` as
  `@MarkScherer` - a different mention, pointing at a different user, with
  nothing in `droppedTypes()` or `degradedTypes()` to say so.

  A mention name is `name_word ('.' name_word)*` and carries no escape, so a
  label holding anything else has no spelling in that syntax. It now degrades
  to the link form - `[o'brien](/u/1){.mention}` - which keeps the label, the
  destination and the class, and renders the same anchor. A label that IS a
  valid name is written as `@name` exactly as before.

  The name test uses the character set the parser accepts, which is ASCII, not
  the wider `\w` the old code matched on: `@Jörg` would have been re-read as
  `@J` followed by literal text, so emitting it would have replaced one silent
  corruption with another. The `#tag` branch shares the path and the fix.

  Three neighbours of the same defect, found while covering it:

  - **An attribute on a mention is carried rather than dropped.** A trailing
    `{.x}` after a mention stays literal text - the parser leaves it outside the
    node - so `@name` cannot hold attributes however spellable the name is, and
    `@user{#x}` was written as `@user`. It takes the link form too.
  - **Nested markup in a label is carried rather than flattened.** `@*user*` is
    not a mention, so a mention whose label holds emphasis was written as
    `@user`, dropping it.
  - **A doubled sigil is no longer eaten.** The sigil was stripped with `ltrim`,
    which removes a run of them, so `@@user` was written back as `@user`.

  The fallback link is now a CLONE of the mention. Building it by appending the
  mention's children reparented them, leaving every label child of every written
  mention pointing at a throwaway node - invisible in the output, so only the
  tree shows it.

- **The ProseMirror bridge reports an attribute it cannot carry.**
  `applyAttributes()` passed unconsumed attributes through with an
  `is_scalar()` check and no `else`, so a non-scalar value fell off the end of
  the loop and was discarded without a word. The case with a real producer
  behind it is `colwidth`: Tiptap's table extension stores column widths as an
  array, so `Table.configure({ resizable: true })` plus one drag puts one in
  the stored document.

  `ProseMirrorToCarve::droppedAttributes()` now names each one and why, the
  mirror of `ProseMirrorRenderer::droppedTypes()` for the other direction. A
  `null` is not reported - that is how the editor spells "unset", so it carries
  nothing to lose.

  Reported rather than encoded, deliberately: a joined string would come back
  as a string and not an array, and a JSON-encoded one would put an
  unauthorable value in source. Which of those is right is still open
  (carve-php#541); being silent was not.

### Added

- **`setSectionWrapping(false)` renders headings without the `<section>`
  wrapper** (markup-carve/carve#427, spec PART 9 §13). The id goes back on the
  `<h*>` alongside its other attributes, and the blocks that would have been
  section children stay as siblings. On by default, so existing output is
  unchanged.

  The wrapper is the one output change that breaks a site whose source migrated
  cleanly: CSS and JS assuming rendered blocks are direct children of the
  content container stop matching once a `<section>` sits in between.

  Implemented by routing the heading through the path a heading inside a
  container already uses, rather than writing a second renderer. The endnotes
  `<section role="doc-endnotes">` is a different construct and is unaffected.

### Changed

- **Frontmatter is no longer claimed after a block-attribute line.** `grammar.ebnf`
  pins `document = [frontmatter], {block}, EOF`: frontmatter is the document's
  first production, so nothing may precede the opener. A block-attribute line is
  a block, so once one appears the `---` fence beneath it is ordinary content.

  This engine previously accepted `{.meta}` above a fence and attached the
  attributes to the frontmatter node, which no other implementation does.
  carve-js, the reference engine, renders

  ```
  {.meta}
  ---yaml
  title: Test
  ---

  Content.
  ```

  as a classed paragraph, a thematic break, and a paragraph; carve-php swallowed
  the fence as metadata and emitted only `<p>Content.</p>`. Both engines now
  produce the same HTML.

  **This is a behavior change.** A document relying on attributes attached to
  frontmatter loses them, and its fence becomes visible content. Frontmatter at
  the very first line is unaffected.

### Fixed

- **An auto-generated heading id no longer displaces an id the author wrote**
  (spec PART 10 §1). On an unwrapped heading this engine put the id last in
  every case, so `{#x a=b}` rendered `<h1 a="b" id="x">` instead of keeping the
  author's order. Authored attributes now keep their source order and only a
  generated id joins at the end.

  All three engines disagreed here and none could be wrong: carve-js appended a
  generated id but left an authored one in place, this engine put the id last in
  both cases, carve-rs put it first in both. The combination was reachable only
  through a heading inside a container, and no corpus case gave such a heading
  attributes, so each answer stayed green. carve-js is canonical.

- **A ProseMirror table cell keeps the text inside its required paragraph.**
  Tiptap stores every table cell as block content, usually a paragraph, but the
  Carve source form for a table row can only hold inline cell content. The
  bridge built `TableCell > Paragraph > Text`, which still rendered as HTML but
  serialized as an empty Carve cell because the Carve renderer only writes a
  cell's inline children.

  The converter now lifts inline content out of ProseMirror cell blocks before
  rendering back to Carve source, and marks travel with it.

  A Carve table row is one line, so two things inside a cell have no form and
  both degrade to a single space, at every depth of the lifted subtree: a block
  boundary, so a cell holding two paragraphs or a list keeps its word boundaries
  rather than running the text together; and a hard break, which is an ordinary
  shift-enter in any editor and would otherwise be written as a backslash line
  break, ending the row so the whole table reparsed as a paragraph.

  The source-first corpus sweep did not catch any of this because Carve's own
  parser cannot construct the ProseMirror-only paragraph-wrapped cell shape.

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
