# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 0.1.8 and earlier are in [CHANGELOG-0.1.md](CHANGELOG-0.1.md).

## [Unreleased]

## [0.1.10] - 2026-09-25

### Breaking

- `StoredPayloadUpgrade` and the tailored diagnostics for pre-PART 12 payloads are removed. An obsolete stored payload now fails ordinary AST schema validation, so an application holding one must upgrade it with carve-php 0.1.9 or earlier first (#2259).
- `BlockParser` loses its three protected definition collectors and `ReferenceDefinitionExtractor` loses `extract()` and `getLayoutEvents()`, together with the five classes that served only that scan; the methods the parser itself calls stay (#2248, #2249).
- AST ingest refuses a container-internal type inside a `children` array and a `footnote_ref` with no target, accepts a `citation` with no `pos`, and `AstCodec::schema()` drops `pos` from the citation's required list (#2271, markup-carve/carve#2197).
- The AST schema validator honors `not`, so a payload the keyword was written to reject is refused where it used to be accepted (#2409).
- Every empty block container keeps one blank HTML body line, so divs, line blocks, local hard-break blocks and figure groups take the body shape admonitions and block quotes already had (#2256, `CARVE-P10-001`).
- An empty emphasis-family mark throws `SourceUnspellableException` instead of writing an empty brace pair, and a mark whose content is edged with any whitespace is written braced (#2207).
- A parenthesized link or image title is prose, the grammar having only the two quoted spellings (#2195).
- A generated-content `:::` kind parses as a directive rather than an admonition (#2329, markup-carve/carve#2225).
- A task checkbox is read only where the tasklist extension reaches, and a state cmark-gfm never accepted is escaped, so `[-]`, `[_]`, `[>]` and `[?]` import as bracket text (#2376, #2415).
- A `"` inside a quoted fence or directive title is dropped and reported as a loss. The slot has no escape mechanism, and the `\"` the writer used to spell closed the title early and handed the rest of the opener to the paragraph reader (#2398).
- The raw-keep report is read off the output instead of a tag roster, which changes which rows an HTML import reports (#2361).
- The ProseMirror wire contract follows the pinned carve-grammars schema: substitution halves travel as inline arrays, comment and literal or raw inline text sit in editable child nodes, block positions are restored, and table cells carry inherited alignment. Older attribute-based inline payloads still read (#2407).
- The render-loss `code` enum closes at `raw-format-dropped` and `ruby-flattened`, and a table section's discarded attributes are reported as `field-unspellable` on the PART 11 §1d channel instead. Markdown, plain and ANSI reach that channel for the first time, `--report-conversion-diagnostics` is no longer gated on `--carve`, and `--allow-loss` accepts two names where it accepted three, so a consumer matching `table-section-attributes-dropped` reads the new code (#2479).
- Escaped spaces and preserved line-block columns travel as `non_breaking_space` nodes, U+E000 is literal content in every field, and annotation offsets are a fixed codepoint projection independent of JSON key order, image alt text, math, breaks and generated spaces included. A tree stored under the old marker emits that character raw into HTML with no error and no version signal, because the AST contract stays `1.0`, and reparsing the source is the only remedy (#2468).

### Fixes

- The Markdown target keeps every block a list item holds. A continuation line is
  padded from the item's marker rather than from a task item's checkbox, and a
  block below a nested list gets the blank line that stops GFM reading it as a
  continuation of the last sublist item (#2446).
- A node pulled in by a sliced include keeps its own file's coordinates, CRLF and multibyte sources included (#2187).
- A definition's destination is read as `link_destination`, and the writer re-escapes what the reader resolved, so one `fmt` pass no longer loses the definition (#2192).
- A link or image title may contain a closing parenthesis, in both quote forms (#2193).
- A fence opener's quoted title is written once instead of escaped again, so a backslash no longer doubles on every `fmt` pass and an admonition title renders the authored bytes (#2422, #2423).
- Combined span delimiters stay literal, so a bare `*` or `/` pair inside `/*...*/` forms no run of its own (#2198).
- A flushed run of text flanks a quote as its own last character, so `[t]("()` curls the quote the right way (#2200).
- A substitution's closer comes from the scan that finds its arrow, stepping over an escape, a closed code span and both comment forms (#2215).
- A braced span's closer scan steps over an escaped backtick (#2223).
- A bold-italic strong is written nested where its content cannot hug `/*`, instead of a combined form that read back as an emphasis holding literal stars (#2204).
- The writer measures the text beside a mention or an inline extension opener at the true end of the written run, so a soft break before `@r` no longer throws `SourceUnspellableException` and a `:name` line above `[foo]` keeps its colon unescaped (#2439).
- A line comment consumes a bare emphasis closer through the end of the line and ends at the combined bold-italic token's closer, and a span holding a line comment on the closer's own line is written braced (#2208, #2229).
- A line comment's content drops one leading space or tab after the `%%` marker and any trailing ASCII space or tab, in the block, inline and verse readers alike; a no-break space and a vertical tab stay content (#2442, markup-carve/carve-rs#1951).
- A line below a description body's column folds into its open paragraph, and the body's fence opens on a closer written below a lazy line (#2217, #2236).
- An empty term marker with trailing whitespace reads exactly as the bare marker (#2219).
- A fence closer counts only at the opener's container column, an item's fence is read one way when its closer lies past a below-column line, and a demoted marker-line colon run is then read on its own (#2229).
- A caption numbers only a bare `#` (#2229).
- A code block a container closes reaches the content below its fence, with the `list` and `list_item` above it following, and an unresolved reference's given-back caption lines carry their positions, so the paragraph no longer ends at the image (#2252).
- A caption slot is settled through the finished document rather than a detached subtree, which also takes peak parse memory on a 321 KB document to 30 MB from 42 MB (#2237).
- A blank inside a sibling sub-list no longer loosens the outer item (#2264).
- The autolink extension decodes backslash escapes in a bare URL (#2258).
- A GFM delimiter row requires a pipe (#2354).
- A quoted continuation line is decided by its column rather than its shape, and a held quote line is measured from the column it stands in (#2351, #2353).
- An interrupting marker keeps interrupting a quoted paragraph (#2358).
- A setext heading folds where a quote, a list item or a quoted item holds it, and a pipe line folds into the heading above it (#2321, #2344, #2363).
- A closed pipe row stays text under an open paragraph and with no paragraph above it, the run is answered once per line rather than rescanned from every row, and a paragraph ends it instead of a second table growing inside a quote (#2364, #2386, #2393).
- A tilde fence behind a task checkbox stays text (#2367).
- A footnote break stays out of a line block's ranges (#2335).
- A `::: footnotes` marker inside a block-level container no longer moves the endnotes section into that container. It renders the PART 9 floor where it stands, and the section goes where the document would have put it without that marker (#2395).
- Generated `::: toc` content stays at column zero through section and container indentation (#2418).
- A directive's title is published and rendered where the region it places can hold it (#2352).
- A spanning table cell publishes its resolved extent (#2309).
- A rowspan crossing a row-group boundary renders in one body group, and a table row whose cells hold only breaks is dropped before rendering instead of refusing the import (#2292, #2435).
- A profile is no longer asked about the internal `Caption` node (#2316).
- HTML rendering writes an ingested citation group's escaped `raw` when the citations extension is off, on every target and through CLI JSON input (#2291, #2293).
- The ProseMirror bridge round-trips definition nodes, and carries abbreviation and citation definitions as their CarveKit nodes with their authored positions (#2346).
- The Markdown importer follows the shared converter corpus, so fences, list markers, nested quotes, renumbering, continuation lines, item tables, tab columns, dash escaping and ordered-marker interrupts match what `carve fmt` writes and cmark-gfm reads (#2266).
- The Markdown importer keeps an imported setext heading in the container that holds it, keeps a non-1 ordered marker under an item paragraph as text, and escapes a list marker only Carve has (#2269, #2273, #2275).
- The Markdown importer writes every imported definition where `fmt` writes it, and uses a comment for an emptied definition item (#2280, #2310, #2311).
- The Markdown importer reads indented code in a block quote as a fence, keeps quoted code inside list items, and keeps a quoted lazy line in the item whose paragraph it continues (#2282, #2283, #2284, #2296).
- The Markdown importer writes imported quotes and verbatim blocks the way `fmt` writes them (#2314).
- A list keeps its tightness on the Markdown target: the separator above a nested list, a nested quote or any other opener that interrupts a paragraph is dropped, and a loose list is spelled with a blank line between its items (#2406, #2416).
- Incidental paragraph indentation is normalized on Markdown import, quoted lines and pipe rows that need escaping after the dedent included, and a thematic break takes the formatter's blank line above every follower (#2420, #2428).
- An empty ordered task checkbox is reported when whitespace follows its marker, and no loss row is claimed where marker padding turns the item content into indented code (#2411).
- An ordered task item's lost checkbox is described the same way at the HTML and the Markdown entry point (#2437).
- The HTML importer keeps every line of an imported block in the raw block, keeps a raw region that reaches a table cell or a caption, and unwraps one a table row cannot hold (#2297, #2370, #2387).
- The HTML importer keeps an unmapped inline's words instead of losing its subtree, and carries a code block, a ruby and a details into an inline-only slot (#2325, #2383).
- A list in a table cell is flattened to its content, without `- ` or `1. ` reaching the cell as text the input never held (#2434).
- A space-encoded destination is no longer reported as a dropped href, and `LinkPolicy` reads the host a browser reads (#2327, #2328).
- A denied-scheme destination is imported as its content (#2334).
- A raw-kept element reports its refused attributes, and a refused declaration inside a preserved `style` is reported as a refusal rather than as information (#2338, #2391).
- A raw HTML import is attributed by the identity of the element that was emitted, so byte-identical twins, a raw `<head>` or `<body>` container and its preserved descendants are reported against the element actually kept, and trusted stored source draws no guessed loss row (#2426, #2429, #2430).
- A MathML element kept as raw HTML is reported as `raw-preserved` rather than reported dropped while present in the output (#2421).
- Blocks flattened into a pipe-table cell are reported at their input paths (#2425).
- An HTML import that reaches its diagnostic cap returns the converted document with a pathless `diagnostics-truncated` row, instead of refusing the import (#2424).
- The BBCode importer spells the four formatting tags the way the Carve writer would, and for the same 26 test posts writes byte for byte what carve-js writes (#2213).
- The BBCode importer escapes what a post's own text forms beside a converted tag, escapes a link it did not write, drops a stray close tag, and keeps a character reference as its text (#2225).
- An explicitly empty container title travels as `title: []`, so a JSON round trip renders what a direct render renders, and the Carve writer keeps the empty title it was dropping too (#2463).
- An empty AST `directive` publishes `children: []` from Carve source and from HTML import, which is what the spec schema requires of it (#2464).
- An unattached continuation marker stays in a nested list. It no longer drops a literal marker at column 1 or moves an indented follower out of the list, and a marker forwarded to an inner list carries its own residual column (#2465, #2467).
- A `+` one column left of an in-item block quote's marker stays text rather than being read as a continuation marker (#2472).
- HTML import keeps a heading, list, code block, table or block quote that sits under two or more nested unsupported elements, rather than flattening it into a paragraph. Importing a GitHub page used to lose every heading, list and code block a README holds (#2474).
- The general `element-unwrapped` row reads `Unwrapped unsupported <x> element`, which is what becomes of an unsupported element in block context, where no span is written (#2475).

### Improvements

- Editor-facing AST APIs for node identity, annotation ranges and provenance sidecars, plus reversible AST patches carrying revision fingerprints, documented in [AST editor sidecars](docs/ast-editor-sidecars.md) (#2456).
- `MarkupCarve\Carve\Ast\AstEnvelope` reads and writes the versioned AST interchange envelope, so a payload from a newer contract, one needing an extension this build does not implement, and a foreign vocabulary are each refused distinctly rather than all arriving as an unreadable tree (#2453).
- The Carve writer exposes a bounded conversion-diagnostics report for the source structures and fields it cannot spell, with the CLI flag `--report-conversion-diagnostics` (#2331).
- Ruby annotations survive AST JSON interchange as ordered base and annotation pairs, with the `base(annotation)` fallback reported as `ruby-flattened` and accepted by `--allow-loss` (#2290).
- Line-block ranges, explicit sections and block table cells decode, render and write (#2319).
- A citation item's own mode is read rather than only the group's (#2322).
- A block extension is read and the fallback it declares is rendered, and an opaque JSON object inside its payload stays an object through both bridges and AST encoding (#2323, #2408).
- The ProseMirror bridge reads and writes directive, block extension, ruby and small-caps nodes (#2402).
- Figure nodes expose `getTargets()`, `getCaption()` and `getCaptions()`, and every renderer, the numbering pass and the linter read a figure through that one decomposition (#2260).
- `carve lint --extension citations` reports a nested `::: references` marker, through the new `ReferencesPlacementLinter` (#2417).
- AST ingest names its standalone `citation` limitation in the error message and in the AST JSON guide (#2287).
- The spec pin moves to carve `05a28fe`, and this engine takes the wire changes it carries (#2254, #2409).
- HTML import loads its DOM through one loader and restores the caller's libxml error mode afterwards (#2332).
- The BBCode list and quote passes copy text up to the next bracket instead of trying two anchored tag patterns at every byte, and converted BBCode that carries no unescaped ASCII punctuation skips the repair parse with output unchanged either way (#2220, #2240).
- Resolving a position reads the document's position index rather than rescanning its line's prefix, which the single-line documents the BBCode repair parse produces made quadratic (#2247).
- A document is reparsed only for a heading the failed label could name, rather than for any unresolved reference (#2246).
- Table heads and feet keep their attributes through AST exchange and HTML import. HTML applies them to `thead`, `tbody` and `tfoot`, and the source and text targets report an attribute they cannot spell (#2473).
- A nested list item's abutting attribute payload is validated once rather than once per enclosing list, and the writer's escape search parses each candidate once rather than re-parsing the document per probe. Together they cut the full re-parses an HTML migration performs by roughly a third, with output and fidelity report byte-identical (#2477, #2478).

## [0.1.9] - 2026-09-19

### Added

- `ProseMirrorToCarve::degradedAttributes()`, a second report channel for what a conversion carried in a lesser form (#2175).

### Changed

- **A mention loss that keeps the text is reported as degraded, not dropped** (#2175). A display label that differs from the `id`, a label that is not text, and a name the mention grammar rejects move from `droppedAttributes()` to `degradedAttributes()`, keyed and worded as carve-rs and carve-grammars write them. An attribute on the text path stays dropped, and its reason names the node kind, so a tag is no longer described as a mention.

### Fixed

- **The ProseMirror bridge drops a mention or tag that carries no name and reports it** (#2176), so a node with neither an `id` nor a `label` is left out and named in `droppedAttributes()` under its node kind, no field having held a name. It used to reach the writer, which refused the whole document. `CarveRenderer` still throws for a tree an API caller builds that way.
- **A caption's `#` placeholder is literal inside inline markup** (#2181, markup-carve/carve#2112). `^ a *# x* b` keeps its `#`, a later top-level `#` still numbers, and the Carve writer stops escaping the bare one, which is what the other engines write.

[Unreleased]: https://github.com/markup-carve/carve-php/compare/0.1.10...HEAD
[0.1.10]: https://github.com/markup-carve/carve-php/compare/0.1.9...0.1.10
[0.1.9]: https://github.com/markup-carve/carve-php/compare/0.1.8...0.1.9
