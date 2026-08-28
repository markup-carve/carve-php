# Importing HTML

The loss report, the diagnostic `path` locator, and the import modes.


~~~ php
use MarkupCarve\Carve\Converter\HtmlToCarve;

$result = (new HtmlToCarve(importMode: 'safe'))->convertWithReport($html);
$carve = $result->value;
$report = $result->report();
~~~

The existing `convert()` API remains unchanged. The CLI equivalent is
`carve migrate --from html --report report.json input.html`.

Each diagnostic carries a `path` locating what was lost. It is a human-readable
locator that all three engines spell the same way, and although it borrows
XPath's notation it is **not** an XPath expression - do not resolve it as one.
It starts at the top level of the fragment handed to the importer, so no wrapper
of the importer's own and no authored `<html>`/`<body>` appears in it; `[n]` is
the position among all of the parent's child nodes, text included; and it names
the traversal the conversion performs, so a table's rows are flattened out of
`<thead>`/`<tbody>` and numbered across the whole table. For this input:

~~~ html
<table><thead><tr><th>h</th></tr></thead><tbody><tr><td onclick="x()">c</td></tr></tbody></table>
~~~

the dropped handler is reported at:

~~~
/table[1]/tr[2]/td[1]
~~~

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

Three more importers convert other markup to Carve, in the library as
`MarkdownToCarve`, `DjotToCarve` and `BbcodeToCarve`, and on the command line
as `carve migrate --from markdown|djot|bbcode`:

~~~ bash
carve migrate --from markdown README.md > README.crv
carve migrate --from djot notes.dj
cat post.txt | carve migrate --from bbcode
~~~

`--mode`, `--adapter`, `--report` and `--check-loss` are the HTML importer's
alone: the other three parse their source whole, so they have nothing to report
as lost. `MarkdownToCarve` reads CommonMark plus GFM by default; its two
constructor flags opt in to the `$math$` and `==highlight==` extensions that
neither dialect defines.

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
