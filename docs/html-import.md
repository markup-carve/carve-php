# Importing HTML

The loss report, the diagnostic `path` locator, and the import modes.


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

When trusted `data-djot-src` returns stored Carve source verbatim, the report has
no diagnostics. The HTML descendants are not imported in that path.

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
`<semantics>`, else from `alttext` with a `math-encoding-assumed` info, since
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

Three more importers convert other markup to Carve, in the library as
`MarkdownToCarve`, `DjotToCarve` and `BbcodeToCarve`, and on the command line
as `carve migrate --from markdown|djot|bbcode`:

~~~ bash
carve migrate --from markdown README.md > README.crv
carve migrate --from djot notes.dj
cat post.txt | carve migrate --from bbcode
~~~

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
