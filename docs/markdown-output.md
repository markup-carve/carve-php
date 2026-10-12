# Markdown output options

`MarkdownRenderer` has four fluent setters. Build the renderer yourself and hand
it to `CarveConverter::create()` to use them:

~~~ php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AttributeFallback;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use MarkupCarve\Carve\Renderer\SoftBreakMode;

$renderer = (new MarkdownRenderer())
    ->setSoftBreakMode(SoftBreakMode::Space)
    ->setSmartTypography(SmartTypographyMode::Source)
    ->setAttributeFallback(AttributeFallback::Html);

$markdown = CarveConverter::create(null, $renderer)->convert($carveSource);
~~~

- `setSoftBreakMode()`: a soft line break inside a paragraph becomes a newline
  (`SoftBreakMode::Newline`, the default), a space (`::Space`), or a hard break
  (`::Break`).
- `setSmartTypography()`: smart typography renders as the resolved glyph
  (`SmartTypographyMode::Glyph`, the default) or as the author's source run
  (`::Source`). Source mode suits output a machine reads, where `...` and `--`
  should stay what the author typed. `HtmlRenderer` also exposes the mode back
  through `getSmartTypography()`, which an extension that builds its own display
  text from a heading - a table of contents, say - reads so its entries and the
  headings they point at do not disagree.
- `setAttributeFallback()`: Markdown has no block container and no attribute
  syntax on an image, so a `::: class` div and an `![alt](src){.class}` lose
  their `{#id .class data-*}` by default. `AttributeFallback::Html` keeps them as raw
  HTML instead. Strong, emphasis and strike preserve their attributes by
  default with `<strong ...>`, `<em ...>` and `<del ...>` tags. Explicit
  `AttributeFallback::Drop` discards those attributes too. Marks without
  attributes use Markdown delimiters. Containers use a
  `<div ...>` wrapper with blank lines around its
  Markdown-rendered body, and an `<img ...>` tag - the way an inline `{=mark=}`
  already degrades to `<mark>`. Use it when the Markdown is an interchange
  format rather than a rendering. Attribute names and values are validated and
  escaped by the same code the HTML target uses, so event handlers, injection
  sinks and denylisted URL schemes are dropped there too.
- `setRoundTripMode()`: the carrier mode of PART 11 §10s, off by default. See
  [Carrying a dropped container](#carrying-a-dropped-container) below.

The constructor also takes a `symbols` map, the same one `HtmlRenderer` takes,
so one configuration serves both targets. Markdown has no symbol syntax to
resolve into, so a `:name:` symbol keeps its source spelling whether or not the
map names it, and `getSymbols()` returns the map as configured.

~~~ php
$renderer = new MarkdownRenderer(symbols: ['rocket' => '🚀']);
~~~

With the HTML fallback, this Carve source:

~~~
{#c1 .calc data-unit="kWh"}
::: calc
Value 42
:::
~~~

renders to:

~~~ markdown
<div class="calc" id="c1" data-unit="kWh">

Value 42

</div>
~~~

## Carrying a dropped container

Markdown has no container block, so a tab set, an admonition, a columns block, a
disclosure, a spoiler, a named div or a composite figure group reaches the output
as its children alone. Nothing left in the file says the container was there, so
an import cannot return it however good it gets.

`setRoundTripMode(true)` turns on the carrier mode (`--roundtrip` on the CLI),
the same option that adds the carrier attributes to HTML output. The mode brackets
supported dropped containers outside pipe-table cells with HTML comments holding
their Carve opener and closer verbatim.

~~~ php
$renderer = (new MarkdownRenderer())->setRoundTripMode(true);
~~~

This Carve source:

~~~
::: tabs
:::: tab [Overview]
First panel.
::::
:::
~~~

writes:

~~~
<!-- carve: ::: tabs -->
<!-- carve: :::: tab [Overview] -->
**Overview**

First panel.

<!-- carve: :::: -->
<!-- carve: ::: -->
~~~

and `carve migrate --from markdown` on that output returns the tab set. The
import reads the markers whenever they are there and needs no flag of its own.

The mode is opt-in because a Markdown renderer with raw HTML turned off shows the
comment as text. With it off the emitted bytes are the ones this target emits
without it.

An attributed container is two Carve lines, so it is two markers: the preceding
attribute line travels in a marker of its own, directly above its opener. A
container Markdown already spells takes no marker at all - an attributes-only
opener (`::: {.warning}`) is written verbatim, and a list table is written as a
pipe table.

A `-->` inside a payload is written `--\>`, and a backslash the payload itself
carries before such a `>` doubles. Nothing else is escaped: the payload is Carve
source, so Carve's own escape is the one the reader already has.

### A damaged marker set is never guessed

A Markdown editor that deleted one marker, reordered two or left a set unbalanced
has destroyed the structure the markers recorded. The import then reads the file
as ordinary Markdown, comments and all, and the migration report carries one
`carrier-markers-damaged` row with `degraded` fidelity and `fallback` confidence.
There is no partial reconstruction and no per-marker recovery.

### What the mode carries

The markers preserve the container's kind, title, attributes and nesting. A
composite figure's caption travels in a separate marker after its closing fence.
Containers in list items and block quotes use markers at the host's content
column, with the list indentation or quote prefix preserved.

Containers in pipe-table cells receive no markers. A construct with no Carve
opener to carry (`small_caps`, `ruby`) is untouched by this mode.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
