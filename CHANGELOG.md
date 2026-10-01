# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 0.1.8 and earlier are in [CHANGELOG-0.1.md](CHANGELOG-0.1.md).

## [Unreleased]

## [0.1.11] - 2026-10-01

### Breaking

- Every destination the sink denylist blanks owes a render-loss row under a new `code` value, `destination-denied`, so the `CARVE-P2-024` enum names three codes rather than two. The row carries the target, `nodeType: inline` and the spec's own message, which says whether a link destination or an image source was blanked. The emitted `href=""` and `src=""` do not move (#2802, #2806, #2804, markup-carve/carve#2681, markup-carve/carve#2686).

### Fixes

- A backslash inside a quoted attribute value or a quoted title is written only where the reader needs one, so `t\zu` comes back as `t\zu` rather than `t\\zu`, and one rule now serves every renderer, extension and importer (#2774).
- Canonical Carve output preserves parentheses and backslashes under every URL scheme, and leading non-whitespace C0 controls in link and image destinations. Presentation targets keep their destination filtering and loss reports (#2808, #2809, markup-carve/carve#2685).
- An empty footnote or definition body written `{empty}` no longer reports an unattached attribute, a fence's indentation check ignores container padding, and a caret stays literal when its braced closer lies past the bracket run (#2791, markup-carve/carve#2663).
- Blanked-destination loss rows are ordered by document position, so a report bounded to one row keeps the link's rather than one from inside its label (#2803).
- A list table's unconsumed label renders before the HTML table, captioned or not; nested code-group and tabs interiors stay at column zero while their wrapper tags follow the surrounding indentation; and an imported superscript or subscript bracket survives a round trip (#2778, #2771, #2772, #2773).
- `list-item-block-overindented` is reported once at each block's opener instead of once per line, and a continuing table or quote shares one finding with the block it continues (#2779).
- The Markdown importer keeps an autolink whose destination holds unbalanced brackets, reads escaped and multiline reference labels and titles, and leaves a malformed definition as paragraph text (#2770, #2775, markup-carve/carve#2593).
- The Markdown importer preserves nested quote depth across a lazy line with fewer markers, and a padded or tabbed quote marker a list item holds stays inside that item (#2784).
- The Markdown importer keeps a lazy line in an open quoted paragraph below a setext-shaped line, keeps every blank payload line of a fence opened on a marker line, and reads a thematic break on an item line as that item's own block (#2780).
- The Markdown importer preserves the columns after an empty list marker, so immediate code, fences, headings and nested lists stay in the item while outdented text starts its own paragraph (#2786).
- Emphasis inside a link label survives when the surrounding text uses the same kind, on Markdown and on HTML import alike (#2781, #2785).
- The Djot importer pairs emphasis by Djot delimiter ownership and converts orphan attributes, empty definition fences, image alt text and reference links in their source context, while code, destinations and fenced metadata stay opaque (#2792).
- The Djot importer keeps a code fence opaque when prose precedes an indented fence, reads numeric, single-letter and Roman list markers, and tracks each enclosing quote separately so a quote inside an item keeps that item's ownership (#2794).

### Improvements

- Custom renderers can declare their report target and read converter configuration through renderer capability interfaces, without inheriting a built-in renderer (#2788).
- Built-in renderer target names are available as `RenderTarget` constants. The values `html`, `markdown`, `plain`, `ansi` and `carve` are unchanged, and a custom renderer may still return its own (#2789).
- Malformed inline input is indexed once instead of rescanned per failed bracket opener, heading indexing skips paragraph inline parsing, and clean nested HTML is padded in bulk (#2776).
- Parsing repeats less work: built-in block continuation skips a callback hop, source mapping is skipped where nothing reads it, a fence probe runs only for the delimiter family the line starts with, and list-marker results are reused (#2795, #2796, #2797, #2799).
- MathML-to-TeX selection is shared between the two HTML import paths (#2807).

## [0.1.10] - 2026-09-29

### Breaking

- `code_block.content` is literal payload text: it keeps the break after its last line, so `""`, `"\n"`, `"a"` and `"a\n"` are four different payloads and HTML adds no newline of its own (#2755).
- Class slots keep the entries the author wrote, empty ones included. `getClassList()`, AST `classes` and ProseMirror `attrs.class` expose that list; `getAttribute('class')` still returns a joined string, and `setClassList()` sets separate entries (#2585, #2582, #2603).
- HTML import uses PHP's native HTML5 parser on PHP 8.4 and later and the libxml one below it, so malformed HTML and diagnostics can differ by PHP version. No new Composer dependency (#2546, #2577).
- `StoredPayloadUpgrade` is gone: a payload stored before the current AST contract has to be upgraded with carve-php 0.1.9 or earlier first (#2259).
- `BlockParser` loses its three protected definition collectors, and `ReferenceDefinitionExtractor` loses `extract()` and `getLayoutEvents()` (#2248, #2249).
- AST ingest refuses a container-internal type inside `children`, a `footnote_ref` with no target and an `admonition` with an empty `kind`, and accepts a `citation` with no `pos` (#2271, #2480, markup-carve/carve#2197).
- The AST schema validator honors `not`, so a payload that keyword rejects is refused where it used to pass (#2409).
- Every empty block container keeps one blank HTML body line, the shape admonitions and block quotes already had (#2256).
- An empty emphasis-family mark throws `SourceUnspellableException`, and a mark whose content is edged with whitespace is written braced (#2207).
- A parenthesized link or image title is prose; only the two quoted spellings are titles (#2195).
- A generated-content `:::` kind parses as a directive rather than an admonition (#2329).
- A task checkbox is read only where the tasklist extension reaches, so `[-]`, `[_]`, `[>]` and `[?]` import as bracket text (#2376, #2415).
- A `"` inside a quoted fence or directive title is dropped and reported; the slot has no escape (#2398).
- The raw-keep report is read off the output rather than a tag roster, which changes which rows an HTML import reports (#2361).
- The ProseMirror wire contract follows the pinned carve-grammars schema, and carries directive, block extension, ruby and small-caps nodes. Older attribute-based inline payloads still read (#2407, #2402).
- The render-loss `code` enum closes at `raw-format-dropped` and `ruby-flattened`. A table section's dropped attributes are reported as `field-unspellable`, `--report-conversion-diagnostics` is no longer gated on `--carve`, and `--allow-loss` accepts two names where it accepted three (#2479).
- Escaped spaces and preserved line-block columns travel as `non_breaking_space` nodes and U+E000 is literal content, so a tree stored under the old marker has to be reparsed from its source (#2468).
- A lone bracket inside a span, link text or inline note is escaped unconditionally, as is a `(` after a paired bare `]`; `f(x)`, `(see above)` and `[a] (b)` stay bare (#2487, #2756, markup-carve/carve#2358, markup-carve/carve#2359).
- A link's or span's edge whitespace stands outside it on HTML import, as one space (#2485, #2534, markup-carve/carve#2365).

### Fixes

- A fence keeps its interior blank lines out of its host's business: it no longer loosens a list item, raises a definition list, absorbs an unmarked line below a quoted item, or drops trailing blanks in a nested item (#2598, #2602, #2610, #2614, #2615, #2702, markup-carve/carve#2550).
- An empty fenced payload renders and writes back as no characters, an all-blank one keeps one line per blank, and the reader tells the two apart (#2725, #2730, #2734).
- A fence closer counts at its opener's column: a closer in the band between the container column and the fence base is payload, a depth-one closer is bounded at the blank, and a below-base run in a nested item folds or hands its closer down (#2609, #2660, #2666, #2690).
- A description body's fence opens on a closer below a lazy line, ends at two blank lines, and a line below the body's column folds into its open paragraph (#2217, #2236, #2686).
- A raw block takes the line its payload shape asks for and sits at its own column in a note body (#2717, #2705).
- A comment span's payload, ownership and closer are read from its opener's column, in every host and across a dedented closer (#2671, #2675, #2655, #2701, #2753).
- A fenced comment keeps payload indentation beyond the host column, and formatting preserves those columns (#2732, markup-carve/carve#2535).
- Blank and whitespace-only lines survive a block comment body, and an open block comment keeps its body past a blank in a loose item (#2511, #2514, #2528, #2508, #2519).
- A line comment ends at an explicit closer and at the combined bold-italic token's closer, and drops one leading space or tab and any trailing space or tab from its content (#2208, #2229, #2442, markup-carve/carve-rs#1951).
- A container label publishes its own inline run, closes on a balanced bracket, and its trailing comment is cut where that run ends rather than at the first marker a closed construct scopes, in the `%%` and the `{%% %%}` spelling alike (#2744, #2712, #2756, #2759).
- List tightness and band ownership are read at every column an item reaches, and a band follower joins the item only while a paragraph is still open (#2715, #2733, #2663).
- A retained list marker below the content column stays text, and a blank after a retained marker paragraph is a boundary (#2750, #2751).
- A continuation marker keeps its own residual column, stays in a nested list, and survives a comment or a code span (#2465, #2467, #2752, #2472).
- A blank inside a sibling sub-list no longer loosens the outer item, and the looseness scan's indentation gate is bounded (#2264, #2267).
- A thematic break leaves no invisible line behind it (#2737).
- A footnote break stays out of a line block's ranges, and a tilde fence behind a task checkbox stays text (#2335, #2367).
- A quote decides a continuation line by its column, ends its paragraph at a comment past its content column, and ends its lazy claim when a fenced block or container fence closes (#2351, #2353, #2652, #2659, #2698).
- An interrupting marker keeps interrupting a quoted paragraph, a setext heading folds where a quote or item holds it, and a below-base colon run stays inside its authored container (#2358, #2321, #2344, #2363, #2618).
- A closed pipe row stays text under an open paragraph and with none above it, a GFM delimiter row needs a pipe, and a row of break-only cells is dropped before rendering (#2364, #2386, #2393, #2354, #2435).
- A spanning cell publishes its resolved extent, a rowspan crossing a row group renders in one body group, and a list in a table cell flattens to its content without a marker reaching the cell as text (#2309, #2292, #2434).
- A colon fence folds into a nested definition term, and an indented one does too; a blank fence line no longer raises the list (#2513, #2543, #2517).
- A tab is counted in codepoints, and a partly consumed container tab's verse line stands on its own line (#2501, #2584, #2588).
- A whitespace-only verbatim line keeps its residue past the host column, in a footnote body and at its fence opener's column (#2581, #2594).
- An endnote backlink folds only into a real paragraph end, and past a block that reaches no output (#2696, #2720).
- A `::: footnotes` marker inside a container renders where it stands instead of moving the endnotes section, and generated `::: toc` content stays at column zero (#2395, #2418).
- A caption slot settles through the finished document, numbers only a bare `#`, and an unresolved reference's given-back lines carry their positions (#2237, #2229, #2252).
- A directive's title is published and rendered where the region it places can hold it, and an explicitly empty title travels as `title: []` (#2352, #2463).
- An empty AST `directive` publishes `children: []` from Carve source and from HTML import (#2464).
- A bracket run is resolved before an emphasis marker scans past it, and both literals a declining run leaves behind are placed (#2723, #2722).
- Combined span delimiters stay literal, a braced span's closer scan steps over an escaped backtick, and a substitution's closer comes from the scan that finds its arrow (#2198, #2223, #2215).
- A flushed run of text flanks a quote as its own last character, and child attributes survive inside an emphasis span (#2200, #2665).
- A bold-italic strong is written nested where its content cannot hug `/*`, and the writer measures the text beside a mention or inline extension opener at the true end of the written run (#2204, #2439).
- The writer writes a fence opener's quoted title once, a figure and its target in one attribute line, adjacent text nodes as one run, and merges an attribute-less definition list into the one before it (#2422, #2423, #2495, #2523, #2493, #2567).
- The writer trims redundant formatting padding around links, moves nested formatting whitespace outside links and spans, and escapes a literal image or link spelling that read back live (#2548, #2527, #2640).
- The writer claims the class slot when an authored class value is empty, writes a soft break in a table cell as one space, and places soft breaks around folded definition-term comments (#2590, #2593, #2606).
- The Markdown target keeps every block a list item holds, a list's tightness, a fenced payload's blank lines, frontmatter, and writes a hard break in a table cell as `<br>` (#2446, #2406, #2416, #2738, #2649, #2484, markup-carve/carve#2363).
- Markdown output reads correctly in a GFM reader, guards a bare email address, keeps an unpaired emphasis run literal, and escapes the bracket that hides a closer (#2500, #2571, #2742).
- The Markdown importer follows the shared converter corpus, so fences, list markers, nested quotes, renumbering, continuation lines, item tables, tab columns, escaping and ordered-marker interrupts match what `carve fmt` writes and cmark-gfm reads (#2266, #2269, #2273, #2275, #2515, #2541).
- The Markdown importer writes every definition, quote and verbatim block where `fmt` writes it, uses a comment for an emptied definition item, and normalizes incidental paragraph indentation (#2280, #2310, #2311, #2314, #2420, #2428).
- The Markdown importer reads indented code in a block quote as a fence, keeps quoted code inside list items, keeps a quoted lazy line in the item whose paragraph it continues, and keeps HTML indentation within containers (#2282, #2283, #2284, #2296, #2684).
- The Markdown importer keeps a code block whose language hint is unsupported, validates complete language hints, and decodes the ones it accepts (#2677, #2706, markup-carve/carve#2522).
- The Markdown importer encodes link and image destinations as URLs, keeps parentheses and autolink destinations, reads an angle-wrapped destination and an invalid one as what they are, and validates link titles (#2676, #2678, #2662, #2653, #2700, #2694).
- The Markdown importer keeps character references, escaped backticks, terminal backslashes, escaped email candidates and multiline emphasis, and normalizes code-span line endings (#2670, #2672, #2683, #2692, #2695, #2668).
- The Markdown importer recognizes CommonMark inline HTML tag boundaries, retains an empty heading as a raw HTML block, resolves references by canonical label, and imports an image description as plain text (#2688, #2693, #2689, #2687).
- The Djot importer folds heading continuation lines, preserves escaped emphasis delimiters, keeps attributes attached to words, and keeps a malformed hashtag attribute as text (#2673, #2674, #2704, #2691).
- The HTML importer keeps a heading, list, code block, table or block quote nested under unsupported elements instead of flattening it to a paragraph; importing a README used to lose every one (#2474).
- The HTML importer keeps every line of a block in the raw block, keeps a raw region that reaches a table cell or caption, and unwraps one a table row cannot hold (#2297, #2370, #2387).
- The HTML importer keeps an unmapped inline's words, carries a code block, ruby or details into an inline-only slot, and keeps an inline element holding blocks inline (#2325, #2383, #2560).
- The HTML importer preserves authored `role` attributes, attributes on empty paragraphs and thematic breaks, an id-bearing div's fence, unspellable classes on generic divs, and a percent sign in an unquoted value (#2521, #2531, #2526, #2535, #2532, #2533, #2502).
- The HTML importer drops an empty class token, a list with no item and an empty heading, and reports each rather than leaving an orphan attribute line (#2488, #2486, #2591, markup-carve/carve#2367).
- The HTML importer normalizes carriage returns, drops the line feed after a `<pre>` start tag, and preserves fragment content, foreign namespaces and colon names (#2505, #2524, #2579, #2580).
- The HTML importer maps table-cell alignment consistently across modes, matches CSS alignment evidence to the element it came from, keeps `valign` in safe mode when the declaration beside it does not map, and inserts the row group HTML5 implies (#2549, #2620, #2637, #2630).
- The HTML importer admits a digit-leading class as a fence kind, derives section ids by the renderer's dedup, and preserves quotes in a details summary's link title (#2551, #2547, #2569).
- The HTML importer honors round-trip markers only in round-trip mode, gives each build its own session, and separates attribute construction from import decisions (#2553, #2552, #2608).
- HTML import parity reaches comments beside dropped elements, empty links, math attributes, definition lists, caption separators, a bare `pre`, a nested table and a processing instruction (#2536, #2544, #2574).
- HTML import reports a missing `ext-dom` capability explicitly, and Composer lists the extension as optional (#2550).
- A raw HTML import is attributed by the identity of the element emitted, so twins, a raw `<head>` container and its descendants are reported against the element kept, and trusted stored source draws no guessed loss row (#2426, #2429, #2430).
- A raw-kept element reports its refused attributes, a MathML element kept as raw is reported preserved, and a refused declaration inside a preserved `style` is reported as a refusal (#2338, #2391, #2421, #2613, #2599).
- A style-unmapped row names the CSS declaration it is about, and the `element-unwrapped` row reads as what becomes of an unsupported element in block context (#2576, #2475).
- An import that reaches its diagnostic cap returns the converted document with a `diagnostics-truncated` row instead of refusing (#2424).
- A space-encoded destination is not reported as a dropped href, `LinkPolicy` reads the host a browser reads, and a denied-scheme destination imports as its content (#2327, #2328, #2334).
- A `<math>` carrying no TeX imports as its text where its tokens are linear, and a formula beside its hidden-MathML fallback image imports once (#2485, markup-carve/carve#2365).
- Blocks flattened into a pipe-table cell are reported at their input paths, a multi-line HTML comment in a cell is dropped, and one among cell blocks is spelled inline (#2425, #2499, #2510).
- A figure/target id collision is reported with the shared message, and the shared attribute line is named when a figure's id is dropped (#2503, markup-carve/carve#2386).
- An empty ordered task checkbox is reported when whitespace follows its marker, described the same way at both entry points, and no loss row is claimed where marker padding turns the content into indented code (#2411, #2437).
- The BBCode importer spells the four formatting tags the way the Carve writer would, escapes what a post's own text forms beside a converted tag, drops a stray close tag, and keeps a character reference as its text (#2213, #2225).
- The autolink extension decodes backslash escapes in a bare URL (#2258).
- A definition's destination is read as `link_destination`, so one `fmt` pass no longer loses the definition, and a title may hold a closing parenthesis in both quote forms (#2192, #2193).
- An empty term marker with trailing whitespace reads exactly as the bare marker, and a comment or definition past a term's column folds into the term (#2219, #2586).
- A node pulled in by a sliced include keeps its own file's coordinates, CRLF and multibyte sources included (#2187).
- A profile is no longer asked about the internal `Caption` node (#2316).
- HTML rendering writes an ingested citation group's escaped `raw` when the citations extension is off, on every target and through CLI JSON input (#2291, #2293).
- The ProseMirror bridge round-trips definition nodes and carries abbreviation and citation definitions with their authored positions (#2346).
- A hyphen-only line block keeps its text unpadded when formatted (#2639).
- ANSI output preserves every code payload line, including trailing blanks (#2743, markup-carve/carve-js#2357).
- Unquoted attribute values match the grammar: they reject pipes and backslashes and accept an opening brace, and the writer quotes a backslash so its output parses back (#2572, #2578).
- Class hardening removes refused entries independently, so `{class="javascript:alert(1)" .b}` keeps `b`, and a structural container dedupes its classes from the same pool the renderer uses (#2585, #2617).
- AST child mutations keep parent links consistent, move a child out of its previous parent, and reject cycles and duplicate bulk children before changing the tree (#2550).
- Six fuzz shapes now answer the way the oracle does (#2648).
- Nested code-span formatting is fixed, as are three lint diagnostics, a comment-span tightness and a table's wire partition (#2612, #2646).
- The canonical writer no longer spells `{loose}` on a one-item list whose own blank line already says it: the looseness re-parse read the writer's own sentinels as source (#2763).
- `carve lint` reports an indented raw `=FORMAT` fence as `fence-delimiter-indentation`; `fence-opener-fallback` stays reserved for an invalid info string (#2767).

### Improvements

- HTML indentation uses native tag searches instead of scanning every padding byte in PHP, reducing nested list and quote rendering time. Multiline attributes and preformatted payloads keep their existing bytes.

- Editor-facing AST APIs for node identity, annotation ranges and provenance sidecars, plus reversible AST patches carrying revision fingerprints (#2456).
- Editor sessions with bounded HTML streaming, and plain paragraphs reused across updates (#2624, #2626).
- `MarkupCarve\Carve\Ast\AstEnvelope` reads and writes the versioned AST interchange envelope, so a payload from a newer contract, one needing a missing extension, and a foreign vocabulary are each refused distinctly (#2453).
- The Carve writer exposes a bounded conversion-diagnostics report for what it cannot spell, with the CLI flag `--report-conversion-diagnostics` (#2331).
- Ruby annotations survive AST JSON interchange as ordered base and annotation pairs, with `base(annotation)` reported as `ruby-flattened` and accepted by `--allow-loss` (#2290).
- Line-block ranges, explicit sections and block table cells decode, render and write (#2319).
- A citation item's own mode is read rather than only the group's, and AST ingest names its standalone `citation` limitation in the error message (#2322, #2287).
- A block extension is read and the fallback it declares is rendered, and an opaque JSON object inside its payload stays an object through both bridges (#2323, #2408).
- Figure nodes expose `getTargets()`, `getCaption()` and `getCaptions()`, and every renderer, the numbering pass and the linter read a figure through that one decomposition (#2260).
- Table heads and feet keep their attributes through AST exchange and HTML import; the source and text targets report an attribute they cannot spell (#2473).
- `carve lint` gains reference, footnote and container diagnostics, a nested `::: references` report under `--extension citations`, complete default triggers, and an attribute scope that matches the writer's (#2417, #2600, #2607, #2682).
- List tables import per the contract, `--list-table` is available, and a list table writes as a pipe table on the Markdown target (#2525).
- HTML import recognizes explicit code-language hints on code blocks and on Sphinx, GitHub and MediaWiki wrappers (#2504, markup-carve/carve#2387).
- The HTML importer carries its tree and decisions in one typed result, with shared shapes typed and structural scans separated (#2557, #2621, #2623).
- Document line normalization and byte mapping are extracted, and HTML import loads its DOM through one loader that restores the caller's libxml error mode (#2556, #2332).
- The BBCode passes copy text up to the next bracket instead of trying two anchored patterns per byte, and skip the repair parse where the converted output carries no unescaped ASCII punctuation (#2220, #2240).
- Resolving a position reads the document's index rather than rescanning its line's prefix, which the single-line documents the BBCode repair parse produces made quadratic (#2247).
- A document is reparsed only for a heading the failed label could name (#2246).
- An HTML migration performs roughly a third fewer re-parses: a list item's attribute payload is validated once, and the escape search parses each candidate once (#2477, #2478, #2487, #2494, #2542).
- Fence-ownership tracking that nothing read is gone, and a spanning cell's extent is settled without it (#2619).
- Migration reports verify literal text (#2625).
- The spec corpus pin reaches carve `aa3678a2` (#2254, #2409, #2749, #2757).
- Nested note blocks stay inside collected list chunks (#2628).
- A cross-reference label asks the budget before rendering (#2658).
- Importing deeply nested HTML scans less indentation per level (#2766).

## [0.1.9] - 2026-09-19

### Added

- `ProseMirrorToCarve::degradedAttributes()`, a second report channel for what a conversion carried in a lesser form (#2175).

### Changed

- **A mention loss that keeps the text is reported as degraded, not dropped** (#2175). A display label that differs from the `id`, a label that is not text, and a name the mention grammar rejects move from `droppedAttributes()` to `degradedAttributes()`, keyed and worded as carve-rs and carve-grammars write them. An attribute on the text path stays dropped, and its reason names the node kind, so a tag is no longer described as a mention.

### Fixed

- **The ProseMirror bridge drops a mention or tag that carries no name and reports it** (#2176), so a node with neither an `id` nor a `label` is left out and named in `droppedAttributes()` under its node kind, no field having held a name. It used to reach the writer, which refused the whole document. `CarveRenderer` still throws for a tree an API caller builds that way.
- **A caption's `#` placeholder is literal inside inline markup** (#2181, markup-carve/carve#2112). `^ a *# x* b` keeps its `#`, a later top-level `#` still numbers, and the Carve writer stops escaping the bare one, which is what the other engines write.

[0.1.11]: https://github.com/markup-carve/carve-php/compare/0.1.10...0.1.11
[0.1.10]: https://github.com/markup-carve/carve-php/compare/0.1.9...0.1.10
[0.1.9]: https://github.com/markup-carve/carve-php/compare/0.1.8...0.1.9
