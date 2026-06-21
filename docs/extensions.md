# Bundled extensions

This page documents the extensions shipped with `carve-php`. The normative,
language-level extension contract (taxonomy, matcher/transform/renderer stages,
registration) lives upstream in
[`carve/docs/extensions.md`](https://github.com/markup-carve/carve/blob/main/docs/extensions.md).

## Default vs opt-in

Two extensions are part of the core Carve language and are registered
automatically on the first `parse()`/`convert()` call, so they are active out of
the box without any `addExtension()` call:

- **FrontmatterExtension** - a leading `---yaml ... ---` block is treated as
  document metadata and stripped from the rendered output (not a thematic break).
- **MentionsExtension** - `@mentions` and `#tags` are parsed as core social
  syntax.

Both are registered lazily: if you add your own pre-configured instance (for
example `new MentionsExtension(mentionUrl: '/users/{name}')`) before the first
parse, your instance takes precedence and the default is not added. Configure
extensions before the first `parse()`; the standard extension lifecycle expects
all extensions to be in place before parsing begins.

Every other extension below is **opt-in** and must be registered manually.

## Adding an extension

All extensions are registered through `addExtension()`:

~~~ php
use Carve\CarveConverter;
use Carve\Extension\ExternalLinksExtension;

$converter = new CarveConverter();
$converter->addExtension(new ExternalLinksExtension());

$html = $converter->convert($djot);
~~~

For each opt-in extension below, the registration call is simply
`addExtension(new FooExtension(...))`. Per-extension examples are shown only
where the syntax or options add value.

Note: `HeadingReferenceExtension` and `WikilinksExtension` both parse `[[...]]`
syntax and therefore cannot be registered on the same converter instance - doing
so throws a `LogicException`.

## Links and references

### AutolinkExtension

Converts bare URLs in text (`http://`, `https://`, `mailto:`, and bare email
addresses) into clickable links without explicit link syntax.

Constructor options:

- `allowedSchemes` (`array<string>`, default `['https', 'http', 'mailto']`) -
  which URL schemes to auto-link. When `mailto` is included, bare email
  addresses are also linked.

~~~ php
$converter->addExtension(new AutolinkExtension());

$converter->convert('Visit https://example.com for more info.');
// <p>Visit <a href="https://example.com">https://example.com</a> for more info.</p>

// Only http/https, no mailto / bare emails:
$converter->addExtension(new AutolinkExtension(allowedSchemes: ['https', 'http']));
~~~

### ExternalLinksExtension

Adds `target` and `rel` attributes to external links (URLs starting with
`http://` or `https://`). Hosts you list as internal are left untouched.

Constructor options:

- `internalHosts` (`array<string>`, default `[]`) - hosts treated as internal
  (no external attributes added).
- `target` (`string`, default `'_blank'`).
- `rel` (`string`, default `'noopener noreferrer'`).
- `nofollow` (`bool`, default `false`) - append `nofollow` to the `rel` value.

~~~ php
$converter->addExtension(new ExternalLinksExtension(
    internalHosts: ['example.com', 'www.example.com'],
    nofollow: true,
));
~~~

### WikilinksExtension

Parses `[[Page Name]]` and `[[page|Display Text]]` (with optional `#anchor`)
into navigational links, as used in wikis and note tools like Obsidian or
MediaWiki. Cannot be combined with `HeadingReferenceExtension`.

Constructor options:

- `urlGenerator` (`?Closure`, default null) - `fn(string $page): string`. When
  null, the page name is slugified.
- `cssClass` (`string`, default `'wikilink'`).
- `newWindow` (`bool`, default `false`).

~~~ php
$converter->addExtension(new WikilinksExtension(
    urlGenerator: fn (string $page) => '/wiki/' . strtolower(str_replace(' ', '-', $page)) . '.html',
));

$converter->convert('See [[Tiger Facts]]');
// <p>See <a href="/wiki/tiger-facts.html" class="wikilink">Tiger Facts</a></p>

$converter->convert('Learn about [[tigers|big cats]]');
// <p>Learn about <a href="tigers" class="wikilink">big cats</a></p>
~~~

### HeadingReferenceExtension

Resolves `[[Heading Text]]` (and `[[Heading Text|display]]`) to in-document
headings, matching on the heading's text rather than its generated id, so
authors do not have to guess slug rules. HTML output only; with other renderers
the `[[...]]` is rendered as literal text. Cannot be combined with
`WikilinksExtension`.

Constructor options:

- `cssClass` (`string`, default `'heading-ref'`).

~~~ php
$converter->addExtension(new HeadingReferenceExtension());
~~~

### MentionsExtension (default)

Parses `@mentions` and `#tags` as core Carve social syntax. Active by default.
By default both render as non-link spans; pass URL templates with a `{name}`
placeholder to render links instead.

Constructor options:

- `mentionUrl` (`string`, default `''`) - URL template for mentions; empty means
  render a non-link span.
- `tagUrl` (`string`, default `''`) - URL template for tags; empty means a
  non-link span.
- `mentionClass` (`string`, default `'mention'`).
- `tagClass` (`string`, default `'tag'`).

~~~ php
// Default (active out of the box): non-link spans
$converter->convert('Hey @alice, see #release-1.0.');
// <p>Hey <span class="mention"><strong>@alice</strong></span>,
//    see <span class="tag"><strong>#release-1.0</strong></span>.</p>

// Render as links instead (add before the first parse):
$converter->addExtension(new MentionsExtension(
    mentionUrl: '/users/{name}',
    tagUrl: '/tags/{name}',
));
~~~

## Headings

### AsciiHeadingIdsExtension

Folds auto-generated heading ids to ASCII (opt-in). By default Carve heading ids
are lowercased but keep non-ASCII characters verbatim (`# Über uns` ->
`über-uns`). This extension transliterates the slug to ASCII before lowercasing
(`# Über uns` -> `uber-uns`), useful for share-safe URL fragments. Unmapped
scripts (CJK, Arabic, Greek) still pass through unchanged; attach an explicit
`{#id}` for those. The same transform is applied to the parse-time tracker so
implicit `[Heading][]` references resolve to the folded ids.

Constructor options:

- `transliterator` (`?AsciiTransliterator`, default null) - supply a custom
  transliterator; defaults to `new AsciiTransliterator()`.

~~~ php
$converter->addExtension(new AsciiHeadingIdsExtension());
~~~

### HeadingLevelShiftExtension

Shifts heading levels down (h1 -> h2, h2 -> h3, and so on). Useful when h1 is
reserved for the page title. Levels are capped at h6. Works with all renderers.

Constructor options:

- `shift` (`int`, default `1`) - number of levels to shift; clamped to 0-5.

~~~ php
$converter->addExtension(new HeadingLevelShiftExtension(shift: 1)); // h1 -> h2
$converter->addExtension(new HeadingLevelShiftExtension(shift: 2)); // h1 -> h3
~~~

### HeadingPermalinksExtension

Appends (or prepends) a clickable anchor link to each heading so users can link
straight to a section. HTML output only.

Constructor options:

- `symbol` (`string`, default `'¶'`) - the displayed symbol (`'#'`, `'🔗'`, etc.).
- `position` (`string`, default `'after'`) - `'before'` or `'after'`.
- `cssClass` (`string`, default `'permalink'`).
- `ariaLabel` (`string`, default `'Permalink'`).
- `levels` (`array<int>`, default `[1, 2, 3, 4, 5, 6]`) - which levels get a
  permalink.
- `showOnHover` (`bool`, default `false`) - adds a `permalink-hover` class to the
  wrapper for CSS-driven hover reveal.
- `copyToClipboard` (`bool`, default `false`) - adds a `data-permalink-copy`
  attribute for a JS copy-to-clipboard handler.

~~~ php
$converter->addExtension(new HeadingPermalinksExtension(
    symbol: '#',
    position: 'before',
    showOnHover: true,
));
~~~

### TableOfContentsExtension

Extracts headings and builds a structured table of contents. It can auto-insert
the TOC into the output, or expose the data/HTML for custom placement. HTML
output only.

Constructor options:

- `minLevel` (`int`, default `1`) / `maxLevel` (`int`, default `6`) - heading
  level range to include.
- `listType` (`string`, default `'ul'`) - `'ul'` or `'ol'`.
- `cssClass` (`string`, default `'toc'`).
- `position` (`?string`, default null) - `'top'`, `'bottom'`, or null for manual
  placement.
- `separator` (`string`, default `''`) - HTML inserted between TOC and content
  when `position` is set.

~~~ php
$toc = new TableOfContentsExtension(minLevel: 2, maxLevel: 3, position: 'top');
$converter->addExtension($toc);

$html = $converter->convert($djot);

// Or place it yourself:
$tocHtml = $toc->getTocHtml();        // nested list HTML
$tocData = $toc->getToc();            // [['level' => 1, 'text' => '...', 'id' => '...'], ...]
~~~

## Blocks and divs

### AdmonitionExtension

Turns standard Carve divs (`::: note`, `::: warning`, etc.) into semantic
admonition markup with accessibility roles. `warning` and `danger` get
`role="alert"`. A `{title="..."}` attribute overrides the heading.

For disclosure/collapsible widgets use the separate `DetailsExtension`
(`::: details "title"`). This extension does not produce `<details>`; any
`{collapsible}` attribute is passed through as an ordinary HTML attribute.

Constructor options:

- `types` (`array<string>`, default `['note', 'tip', 'warning', 'danger',
  'info', 'success']`).
- `defaultTitle` (`bool`, default `true`) - emit a title from the type when none
  is given.
- `titleTag` (`string`, default `'p'`).
- `titleClass` (`string`, default `'admonition-title'`).
- `containerClass` (`string`, default `'admonition'`).
- `icons` (`array<string,string>|bool`, default `false`) - `true` uses the
  built-in emoji icon set; an array supplies custom per-type icons.
- `iconClass` (`string`, default `'admonition-icon'`).

~~~ php
$converter->addExtension(new AdmonitionExtension(icons: true));
~~~

Input:

~~~
::: note
This is a note.
:::

{title="Watch Out!"}
::: warning
Be careful here.
:::
~~~

### DetailsExtension

Renders `::: details` admonitions as the HTML5 `<details>`/`<summary>`
disclosure widget instead of the default `<div class="details">`. The quoted
title becomes the `<summary>`; a title-less block falls back to
`<summary>Details</summary>` so the widget always has an accessible label.

The summary renders as escaped plain text. Block attributes on the opener
(`{#faq open}`) carry onto the `<details>` tag in source order, matching the
default `<div class="details">` behavior (safe-mode attribute filtering still
applies); only the auto `details` class is dropped because the `<details>` tag
is itself the styling hook. HTML output only.

~~~ php
$converter->addExtension(new DetailsExtension());
~~~

Input:

~~~
::: details "More info"
Hidden until the reader expands it.
:::
~~~

Output:

~~~ html
<details>
  <summary>More info</summary>
  <p>Hidden until the reader expands it.</p>
</details>
~~~

Without the extension the same block renders as the default
`<div class="details"><p class="admonition-title">More info</p>…</div>`. Use
`{open}` to expand the widget by default (`<details open="">`).

### ListTableExtension

Renders `::: list-table` blocks as real HTML `<table>` markup, with the table
authored as a nested list. Because each cell is a list item, cells can hold full
block content (paragraphs, lists, code blocks, …) that the native pipe-table
syntax cannot express.

Each outer list item is a row; each inner list item is a cell:

~~~ php
$converter->addExtension(new ListTableExtension());
~~~

> [!IMPORTANT]
> Attributes go on a **preceding** line, not the `:::` opener. A trailing
> `{...}` on the opener makes the whole block literal in Carve. Use
> `{header-rows=1}` on its own line above `::: list-table`.

Input:

~~~
{header-rows=1}
::: list-table "Quarterly results"
- - Region
  - Notes
- - EMEA
  - Strong quarter.

    Drivers:

    - new logos
    - renewals
:::
~~~

Output:

~~~ html
<table>
  <caption>Quarterly results</caption>
  <thead><tr><th>Region</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td>EMEA</td><td><p>Strong quarter.</p>
<p>Drivers:</p>
<ul>
  <li>new logos</li>
  <li>renewals</li>
</ul></td></tr>
  </tbody>
</table>
~~~

The quoted title becomes the `<caption>` (omitted when absent). Two attributes
control header promotion (both default `0`):

- `header-rows=N` promotes the first `N` rows to `<thead>` with `<th>` cells.
- `header-cols=N` promotes the first `N` cells of **every** row to row-header
  `<th>`.
- The **boolean form** `{header-rows}` (no value) means the first row, the
  common "this table has a header row" case, so you rarely need `=1`. Likewise
  `{header-cols}` promotes the first column. An explicit `=N` still wins, and an
  absent attribute means no header.

A cell whose only content is a single plain paragraph collapses to inline
content (`<td>text</td>`), exactly like a tight list item; a cell with multiple
blocks keeps its `<p>`/`<ul>`/… wrappers (as in the `Strong quarter.` cell
above). This is the core benefit over pipe tables: rich, multi-block cells.

Ragged rows (rows with differing cell counts) are padded with empty `<td>` to
the widest row, so no content is ever silently dropped. Inline markup inside a
cell renders normally (`` `flat` `` becomes `<code>flat</code>`). Block
attributes on the opener carry onto the `<table>` tag in source order (safe-mode
filtering still applies); the structural `title`, `header-rows`, `header-cols`,
and the auto `list-table` class are consumed by the extension and not emitted. A
cell that carries its **own** list-item attributes (id, classes, `key=value`)
carries them onto its `<td>`/`<th>` tag; the computed structural `rowspan`/
`colspan` always win, so an author-written `rowspan`/`colspan` (in any case) on a
cell is dropped rather than duplicated. HTML output only.

If a row is authored without an inner cell list - for example a plain paragraph
row like `- not-a-cell-row` - it cannot become table cells without dropping its
text. The whole block then **defers** to the default renderer and degrades to the
literal `<div class="list-table">` nested list, so the content is preserved
verbatim rather than emitted as empty cells.

#### Spanning cells (`^` rowspan, `<` colspan)

Cells can span rows and columns using the **same continuation markers** Carve's
native pipe tables use, so the output `<table>` matches what an equivalent pipe
table produces:

- A cell whose sole content is a lone `^` merges with the cell **above**
  (rowspan). A rowspan of `N` is the cell plus `N - 1` `^` cells in the
  following rows.
- A cell whose sole content is a lone `<` merges with the cell to the **left**
  (colspan). A colspan of `K` is the cell plus `K - 1` `<` cells (so `colspan=3`
  is `Total`, `<`, `<`).

A cell carrying its **own** attribute block (for example `-{.x} ^`) is never a
span marker - its `^`/`<` content stays literal. This is the same escape pipe
tables use.

Input:

~~~
{header-rows=1}
::: list-table "Sales"
- - Region
  - Q1
  - Q2
- - EMEA
  - 10
  - 12
- - ^
  - 14
  - 16
- - Total
  - <
  - <
:::
~~~

Output:

~~~ html
<table>
  <caption>Sales</caption>
  <thead><tr><th>Region</th><th>Q1</th><th>Q2</th></tr></thead>
  <tbody>
    <tr><td rowspan="2">EMEA</td><td>10</td><td>12</td></tr>
    <tr><td>14</td><td>16</td></tr>
    <tr><td colspan="3">Total</td></tr>
  </tbody>
</table>
~~~

`EMEA`'s cell gets `rowspan="2"` (it plus the `^` below it); `Total` gets
`colspan="3"` (it plus the two `<`). The column count accounts for spans, so a
`colspan=K` cell fills `K` columns and a `rowspan=K` cell reserves its column in
the next `K - 1` rows; a span that overflows the grid is clamped (and a warning
is emitted) rather than corrupting the table. A `^` only continues a cell whose
column also existed in the immediately preceding row - below a ragged row that
omitted that column it renders as a plain empty cell, never a span across the
gap.

A rowspan is clamped at the `<thead>`/`<tbody>` boundary: with `header-rows=N`, a
`^` in a body row whose origin cell sits in the header rows does **not** pull a
rowspan across the row-group boundary (an HTML cell cannot reliably span from
`<thead>` into `<tbody>`). The header cell stays a plain `<th>` and the `^`
degrades to an empty body cell. This is a deliberate divergence from the
equivalent pipe table, which has no such row-group boundary. Rowspans that stay
entirely within the body (or entirely within the header) are unaffected.

> [!NOTE]
> Span resolution matches the pipe table for all well-formed inputs, except for
> the header/body boundary clamp described above. Heavily overlapping markers
> (for example a `^` placed inside the interior of an existing
> rowspan-and-colspan cell) are degenerate and may differ slightly from the
> equivalent pipe table - the same kind of input the native pipe table itself
> resolves ambiguously. Ragged rows are padded with empty `<td>` so the grid
> stays rectangular (this is the existing list-table behavior, unchanged by
> spans).

Without the extension the same block degrades gracefully to the default
`<div class="list-table">` holding the literal nested list, so source is never
lost.

### TabsExtension

Converts a wrapper div with class `tabs` containing child `tab` divs into an
accessible tabbed interface. Tab labels come from the first heading or a
`{label="..."}` attribute; `{selected}` marks the default tab. Supports a
CSS-only mode (no JavaScript) and an ARIA mode with keyboard navigation. HTML
output only.

Constructor options:

- `mode` (`string`, default `'css'`) - `'css'` or `'aria'`.
- `wrapperClass` (`string`, default `'tabs'`).
- `tabClass` (`string`, default `'tabs-panel'`).
- `labelClass` (`string`, default `'tabs-label'`).
- `radioClass` (`string`, default `'tabs-radio'`).
- `idPrefix` (`string`, default `'tabset'`).

~~~ php
$converter->addExtension(new TabsExtension()); // CSS-only
$converter->addExtension(new TabsExtension(mode: 'aria'));
~~~

Input (the outer container uses `::::` so it can hold nested `:::` divs):

~~~
:::: tabs

::: tab
### First Tab

Content for the first tab.
:::

::: tab
### Second Tab

Content for the second tab.
:::

::::
~~~

### CodeGroupExtension

Converts a div with class `code-group` containing several code blocks into a
tabbed code interface, ideal for showing the same step in multiple languages.
Tab labels come from the code fence info using `[Label]` suffix syntax, falling
back to the language name or "Code N". HTML output only.

Constructor options:

- `wrapperClass` (`string`, default `'code-group'`).
- `panelClass` (`string`, default `'code-group-panel'`).
- `labelClass` (`string`, default `'code-group-label'`).
- `radioClass` (`string`, default `'code-group-radio'`).
- `idPrefix` (`string`, default `'codegroup'`).
- `highlighter` (`?Closure`, default null) - `fn(string $code, ?string $lang):
  string` to integrate a syntax highlighter.

~~~ php
$converter->addExtension(new CodeGroupExtension());
~~~

Input:

~~~
::: code-group
``` php [Installation]
composer require php-collective/djot
```

``` bash [NPM]
npm install @example/djot
```
:::
~~~

When deciding between this and `TabsExtension`: use `CodeGroupExtension` for
multiple code blocks with labels from language hints; use `TabsExtension` for
arbitrary content with labels from headings/attributes and optional ARIA mode.

### FencedRenderExtension

Generic client-rendered fenced-block factory. Claims fenced code blocks by
language word and emits one hydration element for a client-side library; the
block body is passed through verbatim (no Carve parsing). Mermaid is just one
preset of this client-hydration shape, generalized so D2, Graphviz, WaveDrom,
ABC, Vega-Lite, Chart.js, etc. need no new code. HTML output only. Tier-3
(opt-in, never corpus-pinned).

Constructor options:

- `language` (`string|array<string>`, required) - fence info word(s) claimed.
- `cssClass` (`string`, default first `language` word) - class on the element.
- `tag` (`string`, default `'div'` for json mode else `'pre'`) - wrapper element.
- `contentMode` (`string`, default `FencedRenderExtension::MODE_TEXT`) - `MODE_TEXT`
  or `MODE_JSON` (see below).
- `wrapInFigure` (`bool`, default `false`) - wrap in `<figure class="{cssClass}-figure">`.
- `figureClass` (`string`, default `'{cssClass}-figure'`).

Content modes:

- **`MODE_TEXT`** (Mermaid, D2, Graphviz, WaveDrom, ABC): body is HTML-escaped
  text inside the wrapper. `&` and `<` are escaped (blocking tag injection), but
  `>` is preserved so arrow syntax (`-->`) survives.

  ~~~
  ``` d2
  a -> b
  ```
  ~~~
  renders as `<pre class="d2">a -> b</pre>`.

- **`MODE_JSON`** (Vega-Lite, Chart.js): body is emitted verbatim inside a
  `<script type="application/json">` (default wrapper `<div>`). Any `</` in the
  body is rewritten to `<\/` so the JSON cannot close the script element early
  (byte-equivalent JSON).

  ~~~
  ``` vega-lite
  {"mark": "bar"}
  ```
  ~~~
  renders as
  `<div class="vega-lite"><script type="application/json">{"mark": "bar"}</script></div>`.

Built-in presets (each a one-line factory): `mermaid()`, `d2()`, `graphviz()`
(claims `dot` + `graphviz`), `wavedrom()`, `abc()`, `vegaLite()`, `chart()`.

~~~ php
use Carve\Extension\FencedRenderExtension;

$converter->addExtension(FencedRenderExtension::mermaid());
$converter->addExtension(FencedRenderExtension::d2());
$converter->addExtension(FencedRenderExtension::vegaLite());
$converter->addExtension(new FencedRenderExtension(language: ['dot', 'graphviz'], cssClass: 'graphviz'));
~~~

The `mermaid()` preset emits `<pre class="mermaid">…</pre>` from a ` ``` mermaid `
fence; you must load Mermaid.js on the page to render the diagrams. It accepts
`wrapInFigure`, `tag`, `cssClass`, and `figureClass` for the same customization
the other text-mode presets allow.

To turn them all on without listing each, `FencedRenderExtension::presets()`
returns every bundled preset instance, and `CarveConverter::addExtensions()`
bulk-registers any iterable of extensions:

~~~ php
$converter->addExtensions([
    ...FencedRenderExtension::presets(),
    new MathBlockExtension(),
]);
~~~

> [!NOTE]
> `presets()` claims every preset fence word (`mermaid`, `d2`, `dot`,
> `graphviz`, `wavedrom`, `abc`, `vega-lite`, `chart`), so a literal code sample
> in one of those languages becomes a hydration element. Register only the
> presets whose client library you actually load if that matters.

#### Client rendering

Carve only emits the marker element (the `class`-tagged `<pre>`, or `<div>` with
a child `<script>`); it never renders the diagram itself. Loading the client-side
library and hydrating each emitted element is the host page's job: read the
element's text (text mode) or its `<script type="application/json">` (json mode)
and hand it to the library. The library to load per built-in preset:

| Preset | Fence word(s) | Mode | Client library |
|--------|---------------|------|----------------|
| `mermaid()` | `mermaid` | text | mermaid.js |
| `d2()` | `d2` | text | the d2 WASM build (`terrastruct/d2`) or the `d2` CLI server-side |
| `graphviz()` | `dot`, `graphviz` | text | viz.js / d3-graphviz |
| `wavedrom()` | `wavedrom` | text | wavedrom.js |
| `abc()` | `abc` | text | abcjs |
| `vegaLite()` | `vega-lite` | json | vega-embed |
| `chart()` | `chart` | json | Chart.js |

(`MathBlockExtension` shares the shape for ` ``` math ` fences; load KaTeX or
MathJax.)

Text-mode hydration reads `textContent` (Graphviz shown):

~~~ js
for (const el of document.querySelectorAll('pre.graphviz')) {
  el.replaceWith(viz.renderSVGElement(el.textContent));
}
~~~

JSON-mode hydration reads the child script (Chart.js shown):

~~~ js
for (const el of document.querySelectorAll('.chart')) {
  const cfg = JSON.parse(el.querySelector('script[type="application/json"]').textContent);
  new Chart(el.appendChild(document.createElement('canvas')), cfg);
}
~~~

#### Custom languages (no preset)

Any library that hydrates from element text or a JSON spec needs **no new PHP** -
register the generic constructor with your own fence word:

~~~ php
// Text mode: a library that reads the element's textContent (e.g. nomnoml).
$converter->addExtension(new FencedRenderExtension(language: 'nomnoml'));
// -> <pre class="nomnoml">…escaped source…</pre>

// JSON mode: a spec-driven library with no preset (e.g. ECharts).
$converter->addExtension(new FencedRenderExtension(
    language: 'echarts',
    contentMode: FencedRenderExtension::MODE_JSON,
));
// -> <div class="echarts"><script type="application/json">{…}</script></div>
~~~

Then hydrate `pre.nomnoml` / `.echarts` on the client exactly as the presets
above. Pass an array as `language` to claim several fence words (aliases), and
set `cssClass` when the wrapper class should differ from the first word.

> [!NOTE]
> Author attributes on the fence (a `{#id .class key=val}` block-attribute line
> above it) are copied onto the wrapper, but get the same treatment the core
> renderer applies to every element: always-on hardening
> (`HtmlRenderer::sanitizeAttributes()`) strips event handlers (`on*`),
> `srcdoc`, `formaction` and neutralizes dangerous URL / `expression()` values
> regardless of safe mode, then safe mode strips any additional names (e.g.
> `style` under strict). Values are HTML-escaped so a quote cannot break out. So
> a `{onclick="..."}` on the fence can never reach the output.

### MathBlockExtension

Renders a fenced code block tagged `math` (a ` ``` math ` fence) as
`<div class="math display">\[ … \]</div>`, the GFM-style block form of Carve's
core `$$` display math. The body is HTML-escaped (`&`, `<`, `>`) and wrapped in
`\[ … \]` for a client-side math engine (KaTeX/MathJax). Non-`math` code blocks
defer to the core renderer. HTML output only.

Constructor options:

- `language` (`string`, default `'math'`) - language tag that marks a block.

~~~ php
$converter->addExtension(new MathBlockExtension());
$converter->addExtension(new MathBlockExtension(language: 'latex'));
~~~

Input / output:

~~~
``` math
x^2
```
~~~

renders as `<div class="math display">\[x^2\]</div>`. A preceding
`{#eq .big data-ref=x}` block-attribute line merges onto the `<div>` - author
classes after the `math display` base, then id and other attributes in source
order:

~~~
{#eq .big data-ref=x}
``` math
x^2
```
~~~

renders as `<div class="math display big" id="eq" data-ref="x">\[x^2\]</div>`.

> [!NOTE]
> Author attributes get the same treatment the core renderer applies to every
> element (and as `FencedRenderExtension`): always-on hardening
> (`HtmlRenderer::sanitizeAttributes()`) strips event handlers (`on*`),
> `srcdoc`, `formaction` and neutralizes dangerous URL / `expression()` values
> regardless of safe mode, then safe mode strips any additional names (e.g.
> `style` under strict). Values are HTML-escaped so a quote cannot break out. So
> a `{onclick="…"}` on a ` ``` math ` fence can never reach the output. This
> matches how core inline `` $`…` `` / display `` $$`…` `` math carry `{...}`.

### SpoilerExtension

Hidden / blurred content revealed on interaction (inline + block). Implements
the standard `spoiler` role from the spec's Extension Registry - no new syntax.

- **Inline** `:spoiler[text]` → `<span class="spoiler">text</span>`. Without the
  extension this stays the generic `<span class="ext-spoiler">text</span>`.
- **Block** `::: spoiler "Title"` → an HTML5 `<details class="spoiler">`
  disclosure (native, keyboard- and screen-reader-accessible); a title-less
  block falls back to `<summary>Spoiler</summary>`. Without the extension this
  stays a plain `<div class="spoiler">`.

~~~ php
$converter->addExtension(new SpoilerExtension());

$converter->convert('Plot: :spoiler[the butler did it].');
// <p>Plot: <span class="spoiler">the butler did it</span>.</p>

$converter->convert("::: spoiler \"Ending\"\nEveryone lives.\n:::");
// <details class="spoiler">
//   <summary>Ending</summary>
//   <p>Everyone lives.</p>
// </details>
~~~

Carve emits only the marker; the blur + reveal is the host's CSS (like
`MermaidExtension`). Author attributes merge onto the output element - the
`spoiler` base class ahead of author classes, then id / key-values - with the
always-on hardening (`HtmlRenderer::sanitizeAttributes()`) plus safe-mode name
filtering and value escaping, so a `{onclick="…"}` can never reach the output.

Recommended host pattern: **blurred until clicked** (hover does not reveal - it
would spoil by accident), content kept in the DOM for screen readers. A `.masked`
variant gives a credit-card / PIN look (every char a dot) - add `.masked` on the
fence (`:spoiler[1234]{.masked}`):

~~~ css
/* Inline: blurred until revealed (the familiar spoiler look). */
.spoiler { filter: blur(.3em); cursor: pointer; border-radius: 3px; padding: 0 .15em;
  background: rgba(127, 127, 127, .14); user-select: none; transition: filter .2s; }
.spoiler.revealed { filter: none; background: transparent; user-select: text; }
/* Variant: masked like a credit-card / PIN field (every char a dot). */
.spoiler.masked { filter: none; -webkit-text-security: disc; }
.spoiler.masked.revealed { -webkit-text-security: none; }
/* Block: blur the body and reveal on click, so it reads as a spoiler rather
   than a bare collapse. Amber accent + eye, distinct from a neutral <details>. */
details.spoiler { border-left: 3px solid #e0af68; border-radius: 6px; padding: 4px 12px; }
details.spoiler > summary { color: #e0af68; cursor: pointer; list-style: none; user-select: none; }
details.spoiler > summary::before { content: "👁 "; }
details.spoiler > *:not(summary) { filter: blur(.4em); transition: filter .25s; }
details.spoiler.revealed > *:not(summary) { filter: none; }
~~~

~~~ js
// Inline: reveal on click / Enter / Space, as an accessible button.
for (const s of document.querySelectorAll('span.spoiler')) {
  s.tabIndex = 0
  s.setAttribute('role', 'button')
  s.setAttribute('aria-label', 'Spoiler, activate to reveal')
  const toggle = () => s.classList.toggle('revealed')
  s.addEventListener('click', toggle)
  s.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle() }
  })
}
// Block: keep <details> open so the body is present, blur it, reveal on click.
for (const d of document.querySelectorAll('details.spoiler')) {
  d.open = true
  d.querySelector('summary').addEventListener('click', (e) => {
    e.preventDefault()
    d.classList.toggle('revealed')
  })
}
~~~

Prefer this blur look, or drop the block JS and let `<details>` collapse natively
(no JS, fully keyboard/screen-reader accessible) - both are valid; the extension
emits the same `<details class="spoiler">` either way.

### FrontmatterExtension (default)

Parses a leading frontmatter block. Active by default. The block opens with
`---` plus a format identifier (`---yaml`, `---toml`, `---json`, ...) to
distinguish it from a thematic break; a bare `---` opening falls back to the
configured default format. The extension exposes the raw content - it does not
interpret it, so use your preferred parser (symfony/yaml, etc.).

Constructor options:

- `defaultFormat` (`string`, default `'yaml'`) - format assumed for a bare `---`
  opening.
- `renderAsComment` (`bool`, default `false`) - emit the frontmatter as an HTML
  comment instead of stripping it.
- `renderCallback` (`?Closure`, default null) - `fn(Frontmatter $fm): string`
  for custom rendering.

~~~ php
// Default behavior is automatic. To read the parsed content, register your
// own instance and keep a reference:
$fm = new FrontmatterExtension();
$converter->addExtension($fm);

$converter->convert($djot);

$frontmatter = $fm->getFrontmatter();
if ($frontmatter !== null) {
    echo $frontmatter->getFormat();  // 'yaml'
    echo $frontmatter->getContent(); // 'title: My Document...'
}

// With a parser:
$metadata = $fm->getParsedContent(
    fn ($content, $format) => $format === 'yaml' ? \Symfony\Component\Yaml\Yaml::parse($content) : null,
);
~~~

Input:

~~~
---yaml
title: My Document
author: John Doe
---

# Document content starts here
~~~

## Inline and spans

### SemanticSpanExtension

Turns spans carrying semantic attributes into proper HTML5 elements:
`{kbd}` -> `<kbd>`, `{dfn}` -> `<dfn>`, `{abbr="..."}` -> `<abbr title="...">`,
`{samp}` -> `<samp>`, `{var}` -> `<var>`. Attributes can be combined, with `dfn`
wrapping inner elements. No constructor options.

~~~ php
$converter->addExtension(new SemanticSpanExtension());

$converter->convert('[Ctrl+C]{kbd}');
// <p><kbd>Ctrl+C</kbd></p>

$converter->convert('[HTML]{abbr="HyperText Markup Language"}');
// <p><abbr title="HyperText Markup Language">HTML</abbr></p>

$converter->convert('[CSS]{dfn abbr="Cascading Style Sheets"}');
// <p><dfn><abbr title="Cascading Style Sheets">CSS</abbr></dfn></p>
~~~

For automatic abbreviation expansion (define once, apply everywhere) use the
built-in `*[HTML]: HyperText Markup Language` definition syntax instead.

### InlineFootnotesExtension

Converts a span with the `.fn` class into an inline footnote, so footnote
content can be written inline rather than in a separate definition block. Inline
footnotes share the same numbering sequence as regular footnotes and appear
together in the footnotes section. The content supports full inline formatting.
HTML output only; for other renderers use
`InlineFootnotesToParenthesesTransform`.

Constructor options:

- `cssClass` (`string`, default `'fn'`) - the class that marks a span as an
  inline footnote.

~~~ php
$converter->addExtension(new InlineFootnotesExtension());

$converter->convert('Some text[An inline footnote]{.fn} continues.');
~~~

### SmartQuotesExtension

Configures locale-specific typographic quote characters. By default the parser
uses English quotes; this extension switches them per locale (German low/high,
French guillemets, etc.) while keeping apostrophes as U+2019 regardless of
locale.

Constructor options:

- `locale` (`?string`, default null -> `'en'`) - locale code such as `'de'`,
  `'fr'`, `'de-CH'`. Built-in locales include en, de, de-CH, fr, pl, ru, ja, zh,
  sv, da, fi, cs, hu, it, es, pt, nl, nb, nn, uk.
- `openDoubleQuote` / `closeDoubleQuote` / `openSingleQuote` /
  `closeSingleQuote` (`?string`, default null) - explicit overrides that take
  precedence over the locale.

~~~ php
$converter->addExtension(new SmartQuotesExtension(locale: 'de'));

// Or explicit characters:
$converter->addExtension(new SmartQuotesExtension(
    openDoubleQuote: "\u{00AB}",
    closeDoubleQuote: "\u{00BB}",
));
~~~

## Lists

### PlusBulletExtension

By default Carve does not treat `+` as a bullet marker; it is reserved as the
list-continuation marker. `PlusBulletExtension` re-enables `+` alongside `-` and
`*`, with one deliberate difference: a `+` is only a bullet when followed by a
space and non-empty content. A content-less `+` (bare, or trailing whitespace
only) stays the continuation marker, so the two never collide. `+ +` follows the
same first-block-item syntax as `- +` / `* +` (the trailing `+` is the
first-block sentinel), not a literal `+` item.

~~~ php
use Carve\CarveConverter;
use Carve\Extension\PlusBulletExtension;

$converter = new CarveConverter();
$converter->addExtension(new PlusBulletExtension());

$converter->convert("+ Apple\n+ Banana\n"); // <ul><li>Apple</li><li>Banana</li></ul>
$converter->convert("+ [ ] todo\n");         // task list item
$converter->convert("+\n");                  // <p>+</p> - still the continuation marker
~~~

## Output post-processing

### DefaultAttributesExtension

Adds default attributes to elements by type (CSS classes, lazy loading, etc.).
Defaults are only applied when the element does not already have that attribute;
`class` values are merged rather than overwritten. No-op when given an empty map.

Constructor options:

- `defaults` (`array<string, array<string, string>>`, default `[]`) - map of
  element type (snake_case) to attributes. Supported types include block:
  `paragraph`, `heading`, `code_block`, `block_quote`, `list`, `list_item`,
  `table`, `table_cell`, `div`, `thematic_break`; inline: `link`, `image`,
  `emphasis`, `strong`, `code`, `span`, `subscript`, `superscript`, `footnote`,
  `footnote_ref`.

~~~ php
$converter->addExtension(new DefaultAttributesExtension([
    'image' => ['loading' => 'lazy', 'decoding' => 'async'],
    'table' => ['class' => 'table table-striped'],
    'code_block' => ['class' => 'highlight'],
]));
~~~

### TabNormalizeExtension

Expands literal tabs in code content to a fixed number of spaces at render time.
Carve preserves literal tabs by default (tab display is a CSS `tab-size`
concern); add this for fixed-width output without CSS (email, RSS, plain HTML).
Flat replacement (no elastic tab stops); only code content is touched. HTML
output only.

Constructor options:

- `width` (`int`, default `2`) - spaces per tab.

~~~ php
$converter->addExtension(new TabNormalizeExtension());          // 2 spaces
$converter->addExtension(new TabNormalizeExtension(width: 4));  // 4 spaces
~~~
