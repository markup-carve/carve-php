# Standard extensions (provisional)

The standard extensions of the 0.2 catalog, ported from the carve-js reference. They follow `extensions.md` of the
specification and may change in 0.2.x. The core never loads them: a host registers each one it wants.

Each factory returns an `Extension\Bundle`. Pass it in the `extensions` option of `parse`, `render`, `format`,
`write` and `decodeTree`; pass `$bundle->transforms()` to `Carve::renderWithDocumentTransforms()` when the bundle
has any. `Bundle::merge(...)` combines bundles, and two claims of one name fail with `OptionError` (C2-X-014).
`$bundle->withoutRenderers()` keeps the claims and writes every fallback (C2-X-035).

Every name of the catalog can also be passed as a string in `extensions`. A name with a matcher, as
`carve.codeGroup` or `carve.fencedRender.d2`, writes its fallback without a renderer. A name marked `transform`
(C2-X-072), as `carve.headingPermalinks` or `carve.toc`, runs its document transform with its default options.
Named `carve.tabs` accepts a host renderer option:

```php
use MarkupCarve\Carve\Carve;

$source = ":::: tabs\n::: tab \"First\"\nHello.\n:::\n::::\n";
Carve::render($source, [
    'extensions' => ['carve.tabs'],
    'renderers' => ['carve.tabs' => ['standard' => ['mode' => 'aria']]],
]);
```

`mode` defaults to `css`; `aria` writes a tablist with buttons and named panels. Both modes keep authored
`role`, `aria-label` and `aria-labelledby` on the wrapper before generated defaults.

Named `carve.smartQuotes` takes `locale`, one of the 20 locales of its row with `en` the default, for the quote
glyphs of smart typography: `'renderers' => ['carve.smartQuotes' => ['standard' => ['locale' => 'de']]]` (C2-X-075).

On the command line, `--extensions` registers named standard extensions on `render`, `ast`, `lint` and `fmt`, and
`render` gives each its standard renderer; `--extension-options` sets the renderer options (C2-T-010):

```
vendor/bin/carve render --extensions '["carve.tabs"]' --extension-options '{"carve.tabs":{"mode":"aria"}}' doc.crv
```

Inside this checkout, run the command as `php bin/carve`.

The names are those of `data/extensions.json`: `carve.autolink`, `carve.cite`, `carve.codeCallouts`,
`carve.codeGroup`, `carve.colorSwatch`, `carve.details`, `carve.externalLinks`, `carve.fencedRender.abc`, `.chart`,
`.d2`, `.dot`, `.graphviz`, `.mermaid`, `.plantuml`, `.puml`, `.vega-lite` and `.wavedrom`, `carve.glossary`,
`carve.headingNumbers`, `carve.headingPermalinks`, `carve.headingReference`, `carve.imgFence.image`,
`carve.imgFence.img`, `carve.index`, `carve.indexTerm`, `carve.listTable`, `carve.mathBlock`, `carve.semanticSpan`,
`carve.smartQuotes`, `carve.spoiler`, `carve.spoilerBlock`, `carve.tab`, `carve.tabNormalize`, `carve.tabs`,
`carve.term`, `carve.toc`, `carve.tocPlacement` and `carve.wiki`. `carve.glossary` registers `carve.term` with it, and
`carve.index` registers `carve.indexTerm`. Any other name is a usage error, and so is an options entry for an
extension that `--extensions` does not list. Host extensions stay library-only.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\CodeCallouts;
use MarkupCarve\Carve\Extension\CodeGroup;
use MarkupCarve\Carve\Extension\FencedRender;
use MarkupCarve\Carve\Extension\MathBlock;

$source = "```math\nx^2\n```\n";
$all = Bundle::merge(FencedRender::presets(), MathBlock::bundle(), CodeGroup::bundle(), CodeCallouts::bundle());
$html = Carve::renderWithDocumentTransforms($source, $all->transforms(), ['extensions' => [$all]])->html;
```

## Configuration names

`Extension\Name` is a string-backed enum of the names a configuration file uses for these extensions, the names the
Laravel, Symfony, Tempest and CakePHP adapters accept. `create()` builds the extensions of one name from its options.
An option is snake_case and binds to the factory parameter of the same name in camelCase, so `css_class` is
`$cssClass`; an unknown option or one of the wrong type throws `InvalidArgumentException`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Name;

$extensions = [
    ...Name::CodeCallouts->create(),
    ...Name::ExternalLinks->create(['internal_hosts' => ['example.com']]),
    ...Name::from('table_of_contents')->create(),
];
$html = Carve::render($source, ['extensions' => $extensions]);
```

`tabs` takes `mode` (`css` or `aria`), `plantuml` takes `css_class`, `img_fence` takes `language` (one fence name or a
list), and `wikilinks` takes `url_template` (with `{page}`), `css_class` and `new_window`. `index` is the same as
`index_terms`. `Name::values()` lists every name.

## Fenced renderers

`FencedRender::bundle(language, cssClass, tag, contentMode, wrapInFigure, figureClass, label, build)` claims one or
more fence words. Each word is its own extension, `carve.fencedRender.<word>`, version `1`.

- `contentMode: 'text'` (default) writes the fence body escaped in a `<pre>`; `>` stays, so arrow syntax reaches the
  client library as written. `'json'` writes it in `<script type="application/json">` inside a `<div>`.
- The css class (default: the first word) comes before the author's classes. `role="img"` and an `aria-label`
  (default: the css class; `label: ''` writes neither) are added unless the author wrote their own.
- `build` is a host function from diagram source to self-contained HTML. It registers the static channel
  (C2-X-052): in `mode: static` the output is a `<div>` holding the built HTML. Without `build`, static mode writes
  the fallback code block. The engine makes no network call.

Presets, each taking the other parameters of `bundle()` by name: `mermaid()`, `d2()`, `graphviz()` (`dot`, `graphviz`),
`wavedrom()`, `abc()`, `plantuml()` (`plantuml`, `puml`), `vegaLite()` and `chart()` (json mode).
`presets($builders)` registers all of them; `$builders` maps a preset's css class to its `build` function.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\FencedRender;

$source = "```mermaid\ngraph TD; A-->B\n```\n";
$bundle = FencedRender::mermaid(build: fn (string $source): string => '<svg></svg>');
Carve::render($source, ['extensions' => [$bundle], 'mode' => 'static']);
```

## Math block

`MathBlock::bundle(language: 'math', build: null)` renders the fence as `<div class="math display">\[…\]</div>`,
the delimiters KaTeX and MathJax read. The element has `role="math"`. `build(string $tex, bool $display)`
registers the static channel.

## Code group

`CodeGroup::bundle(mode, wrapperClass, panelClass, labelClass, radioClass, idPrefix, groupLabel, highlighter)` claims
a `::: code-group` div whose body is one or more code blocks; any other body stays a core div.

- `mode: 'css'` (default) writes radio inputs and labels, no script. `'aria'` writes tab buttons bound to panels
  and needs a script to switch them. Static mode writes one `<section>` per panel with an `<h3>` label.
- Panel label: the bracket label, then the quoted title, then the language, then `Code N`. The first block marked
  `{selected}` is selected, the first block when none is.
- The group name is `groupLabel`, else the `codeGroup` label of the `labels` option.
- `highlighter(string $code, ?string $language)` replaces the core code block HTML of a panel.

## Code callouts

`CodeCallouts::bundle()` holds a document transform. A `<n>` that ends a code line becomes a callout bubble
(`carve.codeCallouts.code`), and a paragraph right after the code block whose every line starts with `<n> ` becomes
its list (`carve.codeCallouts.list`). Both channels write the same HTML. A code group keeps a panel whose code block
the transform replaced.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\CodeCallouts;

$bundle = CodeCallouts::bundle();
$result = Carve::renderWithDocumentTransforms("```js\nlet a = 1 <1>\n```\n<1> One\n", $bundle->transforms(), [
    'extensions' => [$bundle],
]);
```

## Details and spoiler

`Details::bundle()` claims `::: details "Title"` (`carve.details`, slots `summary` and `body`) and writes a native
`<details>` with the title as `<summary>` (the `details` label, `Details`, without one). Static mode adds `open`
unless the author wrote it.

`Spoiler::bundle()` claims `:spoiler[text]` (`carve.spoiler`, a `<span class="spoiler">`) and `::: spoiler "Title"`
(`carve.spoilerBlock`, a `<details class="spoiler">`). Static mode reveals both: the span gains `spoiler-revealed`,
and the block becomes a `<section class="spoiler spoiler-revealed">` headed by `<h3 class="spoiler-title">`.

Without a renderer, a titled div falls back to its title as a strong paragraph and then its body.

## List table

`ListTable::bundle()` claims a `::: list-table` div whose body is one list of rows, each row item holding one list of cells and
nothing else; another such div stays a core div. A cell of only `<` spans from the left, `^` from above. The div
attributes `header-rows`, `header-cols`, `footer-rows` (a decimal integer above 0; another value, or none, has no
effect), `aligns`, `valigns` and `widths` shape the table; a cell takes `header`, `header-row`, `align` and `valign`. The
quoted title is the caption, a bracket label a `<p class="div-label">` before the table.

## Color swatch

`ColorSwatch::bundle(position, shape, tint, reveal)` claims `:color[value]` for a hex, `rgb()`/`hsl()` or named
CSS color, and writes a chip beside the value (`position`: `before`, `after` or `none`; `shape`: `square`, `round`
or `ring`). `{contrast}` writes the value on its own color instead. Any other value stays the core span.

## Semantic spans

`SemanticSpan::bundle()` adds the span keys `samp`, `var`, `cite` and `dfn` to the core `abbr`, `time` and `kbd`
(C2-H-075), nesting from inside out in the 0.1 order. A `dfn` value becomes `title`. Lint reads the same keys for
`semantic-attribute-outside-span` and `semantic-attribute-value-ignored`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\SemanticSpan;

Carve::render('[x]{samp} [w]{dfn=term}', ['extensions' => [SemanticSpan::bundle()]]);
// <p><samp>x</samp> <dfn title="term">w</dfn></p>
```

## Wiki links

`Wikilinks::bundle(urlGenerator, cssClass, newWindow)` is the standard `:wiki` matcher (C2-X-057) with a link
renderer. `:wiki[Page#anchor|Display]` links the page through `urlGenerator` (default: a lowercase hyphen slug) and
appends the anchor. A URL the URL policy refuses renders as text. The generated `data-wikilink`, `target` and `rel`
replace authored pairs of those names.

## Smart quotes

`SmartQuotes::bundle(locale, openDoubleQuote, closeDoubleQuote, openSingleQuote, closeSingleQuote)` sets the quote
glyphs smart typography writes; the `smartTypography` option still turns it on. The locale is matched exactly, then
by its language, then falls back to English; `SmartQuotes::locales()` lists the table and
`SmartQuotes::isLocaleSupported()` checks one. Only quote nodes that carry the default glyph of their kind change,
and a closing single quote between two letters stays an apostrophe.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\SmartQuotes;

Carve::render("\"Hi\" it's 'x'", ['extensions' => [SmartQuotes::bundle('de')]]);
// <p>„Hi“ it’s ‚x‘</p>
```

## Document transforms

`Transforms` holds three bundles with one document transform each and no definitions:

- `defaultAttributes(['heading' => ['class' => 'h', 'data-a' => '1']])`: default attributes per node type, under
  the authored ones (`class` adds classes, `id` sets a missing id, other keys a missing pair).
- `tabNormalize($width = 2)`: each tab in code blocks and code spans becomes `$width` spaces (0 to 16).
- `inlineFootnotesToParentheses()`: each inline footnote becomes its content in parentheses after a space.

## Glossary, index and citations

Each of these bundles holds an inline matcher, a document transform that resolves the uses against the whole view
(C2-X-053 to C2-X-055), and a renderer that writes the same core content in both channels. Pass the bundle in
`extensions` and its `transforms()` to `renderWithDocumentTransforms()`. Without the transform every construct keeps
its core reading.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\Citations;
use MarkupCarve\Carve\Extension\Glossary;
use MarkupCarve\Carve\Extension\IndexTerms;

$source = "Use :term[HTTP].\n\n::: glossary\n:: HTTP\n: HyperText Transfer Protocol.\n:::\n";
$all = Bundle::merge(Glossary::bundle(), IndexTerms::bundle(), Citations::bundle());
$html = Carve::renderWithDocumentTransforms($source, $all->transforms(), ['extensions' => [$all]])->html;
```

`Glossary::bundle()`: `:term[word]` (`carve.term`) links to the entry of the same text in a `::: glossary`
description list (`carve.glossary`), matched exactly after whitespace collapse and NFC. Each entry gets a
`gloss-<slug>` id that avoids authored ids; the lists get the class `glossary`. An undefined term is a
`<span class="term">`.

`IndexTerms::bundle(backrefLabel)`: `:index[term]` (`carve.indexTerm`) is an invisible marker with an
`idx-<slug>-<n>` id; a `::: index` directive (`carve.index`) lists every term in code point order, with one back-link
per use named `Back to <term>` (or `backrefLabel`). Markers in notes are not counted, and the output of repeated
index directives is capped as in 0.1. `backrefLabel` defaults to `Back to`, independently of render `labels`.

`Citations::bundle(mode, bibliography)`: `:cite[key; key]` (`carve.cite`, the one implementation of C2-X-058; its
fallback is the key text) resolves against the description list of a `::: references` or `::: bibliography`
directive (`carve.references`), then the decoded CSL-JSON array `bibliography`. `mode` is `numbered` (`[1]`, an
ordered list) or `author-date` (`(Doe 2020)`, a list sorted by author, which reads `author` and `year` from a span
around the key: `:: [doe]{author="Doe" year="2020"}`). The citation attributes `locator`, `prefix`, `suffix` and
`mode` (`suppress-author`, `author-in-text`) shape the text. With a `bibliography`, each use gets an id and each
entry back-links to its uses. A key without an entry keeps its text; without a directive the list goes at the end.

## Navigation

`Navigation` holds nine factories. Each returns one `ExtensionDefinition` with a `documentTransform`, which
`render()`, `renderWithDiagnostics()` and `renderWithDocumentTransforms()` run in registration order on a copy of the
resolved view (C2-X-053). Pass the definitions straight in `extensions`; no transform list is needed.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Navigation;

$source = "= Heading\n";
Carve::render($source, [
    'extensions' => [
        Navigation::headingNumbers(),
        Navigation::headingPermalinks(),
        Navigation::tableOfContents(),
    ],
    'renderers' => ['carve.toc' => 'standard'],
]);
```

`tableOfContents()` and `tocPlacement()` carry no renderer: register it through `renderers`
(`'carve.toc' => 'standard'`, `'carve.tocPlacement' => 'standard'`), in both modes. Without that entry the table is
written as its fallback `<div class="toc">` and the render reports `extension-fallback-rendered` (C2-X-052).

- `tableOfContents(minLevel, maxLevel, listType, cssClass, position, collapsible, summary, open)`: a `carve.toc`
  node of nested links to the top-level headings, at the top or the bottom, written in a `<nav>` named by the
  `tocNav` label, or in a `<details>` when `collapsible`. A table placed at the bottom lands inside the last section,
  since a 0.2 section holds every block after its heading.
- `tocPlacement()`: claims each `::: toc` directive (`carve.tocPlacement`), with the headings window from
  `{depth=N}` or `{from=X to=Y}`; a quoted title names the nav. Like `tableOfContents()`, it lists only the
  top-level headings, the ones a section wraps.
- `headingPermalinks(symbol, cssClass, ariaLabel, levels, prepend, showOnHover, copyToClipboard)`: an anchor to its
  own id in each heading.
- `headingNumbers(minLevel, label, crossref)`: section numbers in `<span class="section-number">`, and cross-references
  `</#id>` written as `Section 1.2 - Title` (`crossref`: `number`, `number-title` or `title`). A number follows
  nesting depth, not the level, so a skipped level repeats no number (`= A`, `=== C`, `== B` is 1, 1.1, 1.2). Only
  the top-level headings, the ones a section wraps, are numbered: headings in a quote, note, footnote or list item,
  with the class `unnumbered`, or above `minLevel` take no number.
- `headingReference(cssClass)`: a class on each reference link a heading resolves (`[Heading][]`). The 0.1
  `[[Heading]]` spelling is gone (C2-X-013).
- `externalLinks(target, rel, nofollow, internalHosts)`: `target` and `rel` on links and autolinks to `http` and
  `https` URLs. Links to a host in `internalHosts` (compared without case) stay as written.
- `linkPolicy(allowedSchemes, deniedSchemes, allowedDomains, deniedDomains, allowExternal, allowInternal, rel,
  baseHost)`: a link, autolink or image the policy refuses is written as its text; links that stay get the `rel`
  values. Hosts are read as a browser reads them (IPv4 number forms, IDNA), and a bare relative path is internal.
  It narrows the core URL policy, never widens it.
- `autolink(allowedSchemes)`: bare URLs and email addresses in text become links; trailing punctuation stays outside.

A transform that edits heading text keeps the heading's id and turns the references the heading resolved into
direct links, since ids and references are resolved again over the edited view (C2-X-055).

## SVG sanitizer

`SvgSanitizer::sanitize($svg, $options)` cleans an SVG string for a host that embeds SVG itself, the same as
carve-js `sanitizeSvg`. It returns `['svg' => ..., 'ok' => ...]`; when `ok` is false the input was not one
well-formed `<svg>` root, `svg` is empty, and the host shows the source as text. A kept output holds only
characters XML allows: raw control characters are dropped and a numeric reference to one is escaped.

The output keeps only presentational elements and attributes: no `<script>`, `<foreignObject>`, `<style>`, event
handlers, external references or active CSS. `SvgSanitizeOptions` (or the same keys in an array) widens that:
`allowLinks` keeps `<a>` with external `href`, `allowExternalImages` keeps `<image>`, `allowAnimation` keeps
`<animate>`, `<animateTransform>`, `<animateMotion>`, `<set>` and `<mpath>`, and `allowStyle` keeps a `style` attribute that cannot fetch or execute.
`urlPolicy` takes the `deny` or `allow` list of the render option of the same name.

```php
use MarkupCarve\Carve\Extension\SvgSanitizer;

$result = SvgSanitizer::sanitize('<svg><script>alert(1)</script><circle r="5"/></svg>');
// ['svg' => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>', 'ok' => true]
```

The img fence (`carve.imgFence.img`) does not need it: it sanitizes its own SVG.
