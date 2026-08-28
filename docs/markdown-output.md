# Markdown output options


`MarkdownRenderer` has three fluent setters. Build the renderer yourself and hand
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
  their `{#id .class data-*}` by default (`AttributeFallback::Drop`), which is
  right for human-facing export. `AttributeFallback::Html` keeps them as raw
  HTML instead - a `<div ...>` wrapper with blank lines around its
  Markdown-rendered body, and an `<img ...>` tag - the way an inline `{=mark=}`
  already degrades to `<mark>`. Use it when the Markdown is an interchange
  format rather than a rendering. Attribute names and values are validated and
  escaped by the same code the HTML target uses, so event handlers, injection
  sinks and denylisted URL schemes are dropped there too.

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

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
