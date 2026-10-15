# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 0.1.8 and earlier are in [CHANGELOG-0.1.md](CHANGELOG-0.1.md).

## [Unreleased]

### Fixes
- A cross-reference `</#id>` is opaque to emphasis like an autolink: an earlier bare `/` no longer pairs with the slash inside it (markup-carve/carve-js#3100).
- A braced closer inside a cross-reference (`{*a </#x*}> b*}`, `{+a </#x+}> b+}`) no longer closes the span opened before it (markup-carve/carve-js#3105).
- `WikilinksExtension` no longer closes `[[` at a `]]` whose first bracket is escaped (`\]`), so `a [[x\]](u)` renders the core link instead of a wikilink (markup-carve/carve-js#3092).
- `WikilinksExtension` matches carve-js on its edge cases: a whitespace-only `[[   ]]` stays literal, the display text may be empty (`[[a|]]`) or hold a single `]` (`[[a|b]c]]`), and a page named `0` is its own link text.
- An escaped include directive (`\{{ path }}`) stays literal: `IncludeExpander` no longer expands it, and the Carve writer no longer turns `{{ \{{ path }}` into a live directive (markup-carve/carve-js#3101).
- A paragraph holding many cross-references parses in linear time; each `</#id>` used to re-read the rest of the paragraph.
- Inside an unclosed code fence in a `:::` container, only the innermost container's own closer ends the fence. A colon line of any other width is code; it used to open a nested container, so the host never closed and the rest of the document became code (markup-carve/carve#3201).

### Performance
- `IncludeExpander` and the Carve writer look up the run node behind each include directive candidate from an index built once per inline run, so a run with many `{{` candidates (escaped or live) is scanned in linear time instead of quadratic (markup-carve/carve-js#3104).

## [0.1.12] - 2026-10-14

### Breaking
- Markdown import reads `*[TERM]: expansion` as an abbreviation definition by default, and an `<abbr>` whose title matches the defined expansion imports as the plain term. Pass `convertAbbreviations: false` to keep the old literal reading (#3327).
- HTML import reads a line block only from its `data-carve-line-block` carrier, so a bare `<div class="line-block">` now imports as the `::: line-block` admonition. Roundtrip export carries a bare div, a line block and a substitution (#3324, #3334).
- `HtmlToCarve` loses the `trustedRoundTrip` parameter and the stored-source import path. No extension writes `data-carve-src`, and an incoming `data-carve-src` or `data-djot-src` is consumed in every mode and never re-emitted. Code groups, callouts, mermaid and tabs import from their own HTML (#3145, #3164, #3181, #3202, #3208).
- One roundtrip export option covers HTML and Markdown: `MarkdownRenderer::setRoundTripMode()`, `CarveConverter(roundTripMode: true)` and `carve --roundtrip` for `--html` or `--markdown` (#3145).
- Roundtrip carriers are spelled `data-carve-*` instead of `data-djot-*`; import still reads the old spelling. Every import mode consumes them, a carrier leads the authored attributes and wins a name collision, and the displaced attribute is reported (#3081, #3082, #3083, #3086, #3215, #3218).
- AST JSON publishes `delim` only for `)` and `bulletChar` only for `*`. An ordered list with `)` renders `data-delim=")"`, and ordered lists no longer write `data-marker` (#2986, #3008, #3086).
- Image alt text resolves ASCII punctuation escapes, including `\|` in tables, so a literal backslash before punctuation must be doubled. Writers keep the decoded value, and the image-alt pipe degradation warning is gone (#2951, markup-carve/carve#2782).
- A quoted include path decodes a backslash before ASCII punctuation to that character and keeps any other backslash as path text. It used to run through `stripcslashes()`, so `"notes\new.crv"` looked for a name holding a newline (#2948, #2949, #2954, markup-carve/carve#2778).
- The include rename warning's rule id is `include-id-rename` (was `include-heading-id-rename`), and its constant is `IncludeExpander::RULE_ID_RENAME` (markup-carve/carve#2772).

### Fixes
- The Carve writer refuses a thematic break in a table cell at any depth instead of writing an empty cell (#3350).
- A fence closer below its authored base is measured after the line's available prefix: a matching run closes the fence and a shorter run stays payload (#3343).
- Plain text blanks a denied destination in an unused link definition and reports one `destination-denied` loss (#3348).
- A bare `---` block becomes front matter only when it has the shape of a mapping; a typed opener always opens front matter. The importer writes the canonical opener and closer (#2973, #2974, #2976, #2983, #2987, #3005).
- Smart quotes decide direction from more than the preceding character, and a quote after a line break opens (#2991, #3056, #3068).
- A raw HTML block escaped by safe mode renders as a code block in its format. A raw fence ending at EOF keeps its termination in `raw_block.content` (#2975, #3109).
- A row with opening and closing pipes is a table row even when all its cells are blank, and `|< |` is an empty left-aligned cell (#3325, #3333).
- Table spans follow the T5 walk: a span never covers another cell, and continuation text joins the cell the walk resolves within the source row width (#3290, #3299, #3309, #3321, #3328, #3339).
- Continuation text on a blocked `^` or `<` is that cell's content and the marker keeps its span, so `fmt` returns such tables unchanged. The Carve writer puts each part back in its authored column and no longer repeats a span cell's attributes before its moved text (#3359, #3371, #3413).
- An indented `+ |` line after a table stays paragraph text; a continuation starts in the table's first column (#3382).
- A head-row alignment marker overrides the delimiter colon for its column, and inherited head-row alignment survives ProseMirror and AST round trips (#3252, #3385).
- A below-column line continues a paragraph only when one is open at the deepest frame; otherwise it closes the list (#3073, #3099, #3211, #3213, #3216, #3230, #3234).
- `> +` and `[^a]: +` open a first-block form when a flush-left block follows (#3318, #3324).
- An empty quote takes no caption (#3092, #3101).
- An unattached caret line stays in an open list paragraph and ends a definition term. Indented lazy lines stay in an attached quote, and a flush-left line fills an indented empty list item (#3368).
- Notes keep their nested lists and fences, and nested list, quote, note, comment, fence and table content keeps its owner in small inputs (#3252, #3387).
- Formatted Carve stays parseable without changing the document or leaking internal sentinels (#3387).
- Figure groups skip invisible children in their layout (#3368).
- Source spans place nested fenced code at its opening fence, a line block hard break on its physical line inside a list, a table row on its own line when a later continuation joins it, and a footnote body after the definition marker (#3381, #3393, #3409).
- Djot import keeps delimiter scopes, escaped spans, braced openers and forced closers as Djot reads them, without regex stack limits (#2979, #2981, #2988, #2999, #3020, #3021, #3030, #3052).
- Djot import preserves attribute values, invalid and empty attribute groups, attribute ownership across blank and list boundaries, and footnote definition attributes (#3001, #3006, #3013, #3040, #3091, #3313).
- Djot import preserves block ownership, table boundaries and captions, empty footnotes, autolink brackets, image quote text and opaque labels, and writes Carve that reads back the same way (#2967, #3034, #3042, #3045, #3055, #3062, #3089, #3274, #3342).
- Djot import derives image reference keys from child text as Djot does, keeps escaped punctuation in nested image labels, and keeps source NUL characters distinct in link, image and heading reference keys (#3355, #3383, #3386, #3389, #3403, #3405, #3415).
- Djot import reads compact and tab-separated DIV openers and tab-separated task markers, escapes checkbox markers Djot does not read as tasks, keeps empty task checkboxes and tight lists, and puts a leading DIV opener in a definition body (#3388, #3399, #3416, #3420, #3421).
- Djot import reports raw formats containing U+0085, multiline code spans holding a container opener, and blank lines left in an image alternative, which Carve cannot spell (#3355, #3402, #3410).
- Djot import keeps opaque content opaque: autolink labels, fence code and owners, literal reference definitions, and URL IPv6 brackets, raw backslashes and email priority (#2962).
- An escaped pipe keeps its table cell boundary across Djot import and Markdown export, and two entity-bearing footnote ids no longer merge (#2959).
- Markdown import separates front matter from its body and writes block separators, colliding breaks and percent runs as the writer does, so `carve fmt --check` accepts the output (#2977, #2982, #2993, #2997, #3054, #3061).
- Markdown import drops blank table rows Carve cannot spell without losing their list and quote containers, and reads back underline and other inline HTML (#3015, #3016).
- Markdown import preserves HTML code payloads, links across code boundaries, quoted list fences, GFM bare autolinks, destination escapes, reference definition containers and URL labels, and imports spellable HTML code as code (#3047, #3065, #3088, #3097, #3156, #3184, #3196, #3333).
- Markdown import follows the native destination rules, keeps entity reference labels distinct, and keeps a footnote after bracketed text (#3399).
- Markdown table cells hold their boundary across an escaped trailing pipe, a literal backtick or backslash, a quoted table, a pipe-bearing reference label, an HTTP(S) or email autolink and an image title; an unresolved reference stays literal (#2943, #2945, #2953).
- A GFM table whose header starts on a quoted list item marker stays a table, indented at the item's content column through nested lists, quotes and ordered markers of changing width (#2947).
- An imported table footnote keeps its identity under a collision-free label, and a blank line resets the table context (#2956).
- Markdown export preserves link and image titles, label brackets, hard breaks, code-span whitespace, nested emphasis, literal image alt text, multi-block footnotes, tight lists around styled sublists, callback output and GitHub heading anchors. Backtick headers get a tilde fence (#2968, #2969, #3017, #3029, #3039, #3043, #3104, #3105, #3155, #3163, #3316).
- Markdown export writes attributes on inline HTML fallbacks and keeps a CriticMarkup deletion apart from a classed strike (#3014, #3027, #3148).
- Markdown export keeps tight items tight before a thematic break after a nested list and after a closed code fence, and spells a break `***` where `---` would underline a paragraph (#3393, #3396, #3401).
- Markdown, plain text and ANSI output write link definitions at their source positions, including around includes, and omit definitions whose only use is a nested reference link. ANSI blanks a denied destination, and Markdown writes `<>` for an empty or blocked destination (#3396, #3401, #3411).
- ANSI table headers match carve-js when a row mixes header cells and span markers. ANSI output dims unused link definitions, and a definition used only inside link text counts as unused (#3289, #3411).
- An editorial comment keeps its boundary on the Markdown target as a `critic-comment` span with escaped content. Plain text and ANSI output is unchanged, and each comment reports one render loss (#2964, markup-carve/carve#2791).
- HTML import keeps nested formatting across span scopes, code-block and code-span text, footnote carrier labels, and image reference fields, and reports the markup it discards (#2992, #3011, #3024, #3103, #3114, #3118, #3150, #3158).
- Formatting reaches a fixed point for escaped braced closers, quoted attributes, cross-reference targets, reference brackets, adjacent verbatim spans, multiline verbatim payloads, trailing escaped spaces and `!` before a highlight (#2988, #2995, #3009, #3018, #3048, #3094, #3095, #3180).
- List items own their paragraphs, fences, comments and nested definitions correctly after empty leads, separating blanks, dedents and quoted lazy markers (#3036, #3059, #3076, #3079, #3087, #3154, #3165, #3173, #3176, #3185, #3190, #3198, #3205, #3239, #3248, #3340).
- Quoted items, colon divs and line blocks release continuation at the right line after closed containers, comments, code spans and dedented fences (#3116, #3124, #3127, #3130, #3132, #3138, #3139, #3141, #3143, #3144, #3149, #3179).
- Fences, comments, attributes and content-column text land in the definition term or description that owns them (#3187, #3193, #3199, #3214, #3226, #3236, #3276, #3287, #3289).
- Footnote definitions keep their body: a nested definition stays at its parent's column, and a marker-line note keeps the lines at its body column (#3026, #3206, #3254, #3262, #3281, #3292, #3295, #3299).
- Rendering, formatting and Markdown export no longer fail or corrupt text when input uses the private-use marker characters (#3106, #3107, #3108, #3146, #3153).
- A static math block keeps its display delimiters, images stay inline in inline hosts, and a no-break space in an attribute value stays itself (#3003, #3007, #3077).
- Table cell hard breaks and discarded short-caption fields are reported as render losses (#3032, #3035).
- Unclosed CODE and RAW fences include their final EOF line in source spans. An unclosed `<code>` in a heading gains no section separator, and a very deep tree reaches the depth error instead of crashing (#3072, #3152, #3160).
- Adjacent alphabetic and Roman ordered lists stay separate, a lone letter follows the shared tie-break, and a raw block converted to code by a profile keeps its source position (#2993).
- `getClassList()` splits each authored class entry on HTML whitespace again, so `{.a class="b c"}` lists `a`, `b` and `c` and `hasClass('b')` matches as in 0.1.9. The merged `class` attribute does not move (#2939, #2940).
- Cloning Ruby and block extension nodes keeps the original children attached to their original parent (#2935).
- Include directives: an option needs no whitespace before its `@`, so `{{ path@shift:1 }}` resolves; a tab before `#section` separates the slot; and naming two sections warns under `include-selection-conflict` and stays literal instead of silently using the second (#2934, #2937, markup-carve/carve#2773).

### Improvements
- An authored id on a footnote reference replaces the generated `fnref` id, and the note's backlink targets it. HTML import pairs a backlink with its reference by target (#3357).
- New render and migration loss codes: `carrier-attribute-dropped`, `carrier-marker-dropped`, `carrier-markers-damaged`, `list-delimiter-changed`, `list-style-dropped` and `djot-footnote-definition-attributes-dropped`. `--allow-loss` accepts them and `definition-list-flattened` (#3013, #3033, #3083, #3274, #3301, #3311, #3316).
- A render loss carries the code `editorial-comment-flattened` on targets that flatten an editorial comment, and `--allow-loss` accepts it. The vendored spec schema's `code` enum does not list it yet (#2964, markup-carve/carve#2791).
- Lint reports a `^` or `<` span marker that the T5 walk leaves unresolved under `table-span-marker-blocked`. A marker under a shorter row reports that no cell is above it (#3422, #3426).
- `--smart-typography quotes-source` and `SmartTypographyMode::QuotesSource` keep source quotes while dashes, ellipses and symbols still resolve (#3067).
- Nested braced emphasis parses, formats and imports from HTML, Markdown and Djot (#3070).
- Task lists render `class="task-list"` and checked items `data-task-state="x"`, and HTML import reads them back with all four extended states (#3071).
- AST contract 1.1 publishes a table's authored `separatorWidths`, and formatting keeps the separator row. Readers still accept 1.0 (#3093).
- Table continuation rows stay in the tree as `cell_wrap` nodes with `table_row.continuationLines`, and verbatim spans carry `cellWraps` (#3335, #3337).
- `cell_wrap` nodes and `cellWraps` entries carry `col` and a source position, and a blocked cell's text from a later `+` line starts with a `cell_wrap` naming that line (#3359, #3371, #3413).
- Roundtrip Markdown export carries element-less containers, composite figure captions, containers inside lists and quotes, definition list depth, fence titles and labels, list styles and reference links (#3033, #3044, #3074, #3209, #3262, #3301, #3311, #3327).
- Roundtrip HTML export carries code block labels and attribute lines, definition lists, captions, cross-references, caption numbers and tab titles (#3162, #3181, #3262).
- Markdown migration reports construct fidelity with source `line:N` paths, and reports dropped destinations, language tokens, trailing raw-span whitespace and decoded whitespace (#2972, #2996, #3004, #3041, #3054, #3057, #3078).
- Markdown table import explains an unescaped code-span pipe, a mismatched header width and an omitted body cell with a warning naming its source line (#2943, #2945).
- `Node::getPreviousSibling()` returns the preceding child of the parent. Custom parent nodes keep their `getChildren()` behavior (#2935).
- `getClassEntries()` returns the authored class entries unsplit and `hasClassEntry()` compares whole entries, beside the split names `getClassList()` reports (#2939, #2940).

### Performance
- Code-fence interruption lookahead uses a closer index instead of rescanning for closers, which removes a quadratic cost on documents with many fences (#3356).
- The Carve writer keeps its escape search local in containers holding images, so large mixed documents no longer slow down sharply (#3380).
- Djot import scans structural prefixes, comment context and delimiters in linear passes, tracks indentation on a stack, and advances quote prefixes by offset instead of copying the line (#2962, #2994, #3112).
- Djot import handles deeply nested image labels, escaped brackets included, without rescanning each label (#3399, #3404).
- Djot delimiter conversion merges accepted ranges once per rule and writes its edits in one pass (#2959).
- Short literal span contents skip recursive inline parsing (#3122).
- Markdown import and export skip unneeded emphasis checks, delimiter caches and loose-list reparsing (#3167, #3183, #3188).
- Markdown table import indexes backtick runs once instead of rescanning after each unmatched opener (#2945).
- HTML import, AST encoding and validation join adjacent text nodes once, and inline padding trims scan only the suffix (#2931).
- Large HTML imports pace garbage collection to avoid repeated scans of live trees (#2932).
- Swatch classes, ordered attributes, callout markers, nested link rewrites and HTML import names are looked up with bounded scans, cursors and membership maps (#2935).
- Footnote lookup caches target counts, backlink lists and marker classes, and batches backlink checks, separator removal and empty-wrapper pruning (#2944).
- Fence indexes use less memory with packed range maxima and replacement metadata limited to the mutable tail (#2930).

## [0.1.11] - 2026-10-06

### Compatibility and migration

With the new engine, lint existing documents before deploying their output.
`fmt --migrate` repairs unambiguous case-only reference misses. Review changes
to collapsed link text and image alt text; include selectors, glossary
references and external fragment links need manual review. See the
[migration guide](https://github.com/markup-carve/carve-php/blob/main/docs/cli.md#exact-case-reference-migration).

### Breaking

- A glossary reference matches its term exactly, under the same comparison as every other name lookup, and a glossary id keeps its case, so two terms differing only in case take two ids (#2895, markup-carve/carve#2739).
- Every destination the sink denylist blanks owes a render-loss row under a new `code` value, `destination-denied`, so the `CARVE-P2-024` enum names three codes rather than two. The row carries the target, `nodeType: inline` and the spec's own message, which says whether a link destination or an image source was blanked. The emitted `href=""` and `src=""` do not move (#2802, #2806, #2804, markup-carve/carve#2681, markup-carve/carve#2686).
- Heading cross-references, numbered caption and equation references, and collapsed
  references that fall back to heading text now compare case exactly.
  Link-definition labels, footnote labels and include fragment selectors already
  did in the previous published engine.
  Case-only mismatches no longer resolve (#2874, #2879, #2888, markup-carve/carve#2732).

- Includes rename colliding explicit ids on any element and give each later
  occurrence its own least free suffix. References written in the same inclusion follow
  the rename (#2876, #2879, #2888, markup-carve/carve#2729,
  markup-carve/carve#2732).

### Fixes


- The duplicate-merge scaling guard passes the structural indexes required by
  the current matcher, so the release check measures its work instead of
  failing on an outdated private-method call (#2923).

- The upgrade guide distinguishes newly exact lookups from labels that already
  matched case exactly, and explains migration limits. Tests keep case-distinct
  numbered captions and equations separate (#2923).

- AST merges and identity snapshots share exact structural indexes instead of repeatedly serializing subtrees. Patch creation walks each pair once, and replay updates a private tree in place. Provenance ancestry and envelope extension checks avoid repeated scans (#2921).

- Default classes are merged in one pass, preserving authored duplicates and the order of newly added classes (#2916).

- A flush-left line below a closed nested fence folds into the outer item rather than opening a block of its own (#2901, markup-carve/carve#2878).
- A paragraph left open below a closed nested fence takes a following flush-left line (#2905, markup-carve/carve#2903).
- A description body folds a flush-left line below it for every lead kind, not only the kinds the earlier fix named (#2906, markup-carve/carve#2904).
- A collapsed reference image no longer resolves against a heading. The implicit heading fallback stays for collapsed reference links, and the correction reaches the renderer and `fmt --migrate` alike (#2902, markup-carve/carve#2900).
- `lint` compares a reference against the rendered heading ids rather than the authored text, and `fmt --migrate` rewrites a case-only reference against those same ids (#2897, #2898, markup-carve/carve#2892, markup-carve/carve#2893).
- `lint` reports a reference image with no matching definition under `unresolved-reference-link`, the rule that already covered reference links, under no new rule id (#2908, markup-carve/carve#2907).
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
- PCRE errors in source eligibility checks reject the borrowed HTML path (#2842).
- Preserve multiple table bodies, intermediate body headers, empty bodies, and per-body row-header counts with positional source attributes. Keep authored conflicts and report partition loss. Preserve decimal column widths through AST parsing, source export, and HTML rendering.
- List marker attribute validation keeps subclass results on each parser instance. Subclasses can clear `instanceMarkerAttributeCache` after changing validation settings. Attribute payloads above 2,048 bytes are parsed without retaining them in the cache (#2827).
- A container whose opener metadata is invalid is recovered rather than abandoned, and the gate over recovered containers is capped (#2817, #2819).
- A verse body keeps its ownership boundaries (#2813).
- An escaped citation marker stays literal, and a soft-wrapped citation prefix is read as one (#2826).
- A non-heading AST id survives an ingest that does not preserve attribute order (#2833).
- A table keeps its body metadata, its empty bodies and its decimal column-width precision through AST parsing, HTML import, source export and rendering (#2839, #2840, #2843, #2848).
- A flush-left fence line stays inside a description body's nested fence, and a closed nested lead fence releases the line again (#2875, #2883).
- An attributed table group keeps its attributes through the quote renderer's reduced copying (#2847).
- A failed parse leaves PHP's cycle collector enabled again, and a direct parser call is paced like one made through the converter (#2886).

### Improvements

- Avoid repeated fence and comment scans in nested fences and description bodies (#2927).
- Custom renderers can declare their report target and read converter configuration through renderer capability interfaces, without inheriting a built-in renderer (#2788).
- Built-in renderer target names are available as `RenderTarget` constants. The values `html`, `markdown`, `plain`, `ansi` and `carve` are unchanged, and a custom renderer may still return its own (#2789).
- Malformed inline input is indexed once instead of rescanned per failed bracket opener, heading indexing skips paragraph inline parsing, and clean nested HTML is padded in bulk (#2776).
- Parsing repeats less work: built-in block continuation skips a callback hop, source mapping is skipped where nothing reads it, a fence probe runs only for the delimiter family the line starts with, and list-marker results are reused (#2795, #2796, #2797, #2799).
- MathML-to-TeX selection is shared between the two HTML import paths (#2807).
- Extract HTML import element, attribute, and content-loss checks while preserving diagnostic order and traversal (#2860).
- Extract HTML image, link, and math handlers and node-specific ProseMirror attribute handlers while preserving traversal, attribute precedence, and decoding order (#2859).
- Extract the comment-fence closer scan from body rebasing while preserving closer matching at any column and unclosed-span handling (#2858).
- Extract list, quote, and table extent scans from body rebasing while preserving blank and column checks (#2857).
- Extract code-fence, colon-group, and definition-body extent scans from list-body rebasing while preserving column checks and scan order (#2854).
- Extract the authored-base eligibility probe from list-body rebasing while preserving its early exits and scan order (#2853).
- A block can be selected by id on include, and a colliding explicit id is renamed (#2876, markup-carve/carve#2729).
- `lint` reports a fragment link that matches no id, reading the ids from the caller's own render rather than a default one (#2866, #2880).
- Imported AST contracts are tightened and a parser session is reset between documents (#2812).

### Performance

- AST merges and identity snapshots share exact structural indexes instead of
  repeatedly serializing subtrees. Patch creation walks each pair once, and
  replay updates a private tree in place. Provenance ancestry and envelope
  extension checks avoid repeated scans (#2921).
- Default classes are merged in one indexed pass, preserving authored
  duplicates and the order of newly added classes (#2916).
- Speed up editor batches, attribute-token mapping, table linting and repeated empty-include warnings, and preserve table-warning line numbers after Unicode text and byte ranges after CRLF and CR endings. Editor attribute lines are recognized after bare CR endings (#2912).
- Avoid repeated scans of malformed link destinations, including nested labels and emphasis, and speed up AST merges, Carve rendering of wide trees and platform linting of long destinations (#2911).
- Index table spans, placement and AST alignment (#2899).
- Nested singleton lists accumulate HTML indentation instead of repeatedly copying each child subtree. Task wrappers, callbacks, renderer subclasses, static extensions retain their existing rendering paths. Preformatted bodies preserve their output through the shared layout writer (#2850).
- Borrowed HTML writes plain inline text directly and skips repeated paragraph checks. Native scanning handles longer runs between markers; single-byte gaps avoid scanner setup (#2842).
- Buffered HTML appends handle the first fragment directly instead of packing it into a variadic array (#2837).
- Repeated slash openers around bracket runs reuse failed suffix scans while keeping skipped interiors available. HTML import reports reuse each table's block-cell classification during inspection (#2812).
- Memory is bounded where a document can grow it without limit: footnotes, attribute slots, abbreviations, Markdown escapes, list attribute cache keys and the braced and substitution scan caches (#2864, #2827, #2884).
- Repeated scans are bounded or indexed instead of repeated: failed inline scans, table spans and section boundaries, list spans and colon position maps, citation item positions, definition-list import inspection, imported table rows and sibling positions (#2861, #2848, #2856, #2821, #2815, #2825, #2828).
- Reflection and metadata lookups are cached by class and wire type for AST encoding and decoding and for HTML import (#2814, #2820, #2832, #2830).
- The HTML writer allocates less and routes more work through the borrowed path: bulk scanning of ordinary inline text, large plain paragraphs, marker probes and streaming (#2834, #2838, #2844, #2845).
- Nested container layouts are written without reindenting the subtree, block attributes and table continuations accumulate linearly, and nested parsing and render-hook fallthrough cost less (#2849, #2887, #2851).
- Parser state is copied once where it was copied per detachment, and AST payload walks skip unchanged arrays (#2846, #2829).
- A colon that cannot open an inline extension is rejected before PCRE runs, and PHP's cycle collector is paced during parse and render (#2870, #2871).

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
