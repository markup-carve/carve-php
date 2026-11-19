# Standard extensions (provisional)

The standard extensions of the 0.2 catalog, ported from the carve-js reference. They follow `extensions.md` of the
specification and may change in 0.2.x. The core never loads them: a host registers each one it wants.

Each factory returns an `Extension\Bundle`. Pass it in the `extensions` option of `parse`, `render`, `format`,
`write` and `decodeTree`; pass `$bundle->transforms()` to `Carve::renderWithDocumentTransforms()` when the bundle
has any. `Bundle::merge(...)` combines bundles, and two claims of one name fail with `OptionError` (C2-X-014).
`$bundle->withoutRenderers()` keeps the claims and writes every fallback (C2-X-035).

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\CodeCallouts;
use MarkupCarve\Carve\Extension\CodeGroup;
use MarkupCarve\Carve\Extension\FencedRender;
use MarkupCarve\Carve\Extension\MathBlock;

$all = Bundle::merge(FencedRender::presets(), MathBlock::bundle(), CodeGroup::bundle(), CodeCallouts::bundle());
$html = Carve::renderWithDocumentTransforms($source, $all->transforms(), ['extensions' => [$all]])->html;
```

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
$bundle = FencedRender::mermaid(build: fn (string $source): string => $svgFor($source));
Carve::render($source, ['extensions' => [$bundle], 'mode' => 'static']);
```

## Math block

`MathBlock::bundle(language: 'math', build: null)` renders the fence as `<div class="math display">\[…\]</div>`,
the delimiters KaTeX and MathJax read. `build(string $tex, bool $display)` registers the static channel.

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

`ListTable::bundle()` claims a `::: list-table` div whose body is one list of rows, each row a list of cells. A cell
of only `<` spans from the left, `^` from above. The div attributes `header-rows`, `header-cols`, `footer-rows`,
`aligns`, `valigns` and `widths` shape the table; a cell takes `header`, `header-row`, `align` and `valign`. The
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
Carve::render("\"Hi\" it's 'x'", ['extensions' => [SmartQuotes::bundle('de')]]);
// <p>„Hi“ it’s ‚x‘</p>
```

## Document transforms

`Transforms` holds three bundles with one document transform each and no definitions:

- `defaultAttributes(['heading' => ['class' => 'h', 'data-a' => '1']])`: default attributes per node type, under
  the authored ones (`class` adds classes, `id` sets a missing id, other keys a missing pair).
- `tabNormalize($width = 2)`: each tab in code blocks and code spans becomes `$width` spaces (0 to 16).
- `inlineFootnotesToParentheses()`: each inline footnote becomes its content in parentheses after a space.
