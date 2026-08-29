# Changelog

All notable changes to carve-php are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The HTML import diagnostic cap now throws consistently when caption-flatten
  diagnostics exhaust it instead of returning a silently incomplete report
  (#1832).
- A link or image destination title occupies the HTML `title` slot instead of being emitted beside a duplicate authored attribute.

### Fixed

- Djot migration preserves Carve table continuation rows instead of rewriting their `+` marker as a bullet.

### Fixed

- **An empty external-link target omits the `target` attribute** (#1823).
  External-link `rel` policy can now be applied without emitting the meaningless
  `target=""`, in both the authoritative renderer and borrowed-HTML fast path.
- **A soft-wrapped caption keeps its AST positions** (#1819). A caption that wraps across lines published every inline unplaced, and the figure, table or figure group it attached to ended at the host instead of at the end of the caption. The rendered HTML was never affected; this reaches AST consumers only.

## [0.1.6] - 2026-08-27

### Added

- **An empty description body is written with the `{empty}` sentinel** (#1813, #1811, markup-carve/carve#1833, markup-carve/carve#1827, PART 11 §7d). The canonical writer emits `: {empty}` where a description holds no blocks, the sentinel PART 11 §7b already gives an empty footnote definition body, so every entry writes its own description line and a `<dl>` reads back as one list with the grouping it parsed. The definition-list import path no longer reports `structure-unspellable` or `structure-split`.
- **`paragraph.blockImage` is published on the wire and trusted on ingest** (#1806, #1800, markup-carve/carve#1816, PART 9R R7, PART 12 §23). One `BlockImagePromotion` phase replaces the four places that each re-derived the role, and the renderers read its answer.
- **Citation items are typed, positioned nodes** (#1797, markup-carve/carve#1799). Each item is published with `type: "citation"` and its exact `pos`, preserved through AST decode and encode and through the ProseMirror bridge, with inline profiles applied per item.
- **A fenced block quote** (#1748, markup-carve/carve#1718, markup-carve/carve#1734). `::: >` opens a quote whose body is ordinary block content, the same node the prefixed form produces; the node records which spelling was authored, so `carve fmt` writes it back.
- **`carve lint` reports a quote fence that ends the quote above it** (#1761). The new `quote-fence-ends-the-quote-above` rule names the one `::: >` mistake that reads exactly like the nesting it is not.
- **Digit-leading explicit ids and classes** (#1738). `{#123 .2col}` and a digit-leading generic-div type survive parsing, HTML import and canonical output; keys, booleans and generated heading ids are unchanged.
- **`roundtrip` HTML import keeps what Carve cannot express, byte for byte** (#1724, #1713). `<iframe>`, `<svg>`, `<form>`, `<hgroup>` and the rest are preserved instead of dropped, unwrapped or degraded to a `:::` fence. `safe` and `semantic` are unchanged.
- **Checked render results** (markup-carve/carve#1728). `convertWithReport()` and `renderWithReport()` preserve the existing value while reporting bounded, positioned `raw-format-dropped` losses; strict mode throws `RenderLossException` with the same complete count.
- **A definition list's spelled looseness is a field on the wire** (#1658, #1660, markup-carve/carve#1624, PART 12 §8). `definition_list` publishes `loose` where the source spelled it, so a tree that goes out over the wire no longer has to derive each description's wrapper from its block count.
- **The HTML importer has an AST exit** (#1666). `HtmlToCarve::convertToAst()` and `convertToAstWithReport()` return the PART 12 AST, the latter as an `HtmlImportAstResult` carrying the same mode, adapter and diagnostics as the source exit.
- **A container takes PART 9 §17 L7's consumed `loose` boolean** (#1642, #1644, #1654, markup-carve/carve#1612, markup-carve/carve#1623). `{loose}` above a list or definition list renders the children as blocks and never reaches the HTML.
- **The table-of-contents nav carries an accessible name** (#1574, markup-carve/carve#1547, ruling markup-carve/carve#1509). Both TOC extensions write `aria-label` on the `<nav>` from a new `labels` key `tocNav`; an authored name wins.
- **Table column metadata** (#1451, #1480, markup-carve/carve#1391). Alignment, vertical alignment and widths reach the AST as `table.columns` and `table_cell.valign`.
- **Semantic table row partitions** (#1482). Pipe tables take `{header-rows=N footer-rows=N}`; a ListTable cell takes `{align= valign=}`. The consumed attributes do not leak into the HTML.
- **Local ListTable headers** (#1479, markup-carve/carve#1248). `header-row` on a row's first cell starts a header-led body group; `header` on any cell emits a single `<th>`.
- **Inherited horizontal table alignment** (#1477, markup-carve/carve#1408). `?^`, `?~` and `?v` set only the vertical axis; a lone `?` stays visible cell content.
- **`SourceUnspellableException`, exposed** (#1462). The canonical writer refuses an empty `raw_inline` instead of emitting one, and reports machine-readable `nodeType` and `reason`.
- **A `labels` converter option carries the strings the engine writes itself** (markup-carve/carve#1456, PART 9 §16a). Values are text and are escaped where they land, unlike the raw `symbols` map.

### Changed

- **A definition body's separator is any run of spaces, and its width sets the content column** (#1772, #1773, markup-carve/carve#1757). `:  body` opens a wider body; the canonical writer and the HTML importer narrow every separator to one space and rebase the continuation by the same amount.
- **`{align=left|right|center}` renders a `text-align` declaration** (#1758, markup-carve/carve#1755) on paragraphs, divs and headings, instead of the deprecated presentational `align` attribute. An element whose `align` means something else keeps it.
- **Reference labels match on normalized ASCII whitespace** (#1740, markup-carve/carve#1726). Links, images and footnotes resolve across harmless spacing differences, in the parser, the borrowed HTML path and the ProseMirror bridge alike; case and Unicode whitespace still do not fold, and the winning definition's authored spelling is what `carve fmt` writes.
- **A marker-attached attribute block moves no list content column** (#1706, markup-carve/carve#1701, markup-carve/carve#1703). `-{#k} a` keeps the bare marker's column, as a task checkbox already did, across the parser, the canonical writer and the HTML importer.
- **An imported cell's alignment is the native marker run** (#1742, markup-carve/carve#1741). In `semantic` and `roundtrip` a `text-align` or `vertical-align` comes back as `|>` rather than `{align=…}`, so a render-and-import cycle no longer drifts; `safe` still drops it and reports.
- **Authored block bases keep opaque fence payloads and nested footnote definitions in their structural owner** (#1756). Invisible fenced percent blocks no longer make an otherwise tight list loose.
- **Recognized block groups authored past a definition or footnote body's minimum column now parse at their authored local base and format back to the canonical column** (markup-carve/carve#1729). Plain lazy continuation and below-column behavior are unchanged.
- **Recognized block groups authored past a list item's canonical content column now use that column as a temporary local base and format back canonically** (#1720, markup-carve/carve#1705). Exact-column and below-minimum behavior stay unchanged; this is a source-compatibility change for older readers that treated the over-indented marker literally.
- **The ANSI quote bar reports containment, not node kind** (markup-carve/carve#1689). A quoted heading, code block, table, thematic break and lone image keep the bar, and a quoted list's bar sits outside its marker. All three engines agreed on the old behavior, so this moves agreed behavior rather than closing a divergence.
- **The canonical writer's round-trip contract names its carve-outs** (#1678, markup-carve/carve#1658, PART 11 §1c). A block whose whole content is one image, one comment, or nothing writes that inner spelling and loses the wrapper; everything else re-reads as what it was given.
- **A Djot blank-line run does not split a list on import** (markup-carve/carve#1430, PART 9 §11 N1a). `carve migrate --from djot` keeps an imported list as one list, with its numbering.
- **The HTML importer spells adjacent sibling lists with the hard boundary** (#1290, PART 9 §11 N1a): the same marker separated by three blank lines. Lists that already differ, or carry an explicit `data-marker`, keep the single blank line.
- **An imported nested container widens inward, as `carve fmt` writes it** (#1583, PART 9 §12).
- **The writer escapes per opener occurrence, not per unit** (#1533, #1519, markup-carve/carve#1533, PART 11 §2): `\{.note}` where the unit-scoped form wrote `\{\.note\}`. An opener RUN is still escaped whole.
- **The escalation search only offers the units the writer asks about** (#1577, markup-carve/carve#1546, PART 11 §2b).
- **A container's span ends at its last placed child** (markup-carve/carve#1522, markup-carve/carve#1524, markup-carve/carve#1530, PART 12 §4), no longer reaching over a hoisted definition or an unattached attribute block. Published `pos` values move.
- **Every closerless container ends at its last child, by one predicate** (#1638, PART 12 §4): `heading`, `footnote`, `definition_term` and `figure`. Published `pos` values move.
- **A css-mode panel is named, and `code-group` grows the same `mode`** (#1528, markup-carve/carve#1489, Extensions §13). **BREAKING: an unknown `mode` on either extension now throws** instead of falling back to `css`, which stays the default.
- **Three blank lines are a hard list boundary** (markup-carve/carve#1430, §11 N1a). Three or more before a compatible sibling marker open a new list at every level; one and two are unchanged.
- **The canonical writer separates two adjacent sibling lists with that boundary** (#1544), instead of indenting the later one by a space.
- **HTML conversion has a conservative borrowed fast path, for configured converters too** (#1506, #1515). Simple blocks render from source slices without building the public AST; nothing is published unless it re-renders byte-identically.
- **The default parse and extension dispatch paths do much less work** (#1489, #1490, #1491, #1498). The quoted-comment closer search the old prepasses hid is now linear.
- **The doubled run is the canonical arrow, in both families** (#1496, markup-carve/carve#1442): `<--` `-->` `<-->` and `<==` `==>` `<=>`. **BREAKING: `=>` no longer converts**, so `key => value` stays as written; `<=` keeps `≤`.
- **An empty brace pair is text, and `{--}` is an en dash** (#1497, markup-carve/carve#1447, markup-carve/carve#1450, §6c). `{//}`, `{**}`, `{^^}` and the rest render literally; a pair holding content is still the construct.
- **The braced en dash is the node the bare run produces** (#1499): a positioned `smart_punctuation` node keeping the authored `{--}`, so `carve fmt` gives it back.
- **Admonitions, task checkboxes and the footnote section carry accessible names** (#1512). An authored `aria-label` or `aria-labelledby` wins.
- **A row is a row, in every table section** (markup-carve/carve#1459, PART 10 §7). `<thead>` and `<tfoot>` write one row per line, as `<tbody>` always did. Nothing renders differently.
- **A table cell's marker run ends at a space** (markup-carve/carve#1259, PART 9 §5 T11), so `|=hot= |` is a highlight, `|=a |` a data cell and `|{#x}=R|` literal text. Formatted documents need no migration.

### Deprecated

- **The single-hyphen arrows `<-`, `->` and `<->`** (#1496). They still render; prefer `<--`, `-->` and `<-->`.

### Security

- **All definition and fence prepasses walk container prefixes by offset** (#1467, PART 9 §25).
- **The heading-reference prescan reads a line's container prefix at an offset** (#1463, PART 9 §25). A 128 KB line of `> - ` repeated copied 4.1 GB.
- **An alternating quote-and-bullet prefix is bounded before it can exhaust the stack** (#1456, PART 9 §25). Past the nesting cap it degrades to paragraph text.

### Fixed

- **The continuation marker's column gate reaches every container** (#1805, #1804, markup-carve/carve#1817, PART 9 §17 L3). A footnote body, a definition description and a block quote refuse a payload that is not flush-left, exactly as a list item already did.
- **A column-0 comment ends a definition description's body** (#1803, #1802, markup-carve/carve#1809). `tail` after the comment came out as a second paragraph of a description that had already ended, and a following definition list merged into the first.
- **An invisible line below a definition description's column folds into it** (#1801, #1799, markup-carve/carve#1809, PART 9 §10 I5). The line stays lazy text of the container it fell below instead of being re-emitted one level out.
- **An attribute line at column 0 under a definition description attaches** (#1798, #1794, markup-carve/carve#1801). The line was folded into the body as lazy text and ended it one line too late, with the attribute consumed as prose.
- **The canonical writer no longer forces a raised base on a nested definition list** (#1795, markup-carve/carve#1802). A structural predicate short-circuited the parser-backed rebase probe, producing non-canonical indentation and dropping a meaningful blank line for a definition list nested in a list item.
- **A wrapped block-attribute line leaves no item paragraph open** (#1792, markup-carve/carve#1799). The physical continuation was read as paragraph text, which kept the item paragraph open and absorbed a following below-column line.
- **A definition body in a list item keeps its payload across the blank line** (#1789, #1787, PART 1 §4). With no blank line above the `::`, the payload landed beside the `<dl>` instead of inside the `<dd>`, and a second entry after it opened a spurious second list; both now read as carve-js, carve-rs and the spec oracle already did.
- **The authored block base belongs to the innermost open container** (#1785, #1786, markup-carve/carve#1781, markup-carve/carve#1791, PART 9 §24 C3). The rule was spelled three ways: a definition entry in a list item ended at its separating blank where the same entry in a body carried its base across it, an opener written at a container's own minimum column was rebased out of the container it opened, and a line below the column it hands out came back as escaped prose. A definition entry is now measured at its exact authored extent, and a block at a description's or a nested item's content column stays inside it.
- **The continuation marker attaches one block in every container** (#1780, markup-carve/carve#1782, PART 9 §17 L3 and L4). A footnote body and a definition description took the whole reach where a list item took one block; a second block now costs a second marker, and a marker before a quote line attaches it.
- **A definition list inside a footnote body keeps the block its description holds** (#1779, PART 11 §1). The canonical writer narrowed the body's base past the payload, so `carve fmt` wrote a document that said something else.
- **A dangling attribute line does not reach a deferred footnote body** (#1770). An unattached attribute line at the end of a document no longer styles the first paragraph of the next note parsed.
- **A caption number placeholder at offset zero is recognized** (#1769), so `^ # H` numbers the caption instead of leaving the placeholder literal, as carve-js and carve-rs already did.
- **Trailing spaces and tabs after a bare colon fence are line whitespace** (#1768), so a `:::` followed by a tab opens a generic div rather than reading as paragraph text. A tab before a real type token is still not a marker separator.
- **A local hard-break block keeps its authored attribute order** (#1766): an authored class precedes the structural `hardbreaks`, and the attribute slots stay in source order, as carve-js and carve-rs publish them.
- **A definition entry inside a footnote body carries its authored base** (#1765), so a block written at the description's own column stays inside the `<dd>` instead of landing beside the list.
- **A promoted header cell keeps both axes of its marker run** (#1752), so `|?^ L |` in a delimiter table's first row keeps its vertical alignment.
- **A local hard-break block nested inside another one leaves nothing behind** (#1750). The outer body no longer ends a fence early, so no stray empty div follows it.
- **A `<figure>` around a self-captioning table stops inventing a caret** (#1746, markup-carve/carve-js#1488). Only one caption line is written, so the import no longer adds a caret the author never typed.
- **An element that did not survive an import says so, from one site** (#1725, #1734, #1739, #1741, markup-carve/carve#1723, markup-carve/carve#1738). An uncaptioned `<figure>`, a nested one and a `<section>` report `element-unwrapped`; an element that held nothing reports `element-dropped` instead; and the element's own row precedes its attribute rows.
- **A rebuilt `<figure>` carries the figure's own attributes** (#1729), so `<figure id="f" class="c">` comes back as the element the HTML had rather than as two declared losses.
- **A sectioning wrapper unwraps instead of becoming a `:::` container** (#1727), which had rendered back as a `<div class="article">` the source never carried. The wrapper and each dropped attribute are reported.
- **A `<figure>` around a table writes its caption on the table** (#1726), instead of detaching it as a paragraph the document never had.
- **An HTML comment imports as a Carve comment** (#1717, markup-carve/carve#1709) in every mode, fenced among blocks and delimited inside an inline run, instead of being dropped with nothing reported.
- **A code block's last newline is its terminator, not a line** (#1715, markup-carve/carve#1708), so an authored trailing blank line and trailing spaces survive HTML import instead of being trimmed away.
- **A checkbox consumed into a task marker is not reported as dropped** (#1711). The value was already right; the report contradicted it.
- **An imported item whose lead is bare text keeps its blocks tight** (#1710). Looseness is voted per list, and the separator inside an item takes the same vote.
- **An explicitly empty id survives HTML import, and a heading's ids are read off the element** (#1707), so `<h1 id="">` keeps `{id=""}` instead of regaining the slug its source suppressed.
- **An `<input>` type matches the checkbox keyword case-insensitively** (#1704), so `type="CHECKBOX"` imports as a task item rather than an ordinary bullet.
- **The Markdown target keeps every row from a multi-header table and uses the final effective column alignment** (#1763). Additional header rows degrade to body rows instead of disappearing, and body-cell overrides do not leak into the delimiter.

- **An exact-column fence in a definition body keeps its opaque payload indentation** (#1759). A backtick run inside a tilde fence is no longer reconsidered as an authored-base opener, so exact and over-indented spellings format identically and idempotently.
- **Fenced block-quote spelling survives the ProseMirror bridge** (#1749).
- **A task item's checkbox is content, so it does not move the content column** (#1693, PART 9 §24, ports markup-carve/carve-js#1455). The canonical writer and the HTML importer both indented an item's later blocks to the full marker-line width, so a block opener written four columns too far opened nothing.
- **A quoted lone-image paragraph keeps the blockquote bar in ANSI** (#1691, corpus 411), as carve-js and carve-rs already did. The promoted block image never reaches the paragraph path, so the carve-out only ever fired on the spelling that is still a paragraph.
- **An indented lone image is a paragraph, not a block image** (#1681, markup-carve/carve#1660, PART 9 §15), so a leading space is decisive for an image as it already is for a heading marker.
- **A figure caption never runs into its body, and never lands on a non-block** (#1676), so an inline figure body no longer concatenates with its caption.
- **The lone-image paragraph row reads what the paragraph writes** (#1673), so a `<p>` holding an image behind a transparent wrapper declares the same `structure-unspellable` loss a bare one does.
- **A figure finds its image behind a wrapper that writes nothing** (#1672). A `<figure>` whose `<img>` sits inside a `<p>` keeps the figure and its caption binding, instead of writing a block image and an unrelated paragraph.
- **An authored paragraph around a lone image is a declared loss** (#1667). The importer reports `structure-unspellable` and names each attribute the image's own block overwrites; nothing it writes moves.
- **A grouping label keeps a div's fence, and comes back on its opener** (#1661), so `::: [g]` survives a render and an import instead of unwrapping and leaving the label as a `{.div-label}` paragraph.
- **A definition description dedents by its own content column** (#1650, PART 9 §16 with §10 I5). A footnote definition inside a `<dd>` keeps its indented continuation, and its body column is the container's content column plus two.
- **An authored heading id that differs from its slug only in case survives HTML import** (#1608, follow-on to #1289): the generated-id check compares exactly, so `{#methods}` on `## Methods` is no longer dropped and regenerated as `Methods`.
- **An imported block-attribute line ends its own line** (#1653), so the attribute no longer floats forward past a blank line to another block.
- **The HTML importer spells the looseness the layout cannot say** (#1648, PART 9 §17 L7).
- **A dropped definition description is declared by what it writes, not what it holds** (#1649), so `<dd><p> </p></dd>` and `<dd><ul></ul></dd>` report `structure-unspellable`.
- **A footnote body survives a blank run** (#1643, markup-carve/carve#1633).
- **A position is spelled on the line its own offset is on** (#1639, PART 12 §4).
- **A dropped empty description breaks the list rather than lending it** (#1646, markup-carve/carve#1636).
- **An empty definition description declares the loss it already took** (#1615): the `<dd>`'s path now reports `structure-unspellable`.
- **An import says what it drops, and a block that spells nothing keeps a list boundary** (#1633, PART 11 §7 and §10j). An all-layout block reports `element-dropped`; U+00A0, U+202F and U+3000 stay content.
- **The canonical writer honors the declared-loss ceiling too** (markup-carve/carve#1627).
- **A declared loss is a ceiling, not a license, and an endnotes section keeps its position** (#1629, markup-carve/carve#1627).
- **An import keeps the meaning the HTML held, in three shapes** (#1615): a paragraph whose text opens `^ `, a caret that opens a bracketed label, and an empty `<ins>` or `<del>`, which reports `element-dropped`.
- **A Markdown delimiter is owned per occurrence, not per character** (#1624), so an unclosed backtick run is ordinary text rather than a code span running to the end of the block.
- **A BBCode `noparse` body is literal at the block level too** (#1622), so a line-initial `- ` inside it no longer reaches the document as a list.
- **A fence keeps its blank runs behind whatever a container wrote to its left** (#1618).
- **A BBCode list keeps its structure, and every line keeps its block context** (#1619, #1620, #1621, #1623): nesting, continuation columns, sibling markers and `[list=a]` are all read correctly now.
- **What sits between two lists is measured by what it writes** (#1617), so an element rendering to nothing no longer erases the hard boundary and a bare text run no longer gains one.
- **The BBCode code fence trims where the body is still text** (#1612).
- **A nested literal run no longer leaks its stash sentinel** (#1611, markup-carve/carve-js#1375).
- **The no-destination rule outranks a provenance attribute, and unwrapped alt text is prose** (#1613, markup-carve/carve#1609): a `data-djot-*` attribute beside a blanked `href` no longer rebuilds the link, and an unwrapped `<img>`'s alt value is escaped.
- **A destination Carve cannot carry is not a destination** (#1605, markup-carve/carve#1609). An `<a>` with no `href` and an `<img>` with no `src` come back as their content, reported as `element-unwrapped`.
- **The symbol sigil is escaped where it opens a symbol** (#1605, markup-carve/carve#1601, PART 11 §2): `a :rocket: b` imports as `a \:rocket: b`. A colon that closes no shortcode is unchanged.
- **A table cell whose whole payload is a span marker is escaped** (#1605, markup-carve/carve#1601, PART 11 §6f): `| \^ |` and `| \< |`, while a real `rowspan` or `colspan` keeps the bare marker it needs.
- **Intraword emphasis survives HTML import** (#1602). `<p>Sy<strong>rup</strong>-free</p>` imported as `Sy*rup*-free`, throwing the bold away; it is now `Sy{*rup*}-free`, agreeing with carve-js.
- **`carve --help` names the HTML import modes the CLI accepts** (#1601).
- **A quoted fenced block keeps its blank lines on Djot import** (#1600).
- **The Markdown and plain-text renderers keep a figure caption's authored leading whitespace** (#1594, corpus 404), as carve-js and carve-rs already did.
- **`carve fmt` keeps a heading's leading tab** (#1595, markup-carve/carve#1587, PART 11 §1, corpus 406): the marker separator is a run of spaces.
- **An imported list item's attributes abut its marker** (#1587), as `1.{#fn1} n`, rather than an indented line below it where a one-block item lost the attribute.
- **A non-`li` child of a list keeps its content and says so** (#1589, ruling markup-carve/carve-rs#1266): emitted ahead of the list and reported as `element-unwrapped`.
- **An endnotes section nothing references keeps its text** (#1582, markup-carve/carve#1558), as the `<hr>` and `<ol>` it is built from.
- **The HTML import report no longer calls a derived accessible name a dropped attribute** (#1579, markup-carve/carve#1502). An attribute genuinely lost still reports.
- **A marker on a block quote's lazy continuation is text** (#1575, markup-carve/carve-js#1200, PART 9 §10 I6), not a sub-list.
- **A marker at an item's content column opens a sublist, first in the item or not** (markup-carve/carve#1517, PART 9 §24 C3).
- **A blank verbatim line inside a block quote drops the prefix's trailing space** (markup-carve/carve#1544, PART 11 §7): `>`, not `> `.
- **The AST-JSON ingest replaces U+0000, as the parse boundary already does** (#1562, markup-carve/carve#1528, PART 12 §21). The Markdown and BBCode importers replace it too.
- **An import can take the `labels` map the HTML was rendered with** (markup-carve/carve#1500) via `new HtmlToCarve(labels: [...])`. Passing nothing is unchanged.
- **A replaced NUL leaves codepoint offsets, and the text node keeps its position** (#1563, markup-carve/carve#1525, markup-carve/carve#1534, PART 12 §4). Published `pos` values move on documents holding a NUL.
- **The canonical writer spells two sibling sub-lists inside a list item** (#1553, markup-carve/carve#1501, PART 9 §11 N1a) at the item's content column, instead of behind the `+` marker at column 0.
- **An escape escalation reaches the block that failed, not the document** (#1552, markup-carve/carve#1516, PART 11 §2b).
- **A block matched on a first-character fast path spans every line it consumed** (markup-carve/carve#1451, PART 12 §4).
- **A footnote definition inside a container ends with its body** (markup-carve/carve#1451, PART 12 §4), not one codepoint past it.
- **A tab control is `type="button"`, and two marked items select one tab** (#1537, markup-carve/carve#1504, Extensions §13.3 and §13.5): an `aria`-mode control inside a `<form>` no longer submits it, and the first `{selected}` wins.
- **Math survives HTML import** (#1543, #1554, markup-carve/carve#1518, ruling markup-carve/carve#1514, PART 9 §18). Inline and display math import as the core forms, keeping any authored id, class and `data-*`.
- **A block beside loose text in a container stays a block** (#1543).
- **An import drops an accessible name the renderer derives** (markup-carve/carve#1500).
- **A static code-group panel label is a heading** (#1535, Extensions §2.5).
- **A tab or code-group label is text, not an attribute value** (#1538, PART 10 §2), so a double quote in the label element's text is no longer escaped; the attribute keeps its `&quot;`.
- **A titled admonition's generated title reference no longer survives import** (markup-carve/carve#1468).
- **The index back-link says where it goes** (markup-carve/carve#1469): the k-th is named `Back to {term} {k}` and shows `↩<sup>k</sup>`, from the `indexBackref` label.
- **A rendered diagram fence is an image with a name** (markup-carve/carve#1468): `role="img"` and a `label` on the hydration element and the static wrapper. An authored `role` or name wins.
- **A half-formed braced pair keeps its carets bare** (#1522, PART 11 §2). `{^x`, `x^}`, `{^`, `^}`, `{^}` and `{^{^x^}^}` no longer carry an invented `\^`.
- **A tab set and a code group say what they are** (#1526, markup-carve/carve#1468, Extensions §1.5): a `role` and a name on both wrappers, from an authored attribute, the new `groupLabel` option, `labels`, or a default.
- **The published tab CSS keeps the control reachable from the keyboard** (#1525). The radios are clipped rather than `display: none`, and the focus ring is drawn on the label; `CodeGroupExtension` publishes the same recipe.
- **The footnote backlink has an accessible name** (markup-carve/carve#1455, PART 9 §16): `Back to reference`, or `Back to reference 2` for the second of several, instead of the bare `↩` glyph.
- **A hyphen run that opens a word after whitespace is a flag, not a dash** (markup-carve/carve#1443, PART 9 §8): `git log --oneline` keeps its hyphens, while `pages 1--10` converts as before.
- **An empty braced pair keeps its carets bare** (#1516, markup-carve/carve#1482, PART 11 §4 with §1). `{^^}` holds no content; `}^p` and `[^` write bare too.
- **An empty comment writes its marker and nothing else** (#1514), instead of `%% ` with a trailing space no clause asks for.
- **A continuation marker attaches only a flush-left block** (#1501, markup-carve/carve#1436, §17).
- **A lazy marker line's definition defines nothing** (#1486, #1487, #1488), so a probe and the real parse cannot classify a document differently.
- **A definition hosted by an emptied marker item is written back into it** (#1493, markup-carve/carve#620, PART 11 §1).
- **An unclosed inline literal reaches the end of its block**, and **a list item beginning with an empty-destination reference-shaped line keeps that line and its lazy continuation** (#1485, markup-carve/carve#1418, markup-carve/carve#1420).
- **A table alignment marker run is validated as a whole** (#1472, #1474, #1478, markup-carve/carve#1344), so a duplicate axis such as `<<` falls back visibly. Also **an unmarked definition-shaped lazy continuation inside a quoted list is preserved**.
- **An all-blank raw payload remains distinct from an absent payload** (markup-carve/carve#1414, corpus 372). One blank line between raw fences produces one newline.
- **Raw blocks preserve authored leading and trailing blank payload lines**, and unterminated fence-shaped text inside an open list/definition paragraph keeps that paragraph open (markup-carve/carve#1414, corpus groups 366 and 367).
- **Definitions collected at a list item's content column close its paragraph** (markup-carve/carve#1376). A following line below that column no longer uses the comment-only continuation path; bare-dot items use the bullet column.
- **A hard break ends at column 1 of the following physical line** (#1457), including where the next line is a comment-only line the block layer removes.
- **A line block opened on a container's opener line is seen by both definition prepasses** (#1444).
- **A verse comment inside an image's ALT has one spelling**, a bare `%%` line, for inline and reference images alike (#1443).
- **An abutting attribute block attaches to a plus bullet** where the extension is enabled, as it does to every other marker (#1438).
- **The flatten separator and the content-column family follow their rulings** (PART 11 §1b, #1435): the separator is decided per token, an existing NBSP is kept, and table-cell and definition-part boundaries survive.
- **The caption slot reports the blocks it flattens**, against the DOM nodes it actually consumed (#1353).
- **A lazily collected or over-indented comment no longer closes a list item**, and no longer erases the fact that the block above it was invisible (#1458, follow-on to #1421).
- **A heading written at a container's content column leaves no paragraph open** (#1464).
- **A blank line loosens a list item only when a paragraph follows it** (#1657, #1659), so a blank line before an attached `:::` container or a colon fence leaves the item tight.

## [0.1.5] - 2026-08-18

### Security

- **An attribute holding a LIST of URLs is probed at every candidate, not at
  its head** (PART 9 §25, markup-carve/carve#1320, markup-carve/carve#1326).
  `srcset` and the three other list-valued attributes vouched for the whole
  value from its leading scheme, so a dangerous scheme anywhere but first went
  unread. The value-wide probe is an ADDITION to the leading-scheme one, not a
  replacement, so a scheme token holding whitespace is still caught (#1388,
  #1390).

- **The HTML importer's attribute strip policy is a rule, asked in one place**
  (markup-carve/carve-php#1375). `isStrippedImportAttribute()` carries the whole
  policy and every writer asks it, so an `on*` handler no longer reaches the
  Carve source; the five-name enumeration is gone and `srcdoc` and `formaction`
  join it. An unknown attribute is still kept.

### Added

- **A bibliography definition line is a node on the wire** (PART 12 section 18).
  `[@key]: {author= year=} entry` is a `citation_definition` carrying the key
  without its sigil, the entry as inline `children`, the metadata block as
  `attrs` and its position, so an AST round trip no longer deletes it. Tier-2:
  only a parse with the Citations extension enabled produces one. Rendered
  output is unchanged on every target.

- **A `carveDiv` says how its class was written.** `carveTyped` distinguishes
  `::: sidebar` from `{.sidebar}` above a bare `:::`.

- **The renderer reports two losses it always had**: the authored ORDER of an
  attribute run, which the wire splits into `id`, `class` and one
  `carveKeyValues` bag, and a soft break, whose text survives as a newline
  while the node does not.

### Changed

- **A line block's hard break is written by the PART 11 §7c property, not by a
  list of cases** (markup-carve/carve#1340). A line whose last node is a comment
  is exempt, an empty comment line no longer gains a separator space, and a
  stanza's last line keeps its trailing backslash.

- **A line block hardens a soft break at every depth**
  (markup-carve/carve#1351), so a boundary inside an emphasis run hardens the
  way its backslash spelling already did.

- **A table is a table however its last row is spelled**
  (markup-carve/carve#1348), and **an invisible line at a container's content
  column ends the paragraph rather than the container**
  (markup-carve/carve#1350).

- **The ProseMirror bridge speaks the published wire shape**, so a document
  stored by carve-grammars and read here keeps its list tightness, reference
  spelling and cell spans. Authored key/values travel in one `carveKeyValues`
  map; a list carries `carveTight`, `carveOlType` and `carveDelim`; a link mark
  carries `carveReferenceDefinition`; a table cell omits a span of 1, carries
  its alignment as `textAlign` and holds a paragraph; an inline extension
  carries its `name`; a figure's image panel is wrapped in a paragraph; an
  abbreviation is the `carveAbbreviation` mark; a code fence's metadata is
  `carveHeader`/`carveLabel`; and a soft break crosses as a NEWLINE rather than
  a space. `resources/prosemirror-wire-fixtures.json` pins all 35 constructs in
  both directions.

- **The bridge carries the attribute run the author typed, and the marks that
  hold no text.**

  - The authored SLOT ORDER of a run rides in `carveAttrOrder` and the writer
    replays it, so `[x]{key=c .a #b}` no longer comes back regrouped. Value
    quoting and classes interleaved with other slots are not recoverable and
    are not faked.
  - An attribute run on inline code: the `code` mark takes `id`, `class`,
    `carveKeyValues` and `carveAttrOrder`, the same four slots as every other
    attributed node.
  - A mark with no content - `[](https://example.com)`, `[]{.a}`, `{++}` and
    `{--}` - crosses as the schema's `carveEmptyMark` atom instead of vanishing.

  `SchemaMap` reads the map's `markCarrierNodes` and `preservationNodes`
  sections alongside `types`.

- **The preservation atoms another bridge writes are read, not refused.**
  `carveUnsupported` and `carveUnsupportedInline` are on the wire (the map's
  `preservationNodes`) and their source is written back verbatim instead of the
  document being rejected.

- **Delimited inline comments are now recognized, which is a behavior change.**
  `foo {% bar %} baz` previously rendered its braces and now hides the middle,
  producing `foo  baz`. The new `braced-comment-in-a-template-source` lint rule
  reports this syntax when Liquid, Nunjucks or Twig source reached the parser
  as text; it never rewrites the source. The ProseMirror bridge carries the
  spelling in both directions - the `delimited` flag PART 12 publishes rides on
  the payload too, for the inline and the block form - and a comment inside a
  table cell crosses as well.

- **An HTML import diagnostic's `path` follows one convention shared with
  carve-js and carve-rs** (markup-carve/carve#1257). It is a human-readable
  locator that borrows XPath's notation but must not be resolved as one: it
  starts at the top level of the fragment the importer was handed, so neither
  the wrapper `<div>` nor an authored `<html>`/`<body>` is named; `[n]` is the
  position among ALL of the parent's child nodes, text included; and it names
  the traversal the conversion performs, so a table's rows are flattened out of
  `<thead>`/`<tbody>` and numbered across the whole table and a list's items are
  numbered among the items. The shared contract fixtures now check `path`
  alongside `code`, `message` and `severity`. An `<html>`, `<head>` or `<body>`
  still reports its own attribute losses under its own name.

- **Every table cell pads its content in the canonical form** (PART 11 §6e).
  One rule covers every cell - `|= Heading |`, `|={.total} Total |= 99 |` -
  with the prefix still glued to the opening pipe and an empty cell taking a
  single space. This also removes the guard that inserted a space only for
  content beginning with `<`, `>` or `~`.

- **The HTML importer reads `<math>` through a three-tier lookup**
  (markup-carve/carve#1210 D6). The TeX comes from an `<annotation>` whose
  `encoding` is exactly `application/x-tex`, `text/x-tex` or `LaTeX`
  (case-insensitive) and which is a direct child of the element's own
  `<semantics>`; failing that from `alttext`, which carries an
  `encoding-assumed` info; failing both, the element carries no TeX at all.
  Three changes for upgraders: a declared encoding now beats `alttext` where
  they disagree; the encoding test is an exact match on the whole value and no
  longer reaches through the subtree; and the last tier drops the element with
  an `element-dropped` warning instead of importing it as its children
  concatenated. `roundtrip` still keeps the whole element as a raw-HTML inline.

- **A cell's attributes bind after its kind and alignment markers** (PART 9 §5
  T10, markup-carve/carve#1226). One order for both cell productions: `=`, then
  the alignment marker, then the `{...}` block, glued to whatever precedes it -
  `|={.x} h |`, `|=~{.x} h |`, `|>{.x} d |`, `|{.x} d |`. An attributed header
  cell is expressible for the first time, so `<th id="x">R</th>` no longer round
  trips as a data cell, and `|={scope="colgroup"} a |` is how §5 T9 documents
  reaching `colgroup` and `rowgroup`. ONE RELEASED SPELLING REINTERPRETS: in
  `|{#x}< content |` the `<` is no longer in a marker position, so it is literal
  content and the cell is not aligned; the new lint rule below reports it. Row
  attributes still glue to the row's closing pipe.

- **An attributed header cell survives HTML import**, and the `table-degraded`
  diagnostic "N header cell(s) become data cells" is gone with the loss it
  named. A `th` carrying attributes is written `|={#x} R |` and comes back a
  header cell; a head row whose cells carry attributes uses the native marker
  form instead of a delimiter row. The other `table-degraded` cases are
  unchanged.

- **A referenced abbreviation definition splits by target** (PART 11 §10f,
  markup-carve/carve#1185). The plain-text and terminal writers now drop the
  `*[TERM]: expansion` line and print `TERM (expansion)` at every occurrence
  instead; Markdown keeps the line and the expansion beside it, and the
  canonical writer keeps every line for PART 11 §1. A definition whose
  expansion reaches no target keeps its line on all four writers: one nothing
  references (§10a), one an authored `abbr` outranks, and one a later
  definition of the same term shadowed.

- **Leftover attributes ride the outermost semantic element.**
  `[Ctrl+C]{kbd .shortcut #copy}` is
  `<kbd class="shortcut" id="copy">Ctrl+C</kbd>` where it was a `<span>`
  carrying those attributes around a bare `<kbd>`. A
  span with no semantic name is unchanged, and a DERIVED attribute yields to an
  AUTHORED one of the same name.

- **The Markdown target escapes `<` only where it would open markup** (PART 11
  SS8a M1e, markup-carve/carve#1148). A `<` is escaped with a BACKSLASH when
  the next character is an ASCII letter, `/`, `!` or `?`, and left alone
  otherwise; `>` takes nothing, since M1 already covers it at line start. A
  link destination carries its URL as the real character rather than as entity
  text.

- **The Markdown target leaves a bare ampersand alone** (#1150). `<` and `>`
  keep their handling, which is what neutralizes embedded HTML.

- **Bidi control characters are stripped from presentation targets** (#1152),
  so Trojan-Source reordering cannot survive into plain-text, ANSI or Markdown
  output.

- **Plain-text and ANSI targets preserve list structure** (#1153) instead of
  flattening items into prose.

### Fixed

- **A colon-fence lead does not decide an item's looseness** (PART 9 §17 L1a,
  markup-carve/carve-php#1450). A blank-line-separated second paragraph after a
  `:::` lead rendered bare where every other lead wrapped it.

- **A joined table row's untouched cell keeps its position, and a verbatim run
  that swallowed a comment keeps its extent** (PART 12 §4,
  markup-carve/carve-php#1450).

- **A blank line before a sibling marker separates the items** (PART 9 §17 L1c,
  markup-carve/carve-php#1445). An unterminated code or tilde fence absorbed the
  blank and left the list tight, so the closer decided a rule that is not about
  closers.

- **A definition's column is reached by composing the container strips**
  (markup-carve/carve-php#1431).

- **A reference label keeps its comment for `fmt` and publishes it nowhere**
  (markup-carve/carve-php#1417).

- **A continuation row belongs to the container it is written in**
  (markup-carve/carve-php#1436).

- **A captioned host no longer writes its block indentation into an attribute
  value** (markup-carve/carve#1352, markup-carve/carve-php#1422).

- **A quote is asked its own body, and that body may be a quote**
  (markup-carve/carve#1355); **at a container's content column a block ends the
  paragraph it sits under** (markup-carve/carve#1364, markup-carve/carve#1357);
  **a block's extent is the definition's, blank lines and all**
  (markup-carve/carve#1363); and **a flatten preserves the boundary it
  dissolves** (PART 11 §1b, markup-carve/carve#1325), where two former siblings
  each contributing a token now keep a separator between them.

- **An orphan caption reports that its text left the document**
  (markup-carve/carve-php#1386).

- **The sentinel picker refuses rather than returning a collision**
  (markup-carve/carve-php#1398).

- **A sigil before a verbatim run is escaped where it binds**
  (markup-carve/carve-php#1412), so a `$` the writer emitted no longer reads
  back as math and `toHtml(fmt(x))` matches `toHtml(x)` again.

- **A verse comment is removed at the block layer, before any inline run**
  (PART 9 §23, markup-carve/carve#1333), so a stray backtick on the line above
  no longer publishes it, and **a verse hard break keeps its backslash**.

- **A verse comment is placed at its boundary wherever that boundary is**
  (markup-carve/carve-php#1411).

- **A comment-only line in a line block is a comment on every line, not only
  the first** (markup-carve/carve-php#1393).

- **A comment fence reads its container prefix as a sequence**
  (markup-carve/carve-php#1413), and **a comment fence in a quote defers its
  definitions too** (PART 9 §28, markup-carve/carve#1309) - a link reference or
  footnote inside `%%%` in a block quote used to register.

- **No open paragraph binds at every depth, not only the first**
  (markup-carve/carve-php#1403).

- **A line's content position is measured after its container prefix**
  (markup-carve/carve#1331), and both scans that measured it are bounded.

- **An element's code says what became of it, and a discarded input says so**
  in the HTML import report (markup-carve/carve-php#1377).

- **Three superlinear container walks are linear** (PART 9 §25). A line of N
  list markers is walked by offset rather than by copy
  (markup-carve/carve-php#1426); the quote tracker peels its markers once
  rather than once per marker (markup-carve/carve-php#1407); and the
  trailing-block tracker peels its container prefix by offset, which is what a
  line alternating a quote marker with a bullet costs
  (markup-carve/carve-php#1437).

- **An at-sign in source text is not a Carve mention**
  (markup-carve/carve-php#1380). Importing `hi @user ok` no longer produces a
  mention span, matching the escape the converter already applied to the tag
  opener.

- **A line block's spaced content is placeable, so it is placed**
  (markup-carve/carve-php#1351). `SourceMap::add()` records N source bytes as N
  built bytes and a line block's preserved whitespace does not build that way,
  so the content of a spaced line published no position at all.

- **A definition list's extent covers the lines it owns, and stops there**
  (markup-carve/carve-php#1362, markup-carve/carve-php#1371). A floating
  attribute line inside the list is now covered, a reference definition written
  at column 0 under the description is not, and a flush-left `+` the list
  consumed is one of the list's own lines.

- **A line block's paragraph ends where its content does**
  (markup-carve/carve-php#1363). The stanza's extent no longer covers a
  trailing column of whitespace its content does not contain.

- **A node assembled from discontiguous source publishes no position, and a
  gap alone is not evidence of one** (markup-carve/carve-php#1361,
  markup-carve/carve-php#1369). A verbatim run a table row leaves open reaches
  into the `+` continuation row and neither end resolves to an honest offset,
  so it publishes none; an indented fence, whose map has a gap per line but is
  one contiguous region, keeps its position.

- **The definition prepass opens a region only where the parser opens a block**
  (markup-carve/carve-php#1348). A line the block parser refuses as a fence no
  longer opens an opaque region, which had no closer ahead of it and so ran to
  the end of the document, leaving every definition after it uncollected.

- **A bare-dot item suppresses abbreviation collection like any other marker**
  (markup-carve/carve-php#1328). A column-0 abbreviation definition under a
  `. x` item is no longer collected and applied to the whole document.

- **A tab after a fence opener is decided by its position**
  (markup-carve/carve#1295, markup-carve/carve-php#1329). A tab between the
  marker and the info string is the marker-to-content separator, which admits a
  space and nothing else, so the fence does not open; a trailing tab at end of
  line is dropped and the fence opens normally.

- **A container's last block decides whether a column-0 line folds into it**
  (markup-carve/carve#1280, corpus category 326). NO OPEN PARAGRAPH, NO LAZY
  LINE was applied only to an empty quote, so a column-0 line folded into a
  list item and ended a block quote whenever the container's last block left no
  paragraph open.

- **A continuation marker attaches one block, and that block's extent ends it**
  (markup-carve/carve#1290, corpus category 327). A `+` attaches exactly ONE
  block; the run to the next blank line, sibling marker or further `+` is that
  block's extent rather than a count of blocks.

- **A floating attribute is scoped to the container that holds it**
  (markup-carve/carve#1281, corpus category 329). A pending attribute block is
  dropped at the container's end instead of escaping to the block after the
  closer.

- **An open verbatim run reaches across a table continuation row**
  (markup-carve/carve#1293). A `+` continuation extends the cell, so an
  unclosed run reaches the end of the continued cell rather than stopping at
  the first line's closing pipe.

- **An escaped closing pipe is cell content, not the row terminator**
  (markup-carve/carve#1293). The row closes on the line's final pipe; an
  escaped pipe before it stays in the cell.

- **An unterminated run does not un-make a header-less row**
  (markup-carve/carve#1284). An unclosed verbatim run no longer swallows the
  row's closing pipe, which had left content dangling past the last pipe and
  stopped the row splitting into a table at all.

- **An unclosed inline run reaches the end of a line block**
  (markup-carve/carve#1282). A line block is one block, so its line breaks are
  a rendering instruction rather than a boundary the inline parser can see, and
  what the run carries across the break is a newline rather than a space.

- **Position tracking no longer costs the block twice**
  (markup-carve/carve-php#1339). Two separate scans over a source map turned a
  linear parse quadratic, both reachable from a plain multi-line paragraph and
  only with position tracking enabled.

- **A definition term drops the trailing whitespace on its folded lines**
  (markup-carve/carve-php#1330). The trailing-whitespace strip a paragraph and
  a definition term share was never called by the term.

- **A caption slot flattens block content instead of writing its source**
  (markup-carve/carve-php#1345). A caption line is an INLINE slot, so a
  `<caption>` holding a list now flattens to its text rather than having a
  block's Carve source written into the slot, where it re-parsed as prose.

- **The import report describes the document it produced**
  (markup-carve/carve-php#1337, markup-carve/carve-php#1346). Every row is read
  off the finished document rather than predicted from a list of attribute
  names: an attribute the serializer kept is never announced as dropped, one
  dropped inside a table cell is never passed over in silence, and an attribute
  nobody wrote a case for is answered for like any other.

- **`Lint\FigureGroupLinter`, the five composite-figure rules** (PART 9 §4c;
  markup-carve/carve-php#1308). A tree-walking pass fed from the parsed
  document, reporting `figure-group-nested`, `figure-group-opener-metadata`,
  `figure-group-panel-number`, `figure-group-empty` and
  `figure-group-single-panel`, wired into `carve lint` beside the existing
  passes; the panel predicate is the numbering resolver's own and the message
  wording mirrors carve-js.

- **The ProseMirror bridge carries a composite figure** (PART 9 section 4c). The
  vendored copy of the published schema map is refreshed, so a group renders as
  `carveFigureGroup` with its caption as the trailing `carveCaption` child
  rather than crossing as a plain `carveDiv`, and a `carveFigureGroup` arriving
  from an editor is no longer refused.

- **The `word` and `google-docs` HTML import adapters read footnote-shaped
  HTML as footnotes** (markup-carve/carve#1210). `--adapter word` or
  `--adapter google-docs` (or the `importAdapter` constructor argument) binds
  each reference to its note and writes real `[^N]` references and definitions,
  matching through the fragment each anchor addresses and the back-link the
  note carries rather than through vendor class names or the `fn1`/`fnref1` id
  convention. Back-links, the marker anchors they sit on and the separating
  rule are dropped as generated navigation; a reference with no target stays
  the link the HTML spelled and a definition nothing references stays ordinary
  visible content. `generic` is unchanged.

- **Composite figures: `::: figure` is a captionable host** (PART 9 §4c,
  markup-carve/carve#1122). A bare `::: figure` container parses as the new
  `figure_group` AST node - one figure holding ordered panels, its direct
  captionable children, with stray content preserved in place - and the `^ `
  line after the closing fence is the GROUP caption. The group draws ONE number
  from its label's sequence and a panel id resolves `</#id>` as the group
  number plus a letter ("Figure 2a"). An opener carrying a quoted title or
  `[label]` stays a generic container, and groups do not nest. HTML renders the
  corpus-pinned flat `carve-figure-group` / `carve-figure-panel` shape; the
  Markdown, plain-text and ANSI targets degrade deterministically; `carve fmt`
  writes the authored form back; the AST wire carries `figure_group` with
  inline `caption` content; and the HTML importer turns the rendered shape back
  into `::: figure` source.

- **The ProseMirror bridge carries every authored construct** (PART 12
  vocabulary, the schema map's former `unmapped` list). Figures with their
  captions, line blocks, comments, front matter, raw blocks and raw inlines,
  inline literals, symbols, critic substitutions, inline footnotes, crossrefs,
  citation groups and link reference definitions all cross now, in both
  directions. Only an image panel has its paragraph wrapper unwrapped; a
  figure's short caption rides as a second `carveCaption` child flagged
  `short`; a citation item's prefix, locator and suffix ride as ProseMirror
  inline arrays. With the definitions carried, a `[text][label]` reference -
  image references included - keeps its spelling and falls back to the inline
  form only when its definition is gone or repointed, and an abbreviation
  definition child is no longer double-reported as dropped.

- **`Lint\RetiredSpellingLinter`**, an AST-walking pass reporting source
  written to a spelling Carve has since redefined, with one rule `carve lint`
  now reports alongside the others. `table-cell-attribute-before-marker` fires
  on a table cell whose attribute block is immediately followed by `<`, `>` or
  `~` - the retired order, where that sigil was the cell's alignment and is now
  content - and names both spellings. It is a REPORT and not a `fmt` rewrite by
  design, because rewriting `|{#x}< content |` would change the render.

- **`Lint\SemanticAttributeLinter`, this package's first AST-walking lint pass**
  (markup-carve/carve#1131, markup-carve/carve#1132), with two rules reported
  alongside the Markdown habits. `semantic-attribute-value-ignored` reports a
  value on a semantic span name that only selects its wrapper (`[x]{kbd="V"}`);
  `semantic-attribute-outside-span` reports a reserved name on anything other
  than an ordinary span (`` `c`{kbd} ``). Both are tier-aware - pass
  `extensions` to lint the render you publish - and `cite` on a block quote is
  never reported. The off-span message quotes the attribute the render will
  contain, after the renderer's sanitizer and cut at 120 codepoints. Rendered
  output is unchanged.

- **Structural AST merge and patch APIs** (#1162). A three-way merge with
  explicit JSON-Pointer conflicts and optional base/ours/theirs/custom
  resolution, position-independent patch creation and replay, and
  `carve merge [--json] base ours theirs` on the CLI. Results are validated
  through the normal PART 12 decoder, and ambiguous wide-list matching is
  bounded. Authored attributes named `pos` or `srcByteLength` survive, while
  stale generated positions are discarded after a merge or patch.

- **Structured HTML import diagnostics and a migration CLI** (#1174), and
  `carve migrate --from` reaches every importer - HTML, Markdown, BBCode and
  Djot (#1231). The HTML importer reports what it could not carry faithfully
  instead of dropping it silently.

- **HTML import spells all seven semantic elements as the compact span
  attribute.** `<cite>C</cite>` and `<time datetime="2026-01-01">today</time>`
  join the five that already mapped, importing as `[C]{cite}` and
  `[today]{time="2026-01-01"}`. A name whose value attribute is absent gives
  the bare boolean, leftover `id`, `class` and `data-*` ride the same span, and
  the import report records no loss for any of the seven, in all three modes.
  `<mark>` keeps its `=m=` spelling, inline `<code>` keeps its code span, and
  `<code>` inside `<pre>` keeps going to a code block. `abbr`, `time` and `kbd`
  render back as the original element; `samp`, `var`, `cite` and `dfn` need
  `SemanticSpanExtension` registered to do so.

- **Opt-in list-table output for table cells holding block content** (#1168).
  `HtmlToCarve` never writes a construct a table cell cannot parse; a degraded
  wrapper keeps the boundary it carried (#1165, #1166).

- **`DjotToCarve` converts Djot's braced subscript and superscript** (#1187)
  **and intraword emphasis** (#1198). `H{~2~}O` becomes `H{,2,}O`, and
  `intra{_word_}emphasis` converts to the braced `{/.../}` form instead of
  staying literal.

- **Semantic language attributes** (#1228). `[x]{:fr}` is exact sugar for
  `{lang=fr}`, on inline spans and block attribute lines alike; `{:}` desugars
  to `lang=""`.

- **Structural short captions are preserved in AST JSON** (#1193), with
  accessors on the owning nodes.

- **Three semantic span attributes need no extension** (markup-carve/carve#1146).
  `[Tab]{kbd}`, `[HTML]{abbr="…"}` and `[now]{time="2026-01-01"}` render
  `<kbd>`, `<abbr title="…">` and `<time datetime="…">` in a plain converter,
  per spec PART 9 §9. `time` is new as a span attribute; `kbd` and `abbr`
  previously needed `SemanticSpanExtension`.

- Bumped the pinned spec corpus to carve `df0dbc4`, adding conformance coverage
  for categories 306-315, up to and including PART 9R R2 - a footnote inside an
  unresolved reference is not a reference (markup-carve/carve#1198). All 37
  documents already rendered byte-identically, so rendered output is unchanged.

- **`SemanticSpanExtension` is specified, and carries `cite`** (spec PART 9 §10,
  docs/extensions.md §11). It is a Tier-2 extension every engine ships rather
  than this package's own, so `[Dune]{cite}` joins `samp`, `var` and `dfn`, and
  the `:name[…]` spelling is accepted for all seven names as a SOFT-DEPRECATED
  compatibility form scheduled for removal in 0.2. Registering it stays one
  line; the nesting order, the value mapping and the riding rule now have one
  implementation in the renderer.

- **The Carve target writes no `+` continuation marker where a block-attributes
  line already interrupts** (markup-carve/carve#1275). `block_attributes` is one
  of PART 9 §10's INVISIBLE CONSTRUCTS - it interrupts an open paragraph - so
  the lazy fold the marker guarded against cannot happen and the marker added a
  construct the document did not have. An attributed IMAGE keeps the marker,
  because its attributes are written inline (`![a](i.png){.c}`) and no
  attribute line interrupts.

- **An abbreviation line in a list item is the paragraph it renders**
  (markup-carve/carve#1267). PART 12 §7 says `*[A]: a` is a definition only as
  a direct child of the document; inside a container it is ordinary paragraph
  text. It was counted among the invisible constructs at two independent sites
  - the item's looseness decision, and the §17 L1b scan for what stands behind
  an invisible line - so an item holding it came out tight where carve-js and
  carve-rs wrap both paragraphs. A definition that IS collected still keeps the
  item tight, and the no-blank-line variant still folds as lazy text.

- **An attribute block reaches a nested list written with no blank line before
  it** (markup-carve/carve#1238). Inside a list item, a `{...}` line directly
  above a nested list was discarded; it now attributes the nested list, as the
  ruling states. The pending run is scoped to the item, so it also survives a
  chunk whose tail is the attribute line, and where the item genuinely ends it
  still attaches to nothing. Tight/loose is untouched, and the marker-abutting
  form `-{.x} item` is a separate mechanism and unchanged.

- **HTML import names the `<colgroup>` it drops.** Carve has no column model and
  whether it should get one is a language question (`markup-carve/carve#1092`),
  so a table's column description does not reach the output; the report says so
  once, as `element-dropped` at `warning` under the `<colgroup>`'s own path, in
  wording verbatim from carve-rs and carve-js. A `<colgroup>` that is not a
  table's child reports `element-unwrapped` instead, and its content does reach
  the output.

- **The Markdown importer keeps the constructs Carve spells like the source
  literal** (markup-carve/carve#1130's dialect ruling: CommonMark plus GFM is
  the contract). `a $`x+y` b`, `a !`x` b` and `a :term[x] b` are no Markdown
  construct in any flavour and are now escaped unconditionally. Four more are
  real syntax in some flavour, so each stays literal unless its new constructor
  flag opts in: `convertInlineFootnotes` (`a ^[note] b`, Pandoc),
  `convertAbbreviations` (`*[HTML]: HyperText`, PHP Markdown Extra),
  `convertFencedDivs` (`::: note`, Pandoc/Quarto) and `convertAttributes`
  (`[t]{.c}` spans and `{.cls}` lines, Pandoc/kramdown). All default off.

- **The HTML importer keeps a loose list loose.** A source list whose items hold
  an explicit `<p>` imports with a blank line between the items - Carve's
  spelling of looseness - while a bare-text item stays tight. Decided per list,
  as CommonMark does: one paragraph item loosens the whole list.

- **Two adjacent ordered lists import as two lists** (carve-php#1290). The
  delimiter now alternates `.`/`)` across adjacent ordered siblings, the same
  rule bullet lists already follow with `-`/`*`, so the two no longer merge into
  one loose list; an explicit `data-marker` still wins.

- **The HTML importer reads the engine's own mention and hashtag spans back as
  the bare sigil** (carve-php#1291), instead of adding one wrapper per HTML
  round trip. The shortcut requires the whole span text to be a single sigil
  token, so an authored span that merely carries the class stays a span.

- **The HTML importer keeps an authored heading id, and adjacent sections stay
  separate** (carve-php#1289, carve-php#1297). An id matching the tracker's slug
  of the heading text is generation and is left to regeneration; anything else
  is authored and kept, so `{#custom}` survives an HTML round trip. Two
  adjacent sections no longer glue their headings into one line (`## A## B`).

- **The HTML importer keeps a captioned code block's figure** (carve-php#1288).
  A `pre` is a supported figure target now, beside the image and the block
  quote, so `<figure><pre>...<figcaption>` no longer imports as a bare fence
  plus a plain paragraph with the `^` caption association gone.

- **The HTML importer reads the engine's own footnote reference back as a
  reference** (carve-php#1286). The label is derived from the `#fnN` fragment,
  the same derivation the definition side applies to the list item's id, so the
  engine's own footnote output round-trips; a round-trip-mode inline footnote is
  untouched, its data attributes keeping precedence.

- **A typed custom div keeps its quoted title under a class-carrying attribute
  line** (carve-php#1284). Only the OPENER class decides now, so `{.sidebar}`
  above `::: widget "Title"` no longer falls through to the untyped writer and
  loses the title and its `admonition-title` heading; the extra classes are
  written back on the attribute line with the opener excluded.

- **An integral citation group survives its own wire trip** (carve-php#1285).
  The wire carries `mode: "integral"`, absent when parenthetical, instead of the
  internal boolean `integral` that `$defs.citation_group` does not allow and
  that made `decode(encode(x))` throw for every `[+@...]` group.

- **A ProseMirror round trip keeps a mixed task list's plain sibling at column
  zero** (carve-php#1287). `task` is its own list type again, so a task list and
  a plain list beside it are no longer folded to the same type and separated
  with the indented-second-list spelling.

- **A table cell keeps the alignment marker it was written with, and the
  writers spell it the way carve-js and carve-rs do.** Four defects, all of
  them the same cell:
  - The parser folded an explicit `>` into the column's alignment where the two
    agreed, losing `align` on the body cell that carries the marker. The HTML
    was unaffected, but PART 9 section 319 binds the marker to the CELL.
  - The canonical Carve writer disagreed with the other two on a cell that
    carries a marker or an attribute block. All three now write the padded form
    PART 11 §6e states - see the entry above.
  - The Markdown writer promoted a body cell's alignment into the column rule
    (`| ---: |`). Markdown has no cell-level alignment, so only what the HEADER
    declares belongs in the separator row.
  - A list item or definition whose body was collected away wrote its marker
    with the separator space still attached, leaving `"- "` and `": "` at the
    end of a line.

- **A `#tag` stays a tag across the ProseMirror bridge.** Both directions now
  select the mention flavor by name, so a tag no longer reaches the editor as a
  `carveMention` and a `carveTag` written in a Tiptap editor no longer comes
  back spelled as a mention.

- **A `<summary>` imports as the disclosure's label.** It is written as the
  `::: details` opener's quoted title, markup included, with the attribute block
  carrying `open` onto the rendered element, instead of falling through as
  ordinary block content and coming back as `DetailsExtension`'s default
  `<summary>Details</summary>`. A summary the opener line cannot hold (one
  containing the `"` delimiter, or several blocks) keeps its text as block
  content, and the report names both that and a disclosure inside a table cell,
  which degrades to its text.

- **A table's header cells survive HTML import individually.** Header is now a
  property of the cell, written `|= R | 1 |` wherever it stands, and only a
  first row whose cells are all headers is promoted to the head - so a row-head
  column no longer turns both cells into headers, a `<th>` in a later row keeps
  its header, and a table whose third row held a `<th>` no longer comes back
  with its rows rearranged.

- **A table structure Carve source cannot spell says so.** Carve 0.1 source has
  no spelling for the explicit `rowGroups` partition the AST can hold, so a
  `<tfoot>`, a second `<tbody>`, a `<thead>` that does not match the leading
  run of header rows, a second `<caption>`, and a header cell below the head
  that also carries attributes each emit a `table-degraded` diagnostic naming
  what changed. The flattening itself is unchanged from 0.1.4 and deliberate,
  and an ordinary head/body table reports nothing.

- **`<ol type="a">` imports as a numbering style, not a raw attribute.** All
  four styles are now written in the marker itself - `a.`, `A.`, `i.`, `I.` -
  at any depth, so the tree carries the `olType` field rather than
  `attrs.type`, and `type="1"` is written as the plain decimal it means.
  Sequences with no marker spelling (an alphabetic run past `z`, or a one-item
  alphabetic list starting on a letter that reads as a Roman numeral) keep the
  attribute, which still renders the right `<ol>`.

- **A list nested under an alphabetic or Roman marker keeps its indentation.**
  The importer's cleanup pass recognized only `\d+.` as an ordered marker, so an
  item written `a.` or `iv.` fell through to the branch that strips leading
  whitespace and a list nested under it dedented out of its parent.

- **A `div`-grouped definition list survives HTML import.** HTML5's second `dl`
  content model - one `div` per `dt`/`dd` group - is now unwrapped
  transparently, where it used to convert to an empty document with no
  diagnostic; the wrapper's attributes are dropped the way `dt`/`dd` attributes
  already are.

- **An unresolved `</#id>` cross-reference stays readable in Markdown link
  text.** The marker was written `\</#nope>` inside a link and `</#nope>`
  everywhere else, because the flattening a cross-reference in link text takes
  missed the treatment `renderHeadingRef()` gives it. The writer's own `</#`
  and `>` are now literal wherever the marker stands, while the id between them
  still takes the HTML pass, so `</#a<script>` remains inert.

- **A footnote inside an unresolved reference no longer publishes a number**
  (#1269). PART 9R R2 rules that such a note is not a reference: the reference
  degrades to its literal source, so the note gets no number, no endnote and no
  backlink. The rendered HTML already agreed; the serialized AST did not. Both
  note spellings are covered - `[^label]` and `^[content]` - and a number
  arriving on the ingest path inside such a reference is cleared. A bracketed
  run that never had a reference tail and a reference that resolves are
  unchanged.

- **`carve fmt` writes a code fence with no space before its info string.**
  `fenced_code_block` names the no-space form canonical while leaving the reader
  lenient, so the authored ` ```php ` is no longer rewritten ` ``` php `. The
  separators INSIDE the info string are a different slot and are unchanged - a
  header or label still takes exactly one space.

- **HTML import writes the source the canonical writer writes.** An imported
  attribute value is now quoted only where the writer quotes it
  (`<abbr title="HyperText">` gives `[HTML]{abbr=HyperText}`), and a semantic
  element's leftover `id` and `class` now lead with the consumed name last
  (`[Tab]{#k .c kbd}`, not `[Tab]{kbd #k .c}`). Both brought carve-php in line
  with carve-js and carve-rs. An imported code block keeps the tight ` ```php `
  opener it always wrote, which is now what the writer writes too. Rendered
  HTML is unchanged.

- **An ingest refusal at a typed node union names the admitted types instead of
  a field from the first branch.** A payload putting the wrong KIND of node at
  `figure.target` was reported as an `image` missing its `src`; the message now
  names the offending type and the admitted set, which is what carve-js says.
  A node whose type IS admitted and is missing a required field still names
  that field, and no payload changes from accepted to refused or back.

- **`carve fmt` writes a bare caret where no inline note can open.** PART 9 §16
  rules out three positions - an empty or whitespace-only body, an unclosed run,
  and anywhere inside a note's own content - and the writer escaped the caret in
  all of them, so `x ^[]` was rewritten `x \^[]`. A caret in front of a run that
  does give a note a body keeps its escape.

- **An image's alt text closes where a link's text closes.** The alt was found
  by a second scan beside the link scan that skipped neither of the two runs
  whose content is literal, so an alt holding a code span or an editorial
  comment - `![t{# ] #}z](/i.png)`, and the same shape with backticks - was cut
  at a `]` the parse had already ruled was content.

- **`carve fmt` writes a raw bracketed run as authored.** A run the reader reads
  raw resolves no escape, so a backslash the writer added came back as content.
  Five writers carried the same escape - an image's alt text, an admonition
  label, a div label, a code-fence label, and a footnote id in its definition
  and in every reference to it - and `::: [a\b]` grew one backslash per pass,
  so `fmt(fmt(x)) == fmt(x)` failed from the second pass onward. An
  abbreviation keeps its escape.

- **The ProseMirror bridge declares the empty span it cannot carry** (#1259).
  `x ^[]{.c}` came back as `x ^` with neither `droppedTypes()` nor
  `degradedTypes()` reporting it; a span with no content is now declared
  instead of lost silently. Rendered HTML is unchanged.

- **The Markdown importer reads a block-level HTML element as a block inside a
  container** (#1247). CommonMark's HTML block start conditions apply inside a
  block quote or a list item exactly as they do at document level, and
  conditions 1 to 6 may interrupt an open paragraph; the importer knew none of
  them. A condition 1 to 5 block also ends at its own terminator now, so prose
  on the line below `<!-- x -->` is a block of its own, while an inline `<span>`
  on a continuation line is condition 7 and still stays inline.

- **An item's own content column is no longer read as indented code** (#1247).
  The four-column test now measures from the enclosing item's content column
  instead of from column 0, so a nested item's content is no longer fenced and
  emitted at column 0. Indentation is measured in columns throughout, so a tab
  advances to the next four-column stop: one tab under a two-column item is that
  item's content, two are code. Document-level indented code is unchanged.

- **Presentation targets no longer discard authored text** (PART 11 §10e,
  markup-carve/carve#1179), the floor `docs/graceful-degradation.md` states as
  a MUST. A table caption now follows the table as body text on the Markdown
  target, separated by one blank line so a GFM reader does not take it as
  another row; a fence's title (`"src/app.js"`) and grouping label (`[Node]`)
  now render as a standalone line each in plain text and a bold standalone line
  each on the terminal, above the block, title before label. An uncaptioned
  table, and a fence carrying neither token, are byte-identical to before.

- **An authored `abbr` wins on the Markdown and ANSI targets too**
  (markup-carve/carve#1176). markup-carve/carve#1127 ruled that an explicit
  `abbr` outranks automatic expansion, and the HTML renderer honoured it while
  Markdown and ANSI emitted the DEFINITION's text; both now carry the authored
  value, using the same suppression flag, as does the plain-text target.

- **A math span's base class keeps the class slot in place** (PART 10 §1,
  markup-carve/carve#1164). A mandatory base class is prepended INSIDE the
  class slot and the slot stays where the author first wrote a class, so
  `$`E=mc^2`{#i .c k=v}` gives `<span id="i" class="math inline c" k="v">`.
  With no authored class there is no slot to keep, so the base class leads,
  unchanged.

- **The `ext-NAME` class no longer moves the author's class slot.**
  `:widget[x]{#i .c k=v}` renders `<span id="i" class="ext-widget c" k="v">`;
  the structural class merges INTO the slot the author wrote and the slot keeps
  its position. Spec PART 10 §1, pinned by corpus `45-inline-extensions-12`
  (markup-carve/carve#1168).

- **A nested list is indented once on the Markdown target, not twice** (#1142).
  Each level was padded by the list's own depth and again by the enclosing
  item's marker width, so a third level became an indented verbatim block for
  every reader that is not Carve itself.

- **Plain HTML and BBCode text no longer becomes Carve markup.** A literal
  asterisk or underscore is not Carve markup (#1141), a hash in source text is
  not a Carve tag (#1201), a delimiter the source already escaped is not
  escaped twice (#1213), and a literal backslash survives the conversion
  (#1215, #1219).

- **`BbcodeToCarve` keeps code content literal and consumes `[noparse]`**
  instead of emitting it (#1210, #1211).

- **The Markdown importer stays on CommonMark plus GFM** (#1225), and keeps a
  hard break and an indented code block - also when the hard break sits inside
  a container (#1205, #1208).

- **An abbreviation definition is written where it was authored** (#1160), and
  a `+` line is not a list item, so the abbreviation definition below it is
  collected (#1159).

- **A fence ended by a container closer keeps its trailing blank line**
  (#1178), and a verbatim block's span covers that trailing blank line (#1184).

- **Footnote labels stay on one line** (#1188), and a second caption line does
  not replace an attached table caption (#1200).

- **Formatting keeps structure**: adjacent sibling lists stay separate through
  fmt (#1171), a single-line list item stays on its marker line (#1223), and a
  code span is padded on both sides while a multi-line list item keeps its list
  (#1229).

- **An escaped brace does not suppress the delimiter after it** (#1196).

- **Attribute writing**: a value-less attribute is written as a boolean and
  LANG is no longer folded (#1233), an attribute needs a separator before it
  (#1236), and the semantic registry holds no element Carve already spells
  (#1235).

- **An invisible child does not change a block quote's framing** (#1179).

## [0.1.4] - 2026-08-10

### Breaking

- **A literal footnote reference escapes BOTH brackets on the Markdown target**
  (markup-carve/carve#1040). A reference nothing defines degrades to literal
  text, and this renderer sent it through the ordinary text escaper - which
  applies the PART 11 §8a M1b narrowing, so `[` came back bare and only `]` kept
  its backslash. M1b governs a character that reached the writer inside a TEXT
  node, "one the Carve grammar did not read as an opener"; the grammar did read
  this one. The half-escaped `[^a\]:` is read as a footnote DEFINITION by a
  Markdown reader with footnotes enabled, so a document that degraded the
  construct published a footnote section it never had. `Text[^a].` now writes
  `Text\[^a\].`, and a RESOLVED reference still writes `[^a]` bare.

- **A ragged table keeps each row's own cell count on the Markdown target**
  (markup-carve/carve#1040). PART 11 §10b: the writer "MUST NOT append empty
  cells to make every row as wide as the widest row in the table", and a missing
  trailing cell is not an empty one. The Carve writer already followed it; this
  renderer padded from the expanded grid, so `| ~x~ |` over `| a | b |` came out
  `| ~~x~~ |  |` and the re-parsed table gained a `<td>`. An authored EMPTY
  trailing cell now survives instead of being popped off the header row. The
  delimiter row is unchanged and still spans the whole table, which §10b also
  rules on - all three engines emit it that way, so it is filed rather than
  changed here.

- **Three folds now end where the grammar says they end**
  (markup-carve/carve#1028). Each was an enumerated set with one member missing,
  and each changes what an existing document renders to.

  A COMMENT AND A BLOCK-ATTRIBUTE LINE END A BLOCK QUOTE'S LAZY CONTINUATION.
  PART 2's LAZY CONTINUATION clause lists what a line may not be to continue the
  quote - "a heading, table, fenced code, `:::` div, thematic break, OR an
  'invisible' reference / footnote / abbreviation definition OR COMMENT -- each
  ends the blockquote and starts that block OUTSIDE it" - and PART 9 §10 I5 adds
  the block-attribute line. Only the abbreviation was tested for, so

  ```
  > quote
  %% c
  more
  ```

  put `more` INSIDE the quote as a second paragraph. It is now a sibling
  paragraph of the document, and a `{…}` line in the same position attributes it
  instead of being swallowed.

  A BLOCK-ATTRIBUTE LINE ENDS A LIST ITEM. §10 I5 again, with I6 applying the
  relation to every open paragraph. `- item` / `{.cls}` / `> quote` kept the line
  inside the item, below its content column, where it rendered as the literal
  text `{.cls}` while the quote carried no class. PART 2's LIST-ITEM ATTRIBUTES
  clause names that reading and rejects it. An INDENTED attribute line is
  unaffected.

  A CAPTION LINE DOES NOT END A DEFINITION TERM. The reverse direction: `::
  term` / `^ cap` ended the term and started a paragraph. PART 9 §4 gives a
  caption five hosts and a definition term is none of them, so PART 2's
  `caption_slot` note makes the line "ordinary inline/paragraph content" - which
  `term_continuation_line` folds. This engine already folded a caption line into
  an open paragraph; only the term disagreed. A caption after a block quote still
  attaches, because a quote IS one of the five hosts.

- **The AST now publishes `thematic_break.marker` and the Carve writer
  reproduces it** (markup-carve/carve#976). Parsed `***` and `___` carry `*`
  and `_` respectively; the default `---` leaves the optional field absent.
  AST ingest accepts the field and defaults an absent one to `---`.

- **BREAKING: `beforeRender` takes a read-only context**
  (markup-carve/carve#1007). `BeforeRenderExtensionInterface::beforeRender()` is
  now `beforeRender(Document $document, BeforeRenderContext $context): Document`.
  Every implementer of that interface has to accept the second parameter - PHP
  requires an implementation to match the declared signature, so this breaks even
  a hook that ignores it.

  THE REFERENCE FRAME, stated because it is not carve-js's. There the same ruling
  breaks only a hook written against the `beforeRender(doc, opts)` shape that
  landed hours earlier, since a JavaScript function of fewer parameters is
  assignable and a hook written for the released version still compiles. PHP
  grants no such allowance and this engine never had the intermediate shape, so
  the break here is against the released line: a hook written as
  `beforeRender(Document $document): Document` is a fatal signature
  incompatibility until it takes the context.

  The hook runs BEFORE the render starts, so it had nothing to inherit from: a
  hook that produces output of its own produced it with DEFAULTS, and an entry
  cloned from a heading disagreed with that heading as soon as a render option
  reached inline rendering - the same nodes, two answers. The new
  `MarkupCarve\Carve\Extension\BeforeRenderContext` carries what the spec's
  extension contract requires (docs/extensions.md §2.2): the render options
  (`symbols()`, `smartTypography()`, `safeMode()`, `staticRenderer()`), the
  effective `mode()` with `isStatic()`, and `targetIsHtml()`.

  `targetIsHtml()` is the accessor a bare options parameter had no answer for: an
  extension that emits HTML in the hook reads it to skip its transform on a
  non-HTML target and leave the source node for that renderer to emit as source.
  `mode()` is the EFFECTIVE mode, which is `interactive` on every non-HTML target
  whatever the caller configured, because static rendering is an HTML-only
  concern.

  The context is READ-ONLY as a matter of contract, not convention: the guards
  run after the hooks, so a hook handed live options could clear the field a
  guard measures. It therefore hands out VALUES rather than the renderer that
  holds them, and PHP's copy-on-assign arrays make the maps genuinely the hook's
  own.

  `HtmlRenderer::getSymbols()` and `HtmlRenderer::getStaticRenderers()` are new,
  and `getSmartTypography()` now exists on the Markdown, plain-text and ANSI
  renderers as well as the HTML one, so the context answers for a non-HTML target
  with what the caller configured rather than a default.

- **The Markdown target's escaping narrows on the line** (markup-carve/carve#970,
  PART 11 §8a). `_`, `#` and `[` are escaped IF AND ONLY IF the character is
  adjacent on the emitted line to an unescaped delimiter of the same character.
  So `company_id`, `C#` and `issue #123` are written as the author typed them,
  where they used to come out `company\_id`, `C\#` and `issue \#123` - a
  backslash inside an identifier breaks exact-match search in the published
  document and protects nothing. `a __b` keeps both escapes, because dropping
  them would merge the two into one run.

  **The asterisk is exempt and keeps M1 unconditionally.** This writer spells
  emphasis with `*`, so a literal asterisk can merge with a delimiter the writer
  itself wrote: emphasis containing two asterisks is `*\*\**`, and unescaped it
  is `****`, which a CommonMark reader publishes as a thematic break.

  **An escape the author wrote is unaffected** and is still emitted as an escape
  (§8 M2), so `a\_b` stays `a\_b`. It used to lose its backslash to the old
  intraword rule, which made `a\_b` and `a_b` one document on this target; they
  are two now.

- **Whitespace is a space, a tab, a CR or an LF in fifteen further constructs**
  (markup-carve/carve#963, markup-carve/carve#977, PART 7). A VERTICAL TAB
  (U+000B) and a FORM FEED (U+000C) are CONTENT everywhere, so they no longer
  pad a line or separate a marker from what follows it. A fence closer, a div
  and verse fence opener, a raw-block fence, a frontmatter opener, a table row's
  attribute block, a table continuation row and a multiline attribute block all
  end at a real whitespace run rather than at one of these characters; `{k=v<VT>w}`
  is ONE attribute whose value holds the character; `{#a<VT>.b}` is not an
  attribute block at all; `/<VT>a/` is literal text rather than emphasis, and
  the same for `*` and `/*`; `x^[<VT>]` is an inline footnote holding the
  character; a cross-reference id keeps one; and a straight quote after one
  CLOSES, because the character before it is content.

  The frontmatter opener is the severe one: it runs to the next bare three-dash
  line, so reading the character as padding did not mislabel one line, it
  swallowed the document down to the closer.

  Two slots stay deliberately wider and are unchanged: a link destination ends
  at UNICODE whitespace (PART 3), and a quote after a NO-BREAK SPACE still
  opens.

- **A nested link and an autolink stay nodes in the published AST**
  (markup-carve/carve#817). "Links never nest" is a rendering rule, so it binds
  the renderer and not the encoder: a `link` or an `autolink` inside a link's
  label now reaches the tree as the node the author wrote, where it used to be
  flattened into its display text. `[[x](y)](z)` publishes the inner
  `link` to `y` again, and `[pre <http://h> post](/u)` publishes the
  `autolink`. A consumer walking a link's `children` must expect a `link` or an
  `autolink` there and must not emit a nested anchor for it, exactly as it
  already does for a nested `heading_ref`. Rendered HTML, Markdown, plain text
  and ANSI are unchanged - every target unwraps at its own render seam - but
  `fmt` through the AST now returns the inner destination instead of dropping
  it, which is PART 11 §6's round trip.

- **A heading ends at the newline** (markup-carve/carve#451,
  markup-carve/carve#434). Nothing folds into a heading, so `# Title` with prose
  beneath is a heading plus a paragraph, and its id comes from the heading line
  alone (`Title`, not `Title-Some-text`). Anything with a blank line after the
  heading is unaffected. The `heading-lazy-continuation` lint rule, which
  reported the fold, is removed with it.

- **Five stored AST payload shapes no longer decode, and `StoredPayloadUpgrade`
  is the migration** (carve-php#1002). `AstCodec::decode()` used to normalize
  five spellings that predate PART 12 §7: a root `abbreviations` map with its
  `abbreviationsBeforeBody` flag, a root `frontmatter` object, a root
  `footnoteDefs` map, a `footnote` node keyed `id` rather than `label`, and this
  engine's internal `raw_text` node. All five are refused with a typed
  `AstDecodeException` naming the spelling found. `caption` and `section` are no
  longer ENCODED either - a `section` is published as the `div` it wraps blocks
  as, a `caption` as the `paragraph` it holds inline content as - and both leave
  `AstCodec::schema()`.

  **Migration, shipping in this same release:**
  `MarkupCarve\Carve\Ast\StoredPayloadUpgrade::upgrade()` converts a stored
  payload in any of these shapes into the §7 shape, and `::upgradeJson()` does
  the same JSON in, JSON out. Both work on the payload alone, so an application
  no longer holding the original Carve can still migrate. The conversion is
  idempotent and leaves a §7-shaped payload untouched, so it is safe to run over
  a whole store. One caveat: `raw_text` becomes the `text` node the encoder
  already published it as, and a `text` node is escaped when written back out.
  See `docs/ast-json.md`.

- **An ingest validates the whole payload against the AST schema**
  (carve-php#979, PART 12 §12(d)). `resources/ast-schema.json` is vendored and
  consulted at decode - types and required fields together. Trees this codec used
  to accept are refused with `AstDecodeException`: a root `srcByteLength` that is
  a string or negative, a `children` of `null` (read as an empty document before),
  a `text.value` of `7` (rendered `<p>7</p>` before), a `pos` missing or gaining a
  field, and `attrs` written as `{"class": "x"}`. Two shapes that used to escape
  as a bare PHP `TypeError` are refused as the same typed error. Every AST
  refusal now throws `AstDecodeException` (extending `RuntimeException`, so
  existing catches keep working) rather than a bare `RuntimeException`. A node
  type registered with `AstCodec::register()` is exempt by construction.
  Producers should validate against the schema before sending.

- **A renderer refuses at its ceiling instead of truncating** (PART 9 §25,
  markup-carve/carve#548). Reaching the recursion ceiling throws
  `RenderDepthExceededException`, naming the bound and the renderer, where every
  renderer used to return what it had produced - the nested markers with the body
  dropped. No document `parse()` produces can reach a ceiling; what refuses is a
  tree built through the API or decoded from JSON. The CLI reports on stderr and
  exits 1 rather than writing a partial document.

- **An explicit `[text][label]` no longer reaches the heading index**
  (carve-php#1029, markup-carve/carve#742). `[q][Getting Started]` under a
  `# Getting Started` renders as literal source text instead of linking. The
  collapsed `[text][]` form still reaches the index, and an explicit label naming
  a real definition still resolves.

- **A reference definition whose trailing `{...}` block is not `attributes` is a
  paragraph** (carve-php#1025, markup-carve/carve#933). `[a]: /u {#}`,
  `[a]: /u { }`, `[a]: /u {=}`, `[a]: /u {}` and `[a]: /u {.a\}b}` no longer
  define, so an `[a][]` under one renders as literal source and the braces stay
  on the page. A VALID block still defines and still transfers its attributes.

- **An attribute line above a reference definition floats past it** (PART 9 §15
  A2a, markup-carve/carve#529). Pending attributes attach to the next VISIBLE
  block, and a definition renders nothing - so `{#i}` / `[f]: u` / blank / `e`
  is `<p id="i">e</p>`. This engine used to hand the attributes to the
  DEFINITION, and every link resolving that label carried them. **The
  replacement ships in this same release: put the block on the definition's own
  line, `[ex]: /u {.external}`.**

- **Frontmatter must begin at the document's first line.** Nothing may precede
  the opener - not a leading blank line, and not a block-attribute line. A
  document relying on `{.meta}` above a `---` fence loses those attributes and
  its fence becomes visible content; `\n---\n\n---\n` used to be read as an empty
  frontmatter fence and rendered the whole document to an empty string. carve-js
  and carve-rs already read both this way.

- **A definition-body line indented one or two columns ends the body instead of
  folding in** (carve-php#1035, markup-carve/carve#932). `definition_indent` puts
  the body's content column at 3, and BELOW that column the body ENDS and the
  line is classified in the surviving context - so `:: t` / `:  body` / ` > q`
  closes the `dl` and renders `<p>&gt; q</p>`. Column 0 is unchanged, column 3
  still opens a block inside the `dd`, and column 4 and beyond is still lazy
  text.

- **An inline attribute block's interior is space-only, and a quoted attribute
  value stops at the newline** (carve-php#985, carve-php#986,
  markup-carve/carve#906). A tab after `{`, between two attributes, before `}`,
  after an unquoted value, or in the blessed empty block `{ }` leaves the block
  unrecognized and its braces showing. A line break inside a quoted value ends
  the production, so `{k="a` / `b"}` is a paragraph where this engine used to
  accept it and collapse the newline to a space. The block-attribute LINE keeps
  its whitespace slots, and a tab inside a quoted value is still content.

- **AST vocabulary: two node shapes change.** Rendered output does not move on
  any target.

  - An editorial comment is a `critic_comment` node instead of a `span` carrying
    a `critic-comment` class; the encoded field is `text`. The rendered
    `<span class="critic-comment">` class is deliberately unchanged. One behavior
    does move: an editorial comment no longer contributes to a generated heading
    id, so `# Title {#note#} tail` gives `Title-tail`, matching the reference.
    The ProseMirror bridge gains a `carveCriticComment` mark.
  - A footnote reference encodes its label as `id`, and an inline footnote's body
    as `inline` - the names the reference uses. The rename table had keyed
    `label` -> `id` on the BLOCK type, so it never reached the inline node.

### Added

- **A reference definition carries trailing attributes** (PART 9 §16, PART 9R R1,
  markup-carve/carve#612). `[ex]: https://example.com {.external}` attributes the
  DEFINITION, and every link resolving `ex` gets them. The link's own attributes
  override per key under the §15 A3 merge, so `[ex]: /u {.external #a}` with
  `[E][ex]{.internal #b}` renders `class="external internal" id="b"`. The block
  must be preceded by whitespace and end the line, so `[a]: /u{.x}` keeps the
  braces in the destination.

- **Source positions on AST nodes (PART 12 §4), opt-in.**
  `new BlockParser(trackPositions: true)` records a `SourceSpan` on each node,
  read with `Node::getPos()`, emitted by the codec as `pos`. All six fields are
  present or the span is `null`. Columns and offsets count Unicode codepoints.
  `carve --json` now asks for positions, so its output is §4 conformant.

- **`MarkdownRenderer::setAttributeFallback()`** keeps attributes Markdown cannot
  spell, as raw HTML, instead of dropping them. `AttributeFallback::Html` renders
  an attributed container as a `<div ...>` wrapper and an attributed image as an
  `<img ...>` tag; `AttributeFallback::Drop` remains the default and its output is
  byte-identical to before. The raw HTML is built by the HTML renderer's own
  attribute code, so names go through the same validation and values through the
  same URL denylist and attribute-context escaping.

- **`MarkdownRenderer::setSmartTypography()`** renders smart typography as the
  author's source run instead of the resolved glyph.
  `SmartTypographyMode::Glyph` stays the default. Markdown only. Escaping is
  untouched.

- **`HtmlRenderer::getSmartTypography()`** (carve-php#1033), so an extension
  deriving its own display text at render time can honor the mode the renderer
  was configured with.

- **`setSectionWrapping(false)`** renders headings without the `<section>`
  wrapper (markup-carve/carve#427, PART 9 §13). The id goes back on the `<h*>`
  alongside its other attributes. On by default. The endnotes
  `<section role="doc-endnotes">` is unaffected.

- **`ProseMirrorToCarve::register()`**, so an application's own editor nodes
  convert instead of throwing. The factory returns the node shell, so attributes
  and children come from the normal path. Registration is per converter instance.

  ~~~ php
  $converter->register('placeholderToken', function (array $data): Node {
      $span = new Span();
      $span->addClass('placeholder');

      return $span;
  });
  ~~~

- **`MarkdownHabitLinter` and a `carve lint` command** report Markdown habits
  that parse as valid Carve but render as something else - `**bold**`,
  `__bold__`, `~~struck~~`. Only forms that are never meaningful Carve are
  reported; `*x*` and `_x_` are deliberately not flagged. `carve lint [files...]`
  prints `file:line:column rule message` and exits non-zero.

  Two platform autolink rules ship with it, off by default (carve-php#1005):
  `MarkdownHabitLinter::lint()` takes `['platforms' => ['github']]` and
  `carve lint` takes a repeatable `--platform`. With a host named, the at-word
  and hash-number tokens that host re-linkifies out of published output are
  reported as `platform-mention-token` and `platform-issue-reference`. Neither
  fires unless a host is named; an unknown name is refused on the command line.
  See `docs/lint.md`.

- **`DetailsExtension` accepts a `defaultSummary` constructor argument** for the
  fallback `<summary>` label of a title-less `::: details` block. The default is
  unchanged; a quoted opener title still wins; the custom label is HTML-escaped.

### Changed

- **Smart typography is represented as AST nodes instead of character
  substitution** (markup-carve/carve#339). A `SmartPunctuation` inline node
  carries the resolved kind and the author's source run, so the Carve renderer
  reproduces what was written (`...`, `->`, `--`, `"`) instead of normalizing it.
  `fmt` is no longer lossy on smart typography. Every other target resolves the
  node back to the same glyph, verified byte-identical against the pinned spec
  corpus. Quote glyphs stay locale-aware. Covers all fifteen transforms.

- **`MarkdownToCarve` math conversion is opt-in.** Plain CommonMark treats dollar
  runs as literal text, so the converter no longer rewrites paired dollars by
  default. Pass `convertMath: true`.

- **A profile classifies a div as an admonition by its Tier-1 class, not by its
  opener word.** `::: sidebar` classifies as `div`; `::: note` still classifies
  as `admonition`. No rendered output changes.

  **Migration:** `denyBlock(['admonition'])` used to strip *every* named div and
  now strips only the eight Tier-1 callouts. To preserve the old behavior, deny
  both `admonition` and `div`.

- **A profile that names a type now acts on it.** `autolink` and `admonition`
  were silent no-ops - a host could deny autolinks, get no error and no
  violation, and still emit them. A profile that denies NOTHING now changes
  nothing: `ProfileFilter` used to prune containers that were already empty in
  the source, so six documents rendered differently under `Profile::full()`. And
  `Profile::full()` no longer drops a substitution - `{~old~>new~}` rendered as
  nothing, losing both texts, because `substitution` was never registered.

### Fixed

- **The library reports its own version again** (markup-carve/carve-php#1129).
  `CarveConverter::LIB_VERSION` stayed at `0.1.0` across the 0.1.1, 0.1.2 and
  0.1.3 releases, so `carve --version` and the `carve fmt --stamp` provenance
  marker both answered three releases behind the code that produced them. Any
  document stamped since July carries a `generated-by: carve-php 0.1.0` line
  that does not identify the writer that wrote it.

- **A short ANSI table row is padded out to the box** (markup-carve/carve#1044).
  The ANSI box draws its rules at the TABLE width, so a ragged table left the
  short row stopping mid-box with no right border:

  ```
  | h |
  |---|
  | |x |
  ```

  used to render

  ```
  ┌───┬───┐
  │ h │
  ├───┼───┤
  │   │ x │
  └───┴───┘
  ```

  and now renders

  ```
  ┌───┬───┐
  │ h │   │
  ├───┼───┤
  │   │ x │
  └───┴───┘
  ```

  The trailing cells a row does not have are a DISPLAY pad: nothing re-parses
  ANSI output, and a box has to be a rectangle to read as one. It is also what
  the HTML target already shows, since the table is two columns wide there. PART
  11 §10b forbids this same padding on the Markdown delimiter row because a
  reader parses that row; that reason is absent here, which is why the two
  targets settle it differently. AST row cell counts are unchanged, and the
  Markdown, plain and Carve targets still write each row's own cells.

- **A tab after a line-initial caret is not a caption slot, so the writer leaves
  it bare** (markup-carve/carve#1042 follow-up). A caption marker is a caret
  followed by a SPACE; a tab after it leaves the line as prose, which corpus
  `231-a-tab-after-a-heading-quote-or-caption-marker-leaves-the-line-as-prose-2`
  pins. The caret re-parses as text either way, so PART 11 §2 does not want the
  escape, and `carve fmt` on an image line followed by `^<TAB>Figure 1` wrote a
  backslash before the caret where carve-js writes it bare. Nothing on the page
  changed; the divergence was in the canonical source, which PART 11 §2a requires
  the three engines to agree on byte for byte.

- **The Markdown delimiter row is sized from the header row, not the table**
  (markup-carve/carve#1042). PART 11 §10b says the delimiter "carries exactly one
  cell for each cell in the HEADER ROW, not one for each column reached by a
  wider body row", and the Markdown target sized it from the table width instead.
  A ragged table therefore emitted a delimiter wider than the row it promotes:

  ```
  | h |
  |---|
  | |x |
  ```

  used to write

  ```
  | h |
  | --- | --- |
  |  | x |
  ```

  which neither python-markdown nor marked reads as a table - the whole document
  published as a paragraph of pipes. It now writes

  ```
  | h |
  | --- |
  |  | x |
  ```

  and both readers render a table again. A header that is itself the widest row
  is unchanged, and the header's column alignment still reaches the delimiter.

- **The Carve writer stops escaping a caret that opens no caption slot**
  (carve-php#1113). A caption marker is only readable at the start of the FIRST
  line of a paragraph that directly follows a block able to host a caption, so
  that is the only place the writer has to defend. It escaped a caret after any
  newline instead, so a soft break inside such a paragraph carried the slot
  along with it:

  ```
  | a | b |

  para
  ^ cap
  ```

  was written back with `\^ cap` on the second line, where carve-js and carve-rs
  write the caret bare. Both readings render the same HTML, so nothing on the
  page changed - the divergence was in the canonical source the writer produces,
  which PART 11 §2a requires the three engines to agree on byte for byte.

- **A collapsed reference resolving through a heading publishes its label's
  whitespace** (markup-carve/carve#1023, PART 12 §3a). `ref` carries the derived
  text - the label with its markup stripped, per the markup-carve/carve#962 ruling - and this
  engine was publishing the LOOKUP key instead, which PART 9R R1 additionally
  trims and collapses. So `[My  Heading][]` went out as `My  Heading` from
  carve-js and carve-rs and as `My Heading` here, and a label padded inside its
  brackets lost the padding.

  The lookup was never the published value's business: R1 also folds case, and
  no engine publishes a case-folded `ref`, so the collapsed-but-not-folded
  string named nothing in the resolution. Matching still trims and collapses, so
  nothing that resolved before stops resolving. A reference resolving through an
  authored `[label]: url` definition was already exact and is unchanged.

- **A footnote definition with an EMPTY body now carries a position**
  (markup-carve/carve#1023, PART 12 §4). A definition's extent was derived from
  its body, and `[^f]: {empty}` parses to no blocks - so nothing was there to
  measure and the node went out with no `pos` at all. §4 permits omitting a
  position only for a node that cannot be placed; this one is written on a line
  of its own, so its extent is that line, which is what the reference publishes.
  A definition that HAS content keeps the extent its body gives it.

  The same gap moved a definition, not just its span: §7 orders collected
  definitions by source position, and one with no position sorted last - so
  `[^a]: {empty}` written above `[^b]: x` was published below it.

- Keep adjacent mergeable block openers separate when formatting a tight
  `+`-attached run, instead of collapsing two quotes or tables into one block.

- Preserve each row's cell count when formatting a ragged table instead of
  manufacturing empty cells to make the table rectangular.

- Keep authored symbols in derived display text such as automatic tab labels,
  while continuing to exclude them from heading IDs and other identity text.

- **A collapsed `[text][]` whose label holds emphasis, an escape, a nested link
  or a smart apostrophe now reaches the heading** (markup-carve/carve#1011,
  PART 9R R1). The label was reduced to the heading index's key by a character
  class over its source, so it answered only for the delimiters that class
  listed: `# an /em/ heading` was unreachable by `[an /em/ heading][]` (Carve's
  emphasis delimiter is a slash, which no such class can carry), `# a\_b
  heading` met at neither spelling, `# a [x](/y) b` left the destination behind,
  and `# it's a heading` holds the curly glyph where the label holds the typed
  apostrophe. Each of those rendered the bracketed run as literal source. R1
  says the label enters as its RENDERED PLAIN TEXT, "the same string kind the
  heading side already enters as", so the reduction is now that same extraction
  over the parsed label and needs no list of delimiters. An authored
  `[label]: url` definition is still matched by the label AS WRITTEN, and still
  wins the tie against a same-named heading.

- **A `:name:` symbol no longer feeds a heading id** (markup-carve/carve#1011,
  syntax.md section 4.1 step 1). `# a :smile: b` published `<section
  id="a-smile-b">`, keying the id on the shortcode NAME - a spelling the
  document never renders, and with `smile` mapped to an emoji it named neither
  the source nor the output. The slug rule excludes symbols by construct, which
  it has to: a symbol's rendering is processor configuration and an id is
  assigned in the parse pass no renderer option reaches. The id is now `a-b`
  with or without a symbols map, matching carve-js and carve-rs. The symbol is
  still visible in a derived display label such as a `</#id>` cross-reference;
  only the id excludes it. Consequently a heading whose ENTIRE text is a symbol
  now has no rendered text to be indexed by, so `[:smile:][]` against
  `# :smile:` stays literal source.

- **A malformed UTF-8 byte no longer empties the text around it**
  (carve-php#1082, PART 1). One ill-formed byte in a paragraph rendered `<p></p>`
  on the HTML target and a bare newline on the Markdown, plain and ANSI targets,
  discarding every valid character in that paragraph while returning exit 0 and
  writing nothing to stderr - the one failure a caller cannot detect. The source
  is now decoded the way carve-js decodes it, substituting U+FFFD for each
  maximal ill-formed subsequence, so `hello <bad byte> world` keeps both words on
  every target. Neighbouring paragraphs were never affected and still are not.

- **Four more reserved characters no longer corrupt a document that contains
  them** (carve-php#1087). Each renderer that marks a position inside a string it
  is still building now chooses that marker per render from code points the
  document does not contain, the shape carve#678 settled, instead of reserving a
  fixed one:

  - The canonical writer (`fmt`, `toCarve()`) ate an authored U+E010 opening a
    list item's continuation line **and wrote the paragraph back at column 0**,
    outside the item - a change to block structure, not to one character.
  - The Markdown target deleted an authored U+E004, U+E005 or U+E006 outright.
    PART 7 makes those content and PART 9 section 29 has this target emit content
    rather than delete it, which is the same clause that settled the C0 controls.
  - The HTML target turned a host-built node containing its internal
    `::: footnotes` marker into a footnotes `div` in the middle of a paragraph.
    Not reachable from `.crv` source, only through the node API.
  - `BbcodeToCarve` substituted an unrelated span of the same post for an
    authored `NUL B <n> NUL`, and **raised an uncaught `TypeError` for an index
    past the end of its stash** - a crash from ordinary untrusted forum input.

  None of these is a security issue and none is likely in ordinary prose. They
  matter because "no document contains this" is an assumption about source, and
  the node API lets a caller supply any string.

  The chooser itself was widened in the same pass: it advanced a whole run at a
  time, so a document holding one character from each aligned run - about a
  thousand of them, not the whole private-use area its comment claimed - ran the
  search out and got the colliding preferred run back.

- **`fmt` writes a footnote definition with no blocks as `[^f]: {empty}`**
  (carve-php#1069, PART 11 §7b). The body empties whenever the definition
  line's whole body is a block-attribute run, which the line collects as
  attributes and discards. The writer emitted `[^f]:` with nothing after the
  colon, and that line is not a definition at all, so formatting the document
  lost BOTH halves: the definition came back as a paragraph and the reference
  to it came back as literal text. The sentinel is a valid attribute block,
  collected and discarded on the same line, so the note renders empty and the
  reference still resolves. `{ }` and `{}` would not do - a block-attribute
  line needs at least one attribute, so both stay literal text inside the note
  - and the spelling is pinned by the spec rather than chosen here, so all
  three engines write the same bytes.

- **`fmt` emits a blank inside an indented verbatim block as an empty line**
  (carve-php#1068, PART 11 section 7). A blank line inside a fenced code block,
  a raw block or a block comment nested in a FOOTNOTE BODY or a DEFINITION BODY
  came back as a line holding the container's indent and nothing else. A
  whitespace-only line is not stable - editors that strip trailing whitespace on
  save, `git apply --whitespace=fix` and CI whitespace checks all rewrite it -
  so the formatter produced output that ordinary tooling changes behind it. The
  equivalent list spelling was already correct; the three writers that indent a
  block body share the rule now. The blank line itself is unchanged: it is
  content, and it is still there.

- **A tabs label derived from a heading keeps a code span's text**
  (carve-php#1075). `TabsExtension` derived a tab's label with a walk of its
  own, handling text and smart punctuation and recursing into everything else -
  and a code span, a math span or an inline literal has no children, so its
  content never contributed. A tab headed `` ### `code()` and *bold* ``
  produced the label ` and bold`: the code span's text was gone and its leading
  space stranded, and since the heading is consumed by the label, nothing else
  in the output carried the text. Math, an inline literal, escaped text and an
  `:index[]` marker were wrong the same way, five rows in all, measured against
  carve-js. The label now reads the same leaf rules the heading id does.

  A numbered heading's label loses the number with it: those rules contribute
  nothing for a section-number span. carve-js keeps the number, and which is
  right is open.

- **A caption detaches across two blank lines** (carve-php#1078, PART 9 section
  4). `caption_slot = [blank_line], caption` carries ONE optional blank line and
  section 4 says the same thing in words: a `^ ` caption attaches to the
  immediately preceding captionable block when it is adjacent or one blank line
  below, and two blank lines DETACH, leaving the line an ordinary paragraph.
  This parser attached across any number of blank lines, on all five captionable
  hosts - a table, a fenced code block, a blockquote, an image paragraph and a
  standalone display-math block - so a caption written two blank lines below a
  table, or a paragraph that merely began with `^ `, was pulled into a block it
  was not part of. carve-js already detaches and is the oracle.

  A document written with zero or one blank line is unaffected, which is every
  document in the corpus.

- **The HTML target keeps an author's U+0001** (carve-php#1077, PART 9 section
  29 T1). The renderer marked inline line boundaries with the fixed control
  bytes U+0000 and U+0001, on the claim that a control byte never reaches
  escaped HTML output. It does: section 29 T1 says this target does not strip a
  non-whitespace C0 control, so an author's U+0001 collided with the soft-break
  guard and came back out as whatever the soft-break mode replaces - a newline
  by default, a `<br>` in break mode. The reader saw a line break the author
  never wrote, in all 29 constructs measured, while carve-js and carve-rs both
  emitted the character unchanged. The character was SUBSTITUTED rather than
  dropped, which is why nothing noticed.

  The guards are now chosen per render from private-use code points the
  document does not contain, the shape the canonical writer already used
  (markup-carve/carve#678), so they cannot collide by construction. U+0000 is
  covered by the same change: the parser rewrites an input NUL, so no PARSED
  document could reach that guard, but a tree built through the node API skips
  that rewrite and did.

  The remaining 27 non-whitespace C0 controls were swept across the same 29
  constructs and were already correct, before and after.

- **A header marker is not glued to a character that reads as alignment**
  (carve-php#1069, PART 11 §1). The parser's alignment scan runs at the
  character right after `|` or `|=` and consumes exactly one of `< > ~`, and a
  prefixed cell is written tight - so a header cell whose content opened with
  one of those lost it. `| ~x~ |` was written `|=~x~|`, which re-reads as
  centered with the text `x~`, dropping the strikethrough and centering every
  cell in the column by a marker the author never wrote; `| <https://e.com> |`
  lost its anchor the same way through the left marker. One space between the
  marker and the content is the fix, and the content is trimmed once the prefix
  is consumed, so `|= ~x~|` is a header cell holding `~x~` again. Body cells,
  cells carrying an attribute block and row attributes were measured and are
  unaffected.

- **A continuation marker attaches every block in its run** (carve-php#1069,
  PART 9 §17, PART 11 §1). The writer converted a `+` attachment into
  indentation for everything except a paragraph after a paragraph, on the stated
  ground that no other construct can fold into an open paragraph. Measured
  across twenty-two constructs that is wrong for two: a standalone image and a
  figure are written as a bare inline run on their own line, so at the item's
  content column they are lazy continuation exactly as a paragraph is. `- x` /
  `+` / `![a](i.png)` / `^ cap` came back as one paragraph holding an inline
  image and the literal text `^ cap`, with the `<figure>` and its
  `<figcaption>` gone. Both now keep the marker.

  Separately, once one child of an item is written at the marker column - which
  is column 0 - every later child must be too, or it is indented relative to the
  block above it and absorbed as that block's lazy continuation. `- x` / `+` /
  `---yaml` / `k: v` / `---` indented only the final line, and the thematic
  break folded into the paragraph above it as an em dash where the input
  rendered a rule.

- **`fmt` no longer manufactures a frontmatter block that swallows the document**
  (carve-php#1069, PART 11 §1). A frontmatter block is a `---` fence at byte 0
  plus a bare `---` closer anywhere below it, and two writer decisions put one
  there. PART 11 §6a normalizes `***` and `___` to `---`, so a break that opened
  the document gained a closer from any later break: `***` / blank / `a` / blank
  / `---` / blank / `b` came back as `<p>b</p>` alone, and the minimal pair
  rendered nothing at all. Separately, a hoisted link or footnote definition is
  written after the body, so whatever stood second was promoted to byte 0 - and
  if that block was a `---`, or a paragraph whose first line was `---yaml`-shaped,
  the whole document became frontmatter content and rendered empty.

  **Behavior change:** when the emitted bytes would be read as opening
  frontmatter the document does not have, `fmt` writes every thematic break as
  `***` instead of `---`. That is a deviation from §6a, taken because §1's
  `to_html(fmt(x)) == to_html(x)` is the stronger clause; a document that is not
  misread keeps the canonical `---`, including a leading break with no later
  break.

- **The Markdown and plain targets emit the non-whitespace C0 controls**
  (PART 9 §29 C0 CONTROLS ON THE RENDER TARGETS, markup-carve/carve#979;
  carve-php#1060). After markup-carve/carve#963 the whitespace of the language
  is exactly U+0020, U+0009, U+000A and U+000D, and every other C0 control -
  U+0000..U+0008, U+000B, U+000C, U+000E..U+001F - is ordinary content. These
  two targets deleted all 28 of them; they now write them out. A vertical tab
  or a form feed the author typed comes back on both, in every construct
  measured: paragraph, heading, code span, fenced code, emphasis, link text and
  destination, image alt, blockquote, list item, table cell, footnote body,
  definition term and body, caption, math and line block. The reason first
  offered for the strip - that a Markdown reader reclassifies these as
  whitespace - was measured against the CommonMark reference implementation and
  markdown-it in three modes and did not hold.

  **The terminal target is unchanged and still strips every control character**
  - the non-whitespace C0 controls, DEL (U+007F) and the C1 controls alike. It
  is the one consumer that ACTS on the character.

  **U+000D is not in the class.** Carriage return is whitespace, so both targets
  go on normalizing it, and DEL and the C1 controls stay refused on all three
  non-HTML targets.

- **A vertical tab at the edge of a block is no longer trimmed away** on the
  Markdown and plain targets. PHP's default `trim()` charlist is
  `" \t\n\r\0\x0B"`, which is not this language's whitespace, so a vertical
  tab that landed at the start or end of a paragraph, blockquote, list item,
  table cell, footnote body, definition body or caption was deleted even where
  the strip had let it through. PCRE's `\s` has the same problem from the other
  side and reached the Markdown heading folder, which collapses a soft wrap.

- **Every derived display text clones the heading's nodes** (PART 9R R4, DERIVED
  DISPLAY TEXT CLONES THE SAME NODES, markup-carve/carve#957; carve-php#1073).
  A node carries the author's code span, emphasis and source run and a string
  does not, so flattening at the derivation site destroyed them before any
  renderer was invoked. For `` # `code()` and *bold* heading ``, a `</#id>` to
  it published `code() and bold heading` and now publishes
  `<code>code()</code> and <strong>bold</strong> heading` - and each target
  spells the same nodes its own way, so the Markdown writer emits
  ``[`code()` and **bold** h](#id)`` and the terminal target emits its own bold.
  Five sites moved: the core cross-reference through both of its producers (the
  renderer and the in-link resolver), the numbered cross-reference's title, the
  injected table of contents, the `::: toc` placement directive, and an index
  term's display. The glossary term reference already rendered its nodes and is
  unchanged.

  A derived label is the heading's AUTHORED content, so nothing a later stage
  added appears in one: not a `section-number` span, not a permalink anchor, not
  a footnote reference (a second copy would publish a duplicate `fnref` id), not
  an invisible `:index[term]` marker, and not an abbreviation's expansion - the
  author's short form goes back in its place. A link in the heading unwraps
  inside the label's own anchor, and a mention with it (PART 12 §3a).

- **An inline footnote's BODY no longer leaks into a cross-reference label.**
  `^[note body]` in a heading has a body that renders once, in the endnotes; the
  flatten had no arm for the node, recursed into the body, and published
  `See h note body x` where the heading itself shows a footnote marker. The
  pointer is now dropped, which is what the `[^label]` reference beside it
  already got.

  A table-of-contents entry is now escaped ONCE, by the renderer that renders
  its nodes: a `"` in a heading reaches the entry as `"` rather than `&quot;`,
  matching the heading itself and carve-js. The entry also follows the caller's
  symbols map and raw-HTML policy, as it already followed the typography mode.

- **The Markdown target neutralizes embedded HTML in five more slots**
  (carve-php#1063). The writer's stated invariant is that `<`, `>` and `&` in
  author content are escaped so Markdown re-rendered to HTML cannot execute, and
  math content, the abbreviation definition line (key and expansion), the
  footnote label in both positions and an unresolved cross-reference's target
  all skipped it: a math span holding a `script` tag came out live, and an
  `<abbr title="...">` built from an escaped expansion sat in the same output as
  the unescaped `*[AB]:` line it came from. **Behavior change:** those slots now
  escape like every other author-content slot on this target. A footnote label
  escapes in both the reference and the definition, so the pair still matches;
  escaping math is transparent to a consumer, which decodes the entity back to
  the character before its math renderer sees it, exactly as the HTML target has
  always relied on. An unresolved cross-reference keeps its authored
  `</#target>` marker, which stays readable; only the target inside it is
  escaped, because `</#a<script>` is a complete opening tag once the Markdown is
  rendered.

- **A cross-reference label is a budgeted expansion** (carve-php#1061).
  `</#slug>` republishes the target heading's whole display text while the
  reference costs only the slug, so a short slug on a long heading amplified
  output by (heading length) x (reference count): 20 KB of input produced
  16.7 MB of HTML, 40 KB produced 66.7 MB, and the ratio kept growing with the
  input. The label now charges the same per-render expansion budget an
  abbreviation charges, on the HTML, Markdown, plain-text and ANSI targets
  alike. **Behavior change:** once that budget is spent, a cross-reference
  renders labelled with its authored target (`<a href="#A">A</a>`) instead of
  the target's full display text, the way an over-budget abbreviation renders as
  its plain key. The budget's floor and factor are unchanged, so ordinary
  documents are byte-identical. The Carve target reproduces the authored
  `</#slug>` and never expanded, so it is unchanged.

- **The Markdown writer probes the destination it will actually emit**
  (carve-php#1062). Its consumer decodes character references inside a link
  destination, and the writer probed the authored form, so
  `[t](&#106;avascript:alert1)` came out verbatim and decoded to a live scheme
  one hop downstream. `&#x6A;`, `javascript&colon;` and `javascript&#58;` did
  the same, the last two by hiding the colon so there was no scheme to find at
  all. **Behavior change:** an ampersand that opens a character reference is now
  emitted as `&amp;`, so a consumer decodes it back to the authored bytes
  instead of into a scheme. An ampersand that opens nothing, such as the `&` in
  a query string, is untouched. Percent-encoding the ampersand would have
  corrupted every query string and was not done.
- **A boundary line inside an open fence no longer ends the container**
  (markup-carve/carve#983 corpus category 279, markup-carve/carve#985,
  carve-php#1049). A `+` continuation marker attaches ONE block, and a fenced
  block ends at its closer - so a blank line, a sibling list marker, a dedent, a
  quote line or the next definition written between an opener and its closer is
  fence content and ends nothing. Six `+` collectors consulted no fence state at
  all (a seventh consulted only the code fence), so a code, `:::` or `%%%` fence
  with a blank in its body was cut in two in every container that can hold one:
  a list item, a block quote, a footnote body and a `dd`. The opener was left an
  empty block, the tail escaped to document level, and a code fence's closer
  came back as an empty inline code span. A list item's INDENTED body severed on
  the same reading: a list marker at the body's own column split a `:::` div
  around a nested list and published a spurious empty `div`, and a blank inside
  the item's own `%%%` body ended the item, leaked the span out as two
  paragraphs and loosened the item that held it. An UNTERMINATED fence is
  unchanged and still ends at the boundary.

- **Whitespace is a space or a tab, in every construct** (carve-php#1041, PART 7,
  markup-carve/carve#963, markup-carve/carve#977). Carve has exactly four
  whitespace characters - U+0020, U+0009, U+000A, U+000D - and every other
  character is content, so a VERTICAL TAB (U+000B) and a FORM FEED (U+000C) are
  content everywhere. This engine reached into PHP at 25 places and got two
  answers, because the default `trim` charlist takes a vertical tab and PCRE's
  `\s` takes a form feed as well. So: a list marker followed by one opens a list
  item; a definition term and description keep it as content; a footnote
  definition whose body is one is a definition, where a vertical tab used to make
  the definition disappear; a `+` followed by one is no longer the continuation
  marker; a heading or caption whose whole content is one is a heading or a
  caption, where it used to be refused outright; and a heading keeps a trailing
  vertical tab or form feed. Rendered HTML is unchanged for all 830 corpus
  documents.

- **A caption line drops its trailing whitespace, in the AST as well as the
  HTML** (carve-php#1037). The rule applied to the source for every other content
  line and to nothing for a caption, so the published AST carried `"Cap "` where
  the other engines carry `"Cap"`. `HtmlRenderer` had been trimming its own
  output instead, which also ate an all-space inline literal's content and
  swallowed the newline a trailing hard break emits. Consumers reading
  `text.value` out of a caption see one fewer trailing space, and the span
  shrinks with it.

- **Padding slots take a space, decided by position** (PART 7). A tab is syntax
  only inside a line's leading indentation run, so every slot after the first
  non-whitespace character of a line narrows. Five sites had three different
  character classes between them. Affected: the link and image title slots
  (`[t](/u<TAB>"T")` stays literal text); every table-cell padding slot
  (`|<TAB>a |` renders a cell whose text starts with the tab, and at
  `delimiter_cell` the line stops being a delimiter row entirely); the code
  fence, frontmatter and raw-block openers (```` ```<TAB>js ````, `---<TAB>yaml`,
  ```` ```<TAB>=html ```` are prose); and every slot on a colon-fence opener
  (`:::<TAB>note`, `::: note<TAB>"Title"`, `::: note<TAB>[lbl]` are prose).
  Cardinality is unchanged - a run of spaces still fills each slot.

- **An autolink body excludes format and control characters** (carve-php#983,
  markup-carve/carve#844, markup-carve/carve#860). Outside ASCII, `url_char`
  admits any character that is not whitespace, not General_Category Cf and not
  Cc. This engine already linked an internationalized domain; it also linked a
  host carrying a byte order mark, a zero-width space, a word joiner or a
  U+0001. `<https://e` + U+FEFF + `.com/>` is literal text now.
  `link_destination` is unchanged and `scheme` stays ASCII.

- **A link reference definition's destination is trimmed of Unicode whitespace.**
  `trim()` only knows ASCII, so `[a]: <U+202F>javascript:alert(1)` kept the
  narrow no-break space. HTML hid it; the ANSI target printed the destination to
  a terminal, where an invisible character is the spoofing shape the scheme probe
  exists to catch. Only the ends are trimmed; zero-width characters are not
  whitespace and stay.

- **A block-attribute block spans any number of lines** (carve-php#954). The
  continuation branch had required the line to be indented, which capped a block
  at a single break - visible from the third line on, and `{` / `.a` / `}` did
  not work at all. Two rules move with it: a blank line ends the attempt (PART 15
  A5), and a continuation line that is not a valid attribute list on its own ends
  it too. Inside a quoted value a line break is part of the value.

- **A construct opens only AT its container's content column** (PART 0 S4, PART 9
  §24 C3). One rule, five shapes that used to answer differently: a definition
  below every content column folds as text instead of being consumed and
  rendering nothing (`- - a` / ` [^f]: x` used to lose the second line outright);
  a below-column line folds at every depth, carrying exactly one column, so
  `-   x` / `    - a` / `  - b` no longer nests `b` under `a`; a dedented opener
  after a following-line sub-list folds rather than closing both lists; and a
  marker-line colon fence needs its body at the content column, so `- ::: note`
  with a flush-left body is literal text.

- **No open paragraph, no lazy line** (PART 0 S4, markup-carve/carve#950,
  markup-carve/carve#956). A fence opened on a list-marker line or on a `:  `
  definition-body marker line no longer reaches past the content column: the
  container closes, the item or `dd` holds an EMPTY code block, and the residue
  re-parses at document level - which is the answer the block-quote spelling
  already gave here. A CLOSED fence with nothing after it ends the body too. A
  marker-line sub-list holds an open paragraph like any other, so `- - a` / `b`
  folds `b` into the sub-item. And a dedented line folds into a quoted paragraph
  the parser actually built - the lazy tracker's private copy of the block-quote
  marker walk disagreed with the parser two functions away.

- **A list marker at the content column inside an open fence is code text**
  (carve-php#1007, markup-carve/carve#975). §24 S1 matches the item, so the
  innermost matched container is the FENCED BODY and S2 makes the line code text.
  Two collectors asked about the marker before the fence, so a fence opened on a
  marker line whose body held a marker line published a sublist beside an empty
  code block - a marker character decided whether a verbatim body was verbatim.
  Every marker shape is affected, and so is the block attached to a `+`
  continuation marker.

- **A block-quote marker with no space after it defines nothing**
  (carve-php#961). The definition prepasses read a looser marker rule than the
  block parser, so `>[r]: /u` printed as a paragraph AND resolved `[link][r]` off
  it. The mirror also happened: `> > [r]: /u` under a `> > ``` ` was skipped as
  fenced content while the block parser emptied the line, so the document showed
  neither the definition nor the link. There is now one rule.

- **A definition inside a container is collected, once, at the depth it was
  written.** The definition pre-pass reads a container's closer at the depth it
  opened at, so a nested `> > ``` ` inside `> ``` ` no longer ends the guarded
  region and a `> [^a]: note` after it no longer registers a footnote from inside
  a code block. The line-block guard moved onto the shared opener/closer helpers,
  so the pre-pass and the real parser agree on what opens and closes one.

- **An invisible construct in a list item does not loosen it** (PART 9 §17 L1,
  markup-carve/carve#621). `- a` / blank / `  %% note` came back as
  `<li><p>a</p></li>` - an item wrapped in `<p>` because of a line the reader
  never sees. This engine was the only one loosening for both the comment and the
  definition shape. L1's other clause is untouched: an item followed by a blank
  before the next sibling marker is still loose. A sub-list lead no longer exempts
  its item from the rule either.

- **A `+` continuation marker works whatever the item already holds**
  (carve-php#925, carve-php#929). §17 L3 conditions the marker on its column and
  nothing else, but the marker was ignored once the item held a blank line, so it
  came out as literal text inside the paragraph it was meant to end. Trailing
  whitespace after the `+` no longer breaks it either - the test was spelled four
  ways across seven sites.

- **A floating attribute does not cross a list-item boundary** (PART 9 §15 A2a
  and A4). `- a` / blank / `  {.c}` / `- b` put `class="c"` on the SECOND item's
  paragraph, because the pending-attribute run was parser state that survived
  into the next item's parse.

- **Two markers that reach the same column are one list, however they got there**
  (carve-php#890). Dedenting to a content column consumed a straddling TAB whole
  and dropped the columns past it, so a space-plus-tab marker and a four-space
  marker arrived at different columns and opened a third list between them.

- **A block boundary no longer depends on a line starting with a pipe.** The
  block-start test accepted ANY line beginning with `|` as a table, so a column-0
  `|` after a list item detached from the item where `*`, `-` and `x` all
  attached. The row is validated in both places now, so a pipe in prose is prose.

- **An over-cap opener groups by the ordinary paragraph rule** (PART 9 §25).
  Consecutive over-cap openers and the text after them form ONE paragraph, ending
  at the first blank line, with no trailing newline before `</p>`. The degrade
  path used to hand the whole remainder to a single paragraph, swallowing a blank
  line and everything after it.

- **An unresolved reference image is not a figure.** `![a][nope]` with a caption
  line under it was promoted to a `<figure>`, and the writer then wrote it back
  as `![a]()`, losing both the label and the destination. The caption line's
  interruption test moved with it, so `^ cap` no longer becomes its own
  paragraph. A resolved image still becomes a figure.

- **A citation-key definition line does not interrupt a paragraph.** A citation
  key is not a link reference definition here, but the interruption predicate
  matched it
  anyway, so a hard-wrapped prose line followed by a bibliography entry ended the
  paragraph and the entry reappeared as a second, visible one.

- **An implicit `[Heading][]` reference resolves against the document's real
  headings** (PART 11 R1, carve-php#572). The index came from a line scan
  matching `^#{1,6}` at column 0, so which headings it found came down to source
  indentation: a heading inside a list item was missed because it is indented, a
  `#` line inside a CODE FENCE was indexed because it is not, and a blockquote's
  headings were excluded by accident rather than by the rule. The index is now
  built from the parsed tree, which also asks R1's real question - does this
  heading have a blockquote ANCESTOR, in either nesting order. Two false lint
  warnings go with it. The explicit `[text][Label]` form had the same hole and is
  fixed with it. Costs one extra block parse, only for a document containing
  `][`.

- **A resolved cross-reference and every consumer that derives text from a
  heading keep the heading's source run** (PART 9R R4, markup-carve/carve#952,
  markup-carve/carve#957). The heading was flattened to a glyph string at
  id-tracking time, so smart typography's SOURCE mode could not recover it on any
  target. `# The "quoted" -- heading` with a cross-reference to it now emits the
  typed quotes and double hyphen in source mode on every target, and the glyphs at
  the default. `HeadingNumbersExtension` and both table-of-contents extensions
  follow the same rule. The heading ID is unchanged - still slugged from the
  glyph.

- **An auto-generated heading id no longer displaces an id the author wrote**
  (PART 10 §1). On an unwrapped heading this engine put the id last in every case,
  so `{#x a=b}` rendered `<h1 a="b" id="x">`. Authored attributes keep their
  source order; only a generated id joins at the end.

- **Non-HTML targets stop losing content.** The ANSI target keeps a code block's
  verbatim content - `rtrim()` was taking the trailing space on the last line and
  every blank line at the end. A table column claimed by a `<` or `^` span
  survives on the plain-text and ANSI targets, where the two writers had been
  trimming a span-covered column as if the row were short. A raw block's interior
  lines pass through verbatim rather than gaining their container's indentation
  inside a `<pre>`.

- **A spoiler is revealed in `static` mode** (markup-carve/carve#843).
  `SpoilerExtension` did not implement `StaticRenderExtensionInterface`, so
  `mode: "static"` produced a collapsed `<details>` and a print or PDF engine
  never showed the body - silent content loss on the path
  `docs/graceful-degradation.md` exists to rule out.

- **A `%%%` comment opener with trailing text no longer leaks the comment body
  and drops the next block** (PART 9 §28). Only the leading run of `%` is
  structural, so `%%% TODO` opens and `%%% end` closes; `%%% html` is a comment
  and its body stays hidden. The closer matches on exact delimiter length, so
  `%%%%` no longer closes a `%%%` block. An opener with no closer ahead degrades
  to a line comment.

- **A link label's closing `]` is found past an editorial comment.** `[{#a]b#}](u)`
  formed no link, and no spelling worked, since `{# … #}` resolves no escapes.

- **The Markdown renderer no longer de-escapes underscores inside verbatim
  content.** `` `a\_b` `` came back as `` `a_b` ``, and the same happened in
  fenced code blocks, link destinations, image sources and escaped raw HTML.

- **The converters no longer turn plain input into Carve markup.**
  `MarkdownToCarve`, `DjotToCarve`, `HtmlToCarve` and `BbcodeToCarve` passed
  `/…/`, `=…=`, single `~…~`, `{^…^}`, `{,…,}` and `%%…%%` straight through for
  Carve to parse as markup, so `a {,y,} b` came out as a subscript and
  `a %%c%% b` lost its text entirely. The first delimiter of each construct is
  escaped now, after code spans, links and URLs are protected, with a per-caller
  opt-out for delimiters the source language owns. `MarkdownToCarve` also
  preserves leading frontmatter byte-for-byte instead of migrating it as a
  thematic break plus a setext underline, keeps `---` / `---` as two thematic
  breaks rather than an `## ---` heading, and no longer emits an unguarded
  `---\n\n---` that Carve reads as empty frontmatter. **Behavior change:**
  Markdown that contained Carve inline syntax and passed through verbatim is now
  escaped.

- **The published AST is the shape the schema describes.** Rendered output does
  not move.

  - An unresolved reference stays a `link` (an `image` for `![alt][nope]`)
    carrying `ref` and `rawRef`, where it was flattened to an internal `raw_text`
    node the AST vocabulary does not have - five corpus documents serialized to
    JSON the schema rejected. Nothing produces `raw_text` any more.
  - A resolved crossref publishes its destination:
    `{"type":"heading_ref","target":"intro","href":"#Intro"}`. An unresolved one
    keeps `target` and no `href` - the absent field is what says it did not
    resolve.
  - A field the schema REQUIRES is published even when it holds the node's
    default; six were omitted, which produced a tree the format rejects.
  - A heading always publishes its `level`.
  - The decoder stops recording a source slot for a structural class, so
    `decode(encode(parse(x)))` no longer gains `.class` in `attrs.order`.
  - `AstCodec::schema()` names every type and field the encoder emits.
    `autolink`, `admonition` and `tag` have no node class to reflect over and
    were emitted while absent from the schema entirely, along with ten
    hand-written fields - so an application validating a payload was told a type
    it had just been sent does not exist.

- **An expansion budget is sized from what the payload cost, not from what it
  claims** (carve-php#1052). The abbreviation, table-of-contents and index
  budgets are `max(floor, factor x source length)`. On the parse path that length
  is measured, so a bigger budget costs a bigger document; on the AST-ingest path
  it arrived inside the payload as `srcByteLength`, which let the payload choose
  the size of the guard meant to bound it - rewriting one number to `1000000000`
  took a 214 KB payload from 1.05 MB of HTML to 102 MB, 478x, for nine extra
  bytes. An ingested document is bounded by what its payload actually cost as
  well as by what it claims, and the smaller of the two wins. `srcByteLength` is
  still read exactly as written and re-encoded unchanged, because PART 12 §7
  makes it a field of the payload and a reader that rewrote it would have
  silently repaired the record. A renderer or extension sizing a budget should
  read the new `Document::getExpansionBudgetLength()` rather than
  `getSourceLength()`. Nothing on the parse path changes. **On the ingest path
  the ceiling can bind on legitimate input**, in one narrow shape: a document
  whose encoded tree is SMALLER than the source it came from - a source that is
  mostly blank lines or comments - and whose source is past about 125 KB, where
  the budget's 1 MB floor stops covering it. Such a document renders with a
  smaller expansion budget after a round trip than when parsed, so a `::: toc`
  past the budget emits an empty nav. No corpus document is affected; the
  smallest encoded tree is still 2.25x its source.

- **Every array-taking ingest entry point applies the nesting bound its string
  sibling already applied** (carve-php#1050, carve-php#1051). `decodeJson()`,
  `convertJson()` and `upgradeJson()` were bounded for free, because
  `json_decode` takes a depth argument. The array entry points beside them -
  `AstCodec::decode()`, `AstSchema::firstViolation()`,
  `ProseMirrorToCarve::convert()`, `StoredPayloadUpgrade::upgrade()` and
  `::retiredShapesIn()` - were handed a structure somebody else had decoded, and
  every walk under them is plain recursion, so a deep enough payload exhausted
  the C stack: a segmentation fault rather than a catchable exception. A host
  ingesting a stored tree, a bridge result, a cached tree or a database row as
  an array had no bound at all, and the migration helper exists precisely to be
  swept over payloads nobody in the process wrote. Each now refuses past the
  same number its string sibling passes to `json_decode`, with a message naming
  the bound. **This changes behavior on legitimate deeply-nested input too**: an
  array nesting 1216 or more containers (`AstCodec`, `StoredPayloadUpgrade`) or
  512 or more (`ProseMirrorToCarve`) used to be accepted and is now refused, so
  a caller that decoded its own JSON at a raised depth and passed the array in
  gets an exception where it got a document. Nothing the parser or the encoder
  produces comes near either bound.

- **A footnoted tree from another engine decodes.** carve-js and carve-rs
  publish footnote definitions as PART 12 §7 block nodes, and this engine reads
  them, so a document carrying a footnote crosses between all three. The route
  there was not direct: this decoder first learned to adopt a root-level
  `footnoteDefs` map, which is the shape carve-js used to keep them in and which
  the loss check had refused outright, and that inlet was then retired with the
  other four pre-§7 spellings (see the Breaking entry above). What is left is the
  §7 shape, which all three engines write and all three read. A payload STORED in
  the root-map shape is refused by all three engines and goes through
  `StoredPayloadUpgrade::upgrade()` first.

- **Source spans describe the source.** A span begins at the construct's opening
  markup (PART 12 §4), where anything nested in a container used to begin at the
  CONTAINER's prefix - a heading in a block quote began at the `>`, a fenced
  block in a list item at the `-`. Over the spec corpus that was 33 wrong spans
  across nine node types, now none. The paragraph an over-cap opener degrades to
  places its text runs too.

- **`carve fmt` reproduces the document it was given.** Every one of these
  produced a different document on the next parse, or grew the source on each
  pass:

  - Punctuation that opens nothing is no longer escaped: a caret (superscript is
    braced-only now), a mid-line colon, and a caption marker on a line where no
    caption can form.
  - A heading-derived reference keeps its authored form - `[getting started][]`
    was being replaced with `[getting started](#Getting-Started)`, baking a
    generated id into the source on every pass.
  - Attributes written in BOTH authored positions survive, subtracted per class
    token rather than per whole value, so the line stopped growing by one
    duplicate per pass. An `#id` on a definition's attribute block survives, as
    does a block-attribute line above a captionless reference image.
  - A `+` continuation marker before an attached paragraph is kept, instead of
    being dropped and the paragraph indented into a lazy continuation.
  - A line block is written as `::: |` with plain-space indentation and a plain
    medial gap, not as a `.line-block` div with literal no-break spaces. All seven
    line-block corpus cases round-trip; four did not.
  - A table is written in the native header form (`=` cells plus per-cell
    alignment markers) instead of a GFM delimiter row.
  - A lone table span marker stays padded (`| < |`), so a formatted table cannot
    be read elsewhere as a left-alignment marker.
  - A mention keeps its name, attributes, nested markup and doubled sigil - the
    writer had been DELETING every character outside `[\w.-]`, so `o'brien` was
    written `@obrien`, a different user. An unspellable label degrades to the link
    form.
  - An unresolved footnote reference keeps its trailing attribute block.
  - The frontmatter format token is spelled out (`---yaml`), the one format that
    was written bare.
  - An empty container body gets a blank line between opener and closer, for
    every container shape. A word-class div (`::: b` / `:::`) keeps it too.
  - A heading handed in from an ingested AST is not split: breaks collapse to a
    space.

- **The canonical writer stops emitting a PHP 8.5 deprecation.**
  `ReflectionProperty::setAccessible(true)` has done nothing since PHP 8.1 and is
  deprecated in 8.5, so every `CarveRenderer` render raised a notice on 8.5.

- **The ProseMirror bridge stops losing content silently.** A round trip through
  the editor model used to drop things with nothing in `droppedTypes()` or
  `degradedTypes()` to say so:

  - A table cell keeps its text. Tiptap wraps cell content in a paragraph, which
    the Carve cell writer dropped. Block boundaries and hard breaks inside a cell
    degrade to a single space rather than ending the row.
  - Block-position inline nodes (Tiptap's image extension, and any custom editor
    node) are wrapped in a paragraph instead of leaving an inline as a block
    container's child, which the Carve writer had no form for.
  - Four declared-but-unset fields now carry: a container's title, whether a div
    was typed, an abbreviation's expansion, and a semantic span's name.
  - A footnote keeps its label, so two references to one note stop being
    indistinguishable from two references to different notes.
  - A collapsed heading reference keeps its spelling instead of baking a
    generated id into the source on every pass.
  - An authored attribute no longer displaces the syntax that owns the value:
    `[safe](https://example.com){href=javascript:steal}` reached the editor as a
    link whose `href` was the authored attribute, and writing that model back out
    rewrote the destination. Structural values now win for `href`, `src`,
    `title`, `alt`, `colspan`, `rowspan`, `alignment`, `display` and `label`.
  - Losses that cannot be carried are REPORTED: `ProseMirrorToCarve::droppedAttributes()`
    names each non-scalar attribute it drops (Tiptap's `colwidth` array is the
    real producer), and `degradedTypes()` gains `autolink`, `link` with an empty
    label, `code` attributes, and list style/marker normalization.

- **Parsing is faster on deep documents, with no parse result changed.** A nested
  container no longer re-measures every body line's whole indentation run at
  every level: on the deepest conforming ladder (depth 200, 40,600 bytes) the
  gate walked 2,707,196 characters and now walks 100,097, and the parse of a deep
  list is linear in its size again (82.4 ms to 49.7 ms, growth per depth doubling
  5.10x to 4.07x). A document full of `%%%` openers with distinct widths no longer
  rescans itself per opener; the lookahead is answered from a width-to-last-index
  map built in one pass. The whole spec corpus renders byte-identically on all
  five targets, same SHA-256 over 675 documents before and after.

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

[Unreleased]: https://github.com/markup-carve/carve-php/compare/0.1.5...HEAD
[0.1.5]: https://github.com/markup-carve/carve-php/compare/0.1.4...0.1.5
[0.1.4]: https://github.com/markup-carve/carve-php/compare/0.1.3...0.1.4
[0.1.3]: https://github.com/markup-carve/carve-php/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/markup-carve/carve-php/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/markup-carve/carve-php/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/markup-carve/carve-php/releases/tag/0.1.0
