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
