# Importing HTML

The loss report, the diagnostic `path` locator, and the import modes.

## Which option does what

| Option | Effect |
| --- | --- |
| Export `roundTripMode` / `--roundtrip` | Adds carriers to output. Any import mode reads them; this is not a trust decision. |
| `importMode`: `safe`, `semantic`, `roundtrip` | Decides what happens to HTML Carve cannot spell. `roundtrip` keeps it as raw `{=html}`. |

Omitting `importMode` selects `safe`. Export options do not select an import mode.

HTML import uses PHP's native HTML5 parser on PHP 8.4 and later. On PHP 8.2 and
8.3 it keeps the legacy libxml parser. Both paths require `ext-dom` when importing
HTML; no additional Composer package is required.

The HTML5 parser reconstructs misnested formatting and anchors, inserts implied
table containers, and moves misplaced table text before the table. Diagnostic
paths describe that parsed tree. For example, a bare `<col>` inside a table belongs
to an implied `<colgroup>`, and a document's `<body>` follows its explicit or
implied `<head>`. Malformed HTML can therefore produce different imports and
reports on PHP 8.2–8.3. Use PHP 8.4 or later when HTML5 tree construction is needed.

On PHP 8.4 and later, import refuses trees deeper than 512 element levels with
`HtmlImportDepthExceededException`. Raw text containing tag-like strings does not
count toward this limit. The limit is checked on the parsed tree; it does not
bound the time spent inside PHP's native parser.

~~~ php
use MarkupCarve\Carve\Converter\HtmlToCarve;

$result = (new HtmlToCarve(importMode: 'safe'))->convertWithFidelityReport($html);
$carve = $result->value;
$report = $result->report();
~~~

The existing `convert()` and detailed `convertWithReport()` APIs remain
unchanged. `convertWithFidelityReport()` returns the shared version 2 envelope,
including `sourceFormat`, import `mode` and `adapter`, plus `fidelity` and
`confidence` for every diagnostic. The CLI equivalent is
`carve migrate --from html --report report.json input.html`; `--check-loss`
exits with status 1 for degraded or dropped findings. The diagnostic cap replaces
the last report row with `diagnostics-truncated`; conversion still returns its
output. With a cap of zero, a report with any finding contains only that marker.

A pipe-table cell is one line, so by default a cell holding a list, a code block
or several paragraphs is flattened to its text. `listTableForBlockCells: true`
(CLI: `--list-table`) writes such a table as a `::: list-table`, whose cells are
list items and keep their blocks; a table whose cells are all inline keeps the
pipe form. It is off by default because ListTable is an optional extension:
render the result with `ListTableExtension` enabled, or the table shows as a
nested list. The Markdown target writes a list table as a pipe table either way.

~~~ php
$carve = (new HtmlToCarve(listTableForBlockCells: true))->convert($html);
~~~

## Roundtrip carrier names

The attributes a roundtrip export writes so an import can recover a distinction
the HTML has no spelling for are the `data-carve-*` names PART 10 section 13a
reserves. The renderer writes that spelling only.

The export option is off by default. `CarveConverter` takes it as a constructor
argument, a renderer through `setRoundTripMode()`, and the CLI as `--roundtrip`:

~~~ php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

$html = (new CarveConverter(roundTripMode: true))->convert($source);

$renderer = (new HtmlRenderer())->setRoundTripMode(true);
$html = (new CarveConverter(renderer: $renderer))->convert($source);
~~~

~~~
bin/carve --roundtrip input.crv
~~~

This source:

~~~
* item

```php [Example]
echo 1;
```
~~~

renders under the option to:

~~~ html
<ul data-marker="*">
  <li>item</li>
</ul>
<pre data-carve-label="Example"><code class="language-php">echo 1;
</code></pre>
~~~

The core renderer writes the rows of PART 10 section 13a, under the option only:

| Attribute | Element | Value |
| --- | --- | --- |
| `data-marker` | `<ul>` | The bullet marker, where it is not `-`. |
| `data-char` | `<hr>` | The thematic-break character, where it is not `-`. |
| `data-carve-explicit-id` | `<section>`, `<h1>`-`<h6>` | `1`: the id was authored. It rides the heading where no section wraps it. |
| `data-carve-ref` | `<a>`, `<img>` | The reference label the link or image resolved by. |
| `data-carve-autolink` | `<a>` | `1`: the link was written as an autolink. |
| `data-carve-footnote-label` | `<a>`, `<li>` | The footnote label, on the reference and on the endnote item. |
| `data-carve-inline-footnote` | `<li>` | `1`: the endnote came from an inline footnote. |
| `data-carve-raw` | `<span>` | The raw inline's format. The span's text holds its content. |
| `data-carve-escaped` | `<span>` | No value. The span holds one escaped character. |
| `data-carve-abbreviations` | `<template>` | No value. The text holds the abbreviation definitions, one per line. |
| `data-carve-col-widths` | `<table>` | The separator row's dash widths, comma-joined. |
| `data-carve-label` | `<pre>` | The code block's bracketed label. |
| `data-carve-generated-id` | `<section>`, `<h1>`-`<h6>` | Presence: the id was generated. |
| `data-carve-id-slot` | `<section>` | The authored id's position among the heading's attributes, counted from 0. Omitted at 0. |
| `data-carve-tight` | `<ul>`, `<ol>` | Presence: the list is tight although an item's paragraph keeps its `<p>`. |

A carrier follows the element's structural attributes (`type`, `start`,
`data-delim`, `href`, `src`, `alt`, `title`) and precedes the attributes the
author wrote:

~~~
{.c}
1) {.x}
   a
2) b
~~~

~~~ html
<ol data-delim=")" data-carve-tight="1" class="c">
  <li><p class="x">a</p></li>
  <li>b</li>
</ol>
~~~

Roundtrip export drops reserved carrier names on every node and reports one
`carrier-attribute-dropped` loss for each authored attribute. This includes
`data-djot-` aliases. Default export keeps authored attributes.

Extensions write carriers of their own; see
[Roundtrip carriers](extensions.md#roundtrip-carriers).

An import recovers the distinctions in these tables. It does not return
byte-identical source:

- A raw block has no carrier. One whose format HTML cannot write is dropped with
  a `raw-format-dropped` render loss, with or without the option.
- A raw inline whose format HTML cannot write reports `raw-format-dropped` with
  or without the option. Under the option `data-carve-raw` carries it as well.
- A task marker `[X]` returns as `[x]`.
- A collapsed reference `[r][]` and a full reference `[r][r]` both return as
  `[r][]`.

Documents exported by earlier versions carry the same names under a
`data-djot-*` prefix. The importer accepts that spelling as a **deprecated
alias**: it reads the alias as the `data-carve-` name, the `data-carve-` name
wins where an element carries both, and the alias is consumed either way, so a
document that arrives with it leaves without it.

Every reserved carrier that import consumes without reading it, including an
alias that lost to the `data-carve-` name, gets a report row:
`attribute-dropped` at `info` on an element the importer rewrites, and
`attribute-preserved` on an element `roundtrip` mode keeps as raw HTML. Kept raw
HTML is never rewritten: its carriers keep their authored names, values and
order.

`data-carve-src` is reserved without a row. No renderer writes it, and import
consumes it and its `data-djot-src` alias in every mode without reading Carve
source from the value. The import report names each one: `attribute-dropped`
at `info` on an element the importer rewrites, and `attribute-preserved` on an
element `roundtrip` mode keeps as raw HTML, whose bytes keep the attribute as
written. Default export passes an authored one through. Roundtrip export drops
it and reports `carrier-attribute-dropped`.

The reserved set is an enumeration, not a prefix. A `data-carve-` or
`data-djot-` name the enumeration does not list is an ordinary authored
attribute and is imported as one. `MarkupCarve\Carve\Converter\Html\CarrierNames`
holds the list, including the carriers consumed by the bundled PHP extensions.

Every import mode consumes the names in the table. A value outside its carrier's
grammar is dropped and reported. An id slot requires an explicit-id carrier,
decimal digits, and a value no larger than the heading's surviving attribute
count. An invalid slot places the id first. An unmarked heading id is authored
in every mode; an unmarked section id equal to its heading's slug is generated.


Two carriers that contradict each other on one element restore neither meaning.
An `<a>` carrying both `data-carve-autolink` and a valid `data-carve-ref` imports
as an inline link with one `attribute-dropped` warning. An endnote carrying both
a footnote label and the inline-footnote flag imports as an ordinary numbered
note with the same warning. The other contradictory pairs are explicit-id with
generated-id and escaped with raw. This is a closed list.

Each diagnostic carries a `path` locating what was lost. It is a human-readable
locator that all three engines spell the same way, and although it borrows
XPath's notation it is **not** an XPath expression - do not resolve it as one.
Paths for content start at the top level of the imported fragment. They omit
the importer's wrapper and any authored `<html>`, `<head>`, or `<body>`. A finding
on an attribute of one of those document elements names that element instead.
`[n]` is the position among all of the parent's child nodes, text included.
Paths follow the conversion's traversal, so table rows are flattened out of
`<thead>`/`<tbody>` and numbered across the whole table. For this input:

~~~ html
<table><thead><tr><th>h</th></tr></thead><tbody><tr><td onclick="x()">c</td></tr></tbody></table>
~~~

the dropped handler is reported at:

~~~
/table[1]/tr[2]/td[1]
~~~

In every mode, an `<a href>` or `<img src>` whose scheme the renderer blanks
(`javascript:`, `data:`, OS handlers such as `ms-msdt:` and the rest of the
renderer's denylist, read after stripping whitespace and controls) is not
written. The element imports as if its destination were empty: its content
stays, an `id` keeps it as a span, and the report has one `attribute-dropped`
warning for it. The exception is an element such as `<form>` that `roundtrip`
keeps as raw HTML: its bytes, descendants included, are written unchanged.

A table's head/body/foot sections are one of the things that report names. A
Carve pipe table is a flat row list whose head is the leading run of header
rows, and Carve 0.1 source has no spelling for the explicit partition the AST
can hold, so a `<tfoot>`, a second `<tbody>`, or a `<thead>` that does not match
that leading run flattens into the row list. That is deliberate - a spelling for
it would be a language change, not an importer one - and each case emits a
`table-degraded` diagnostic rather than passing in silence. Row-head columns are
not affected: a `<th>` beside data cells has an exact spelling and round-trips,
unless it also carries attributes, which no cell can hold alongside the header
marker.

A `<math>` element is another. Its TeX is read from an `<annotation>` declaring
`application/x-tex`, `text/x-tex` or `LaTeX` as a direct child of the element's
`<semantics>`, else from `alttext` with an `encoding-assumed` info, since
MathML does not declare what `alttext` holds. An element carrying neither has no
TeX to give: `roundtrip` keeps it verbatim, while `safe` and `semantic` drop it
with an `element-dropped` warning rather than concatenate its children, which
would read `<mfrac><mn>1</mn><mn>2</mn></mfrac>` back as `12`.

An empty `<code>` is a third. A verbatim span with nothing in it is a backtick
run nothing closes, so Carve spells it only where the run itself ends: at the
end of a block, or at the `X}` closing a forced span. Anywhere else the run
reads what follows as code, so an empty `<code>` with a sibling behind it is
dropped with a `structure-unspellable` warning. Where the run does end, the span
survives and the emphasis around it takes the braced closer:

~~~ html
<p><s><code></code></s></p>
~~~

imports as

~~~
{~``~}
~~~

An attribute block attaches to a closing run, which such a span has not got, so
it is written bare and what it carried is reported as `attribute-dropped`.

An ordered task item is a fourth. `task_marker` in Carve hangs off
`unordered_item` alone, so a box behind an ordered marker has no spelling:

~~~ html
<ol><li><input type="checkbox" checked disabled> done</li></ol>
~~~

imports as

~~~
1. [x] done
~~~

with one `structure-unspellable` warning at the `<input>`'s own path. `checked`,
`disabled` and a `data-task-state` character all reach the brackets, so they take
no rows of their own; anything else on that input still reports the loss it is.
Only a writer is affected, so `convertToAstWithReport()` keeps `checked` on the
item and says nothing. Bullets are unaffected.

Task lists render with `class="task-list"` on their unordered list and
`data-task-state="x"` on checked items. Default unchecked items have no derived
state attribute; extended states retain `-`, `_`, `>` or `?`. The hooks apply
in static and interactive output without enabling roundtrip mode. An authored
class keeps its attribute position, with `task-list` prepended to its tokens.

HTML import consumes the structural class only when every direct item is a
task. It consumes a checked-state hook beside a checked box and an extended
state beside an unchecked box. Legacy checkbox-only HTML still imports, and a
plain list's authored `task-list` class stays authored. Derived state hooks
replace authored attributes with the same case-insensitive name.

Three more importers convert other markup to Carve, in the library as
`MarkdownToCarve`, `DjotToCarve` and `BbcodeToCarve`, and on the command line
as `carve migrate --from markdown|djot|bbcode`:

~~~ bash
carve migrate --from markdown README.md > README.crv
carve migrate --from djot notes.dj
carve migrate --from djot --djot-php blog.dj
cat post.txt | carve migrate --from bbcode
~~~

Use `--djot-php` for djot-php's unquoted admonition titles and labeled code
fences. In PHP, pass `djotPhpExtensions: true` to `new DjotToCarve()`.

`--mode` and `--adapter` are HTML-only. `--report` and `--check-loss` apply to
every importer; Markdown, Djot and BBCode fail closed until they provide
construct-level fidelity evidence. Markdown names one loss beside that blanket
row: an ordered task item. cmark-gfm reads a checkbox on `1. [x] done`, Carve
spells a checkbox behind a bullet only, so the marker survives as text and a
`structure-unspellable` row names the source line it was read on.
`MarkdownToCarve` reads CommonMark plus GFM
by default; its two
constructor flags opt in to the `$math$` and `==highlight==` extensions that
neither dialect defines.

Where GFM sees no checkbox but Carve would, the brackets are escaped instead and
nothing is reported. GFM honors ` `, `x` and `X` after ONE container marker, so
`- - [ ] a` and Carve's extra states `[-]`, `[_]`, `[>]`, `[?]` all take the
backslash - unless a link reference definition names that label, in which case GFM
resolves a link and the collapsed form goes in place of the escape. A label GFM did
read leaves its definition idle: `- [x] done` above `[x]: /u` keeps both lines as
typed and renders the box.

`--adapter word` and `--adapter google-docs` add one recognition the `generic`
default does not risk: footnote-shaped HTML. A word processor writes a note as
a body anchor and a definition block that link to each other, and none of them
uses the `doc-noteref` / `doc-endnotes` roles a Carve engine writes, so under
`generic` a note arrives as a literal link beside an orphaned list. Under those
two adapters the pair is matched through the fragment each anchor addresses and
written back as `[^1]` and `[^1]: `, whatever the ids are called - Word's
`_ftnref1`/`_ftn1`, Google Docs' `ftnt_ref1`/`ftnt1`, LibreOffice's
`sdfootnote1anc`/`sdfootnote1sym` and Pandoc's `fnref1`/`fn1` all pair by the
same rule. Back-links, the marker anchors they sit on, and the rule separating
the notes from the body are generated navigation and are dropped. A reference
whose target is missing stays a link, and a definition nothing references stays
ordinary content rather than becoming a definition that renders as nothing.
Name the adapter only for input you know came from that editor: on arbitrary
HTML a mutually linked anchor pair is not proof of a footnote, which is why
`generic` stays out.

HTML rendering can replace trusted `:name:` symbols with a configured map.
Unmapped symbols render literally, and symbol attributes wrap the result in a
`<span>`:

~~~ php
$converter = new CarveConverter(symbols: [
    'rocket' => '🚀',
    'tada' => '🎉',
]);

$html = $converter->convert(':rocket:{.big}');
// <p><span class="big">🚀</span></p>
~~~

Besides HTML, the same AST renders to Markdown, plain text, and ANSI via the
`CarveConverter::markdown()`, `::plainText()`, and `::ansi()` factories:

~~~ php
$markdown = CarveConverter::markdown()->convert('# Hello /Carve/');
$ansi = CarveConverter::ansi()->convert('# Hello /Carve/');
~~~

Raw nodes are routed to their named target. Use a checked result when omitted
content must be observable:

~~~ php
$result = CarveConverter::create()->convertWithReport('`x`{=latex}');
// $result->losses[0]['code'] === 'raw-format-dropped'

CarveConverter::create()->convertWithReport('`x`{=latex}', strictLosses: true);
// throws RenderLossException before a value is returned
~~~

Reports retain the complete count and are bounded to 100 detailed entries by
default. The existing string-returning `convert()` and `render()` APIs remain
available.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
