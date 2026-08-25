# carve-php

[![CI](https://img.shields.io/github/actions/workflow/status/markup-carve/carve-php/ci.yml?branch=main&style=flat-square)](https://github.com/markup-carve/carve-php/actions)
[![Coverage](https://codecov.io/gh/markup-carve/carve-php/branch/main/graph/badge.svg)](https://codecov.io/gh/markup-carve/carve-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![Total Downloads](https://img.shields.io/packagist/dt/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)

PHP parser and renderer for [Carve](https://github.com/markup-carve/carve), a post-Markdown lightweight markup language with visual mnemonics and human-centered design.

Implements **Carve spec 0.1** (see [Versioning & Changelog](https://markup-carve.github.io/carve/versioning)).

## Origins

Carve-PHP is a hard fork of [djot-php](https://github.com/php-collective/djot-php) by the PHP Collective. The fork preserves the architecture, AST, renderer pipeline, profiles, and extensions, and replaces Djot's syntax rules with Carve's. The MIT license carries over; copyright lines remain in `LICENSE`.

For the original Djot implementation, use [`php-collective/djot`](https://packagist.org/packages/php-collective/djot) instead.

## Installation

~~~ bash
composer require markup-carve/carve-php
~~~

## Usage

~~~ php
use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
$html = $converter->convert('# Hello /Carve/');
~~~

HTML migration can include an explicit loss report:

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

### Markdown output options

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

### Source-line tracking

For editor previews and scroll sync, enable source-line tracking with
`sourceLines: true`. Rendered HTML block anchors receive a 1-based
`data-source-line` attribute for their start line in the original document.
The attribute is applied to top-level and nested block elements, including
blocks inside block quotes, divs, list items, footnotes, and definition lists,
and to `<li>`, `<dt>`, and `<dd>` elements (endnote `<li>` entries included,
anchored at their definition line). Author-supplied `data-source-line`
attributes are preserved.

~~~ php
$converter = new CarveConverter(sourceLines: true);
$html = $converter->convert("- Item\n\n  More\n");
~~~

~~~ html
<ul data-source-line="1">
  <li data-source-line="1"><p data-source-line="1">Item</p>
<p data-source-line="3">More</p></li>
</ul>
~~~

`data-source-line` is the stable lean source-position tier: the attribute name,
format, 1-based start-line meaning, and block/list/definition scope are frozen.
Richer start/end ranges with columns and byte offsets would be added later as a
separate opt-in option, not folded into this attribute. Any future end
positions must be tight and must not overshoot into separator blank lines.

## CLI

The package ships a `bin/carve` executable that reads Carve from a file or
stdin and writes the rendered output to stdout. HTML is the default; pass a
format flag for another output:

~~~ bash
bin/carve README.crv > README.html   # HTML (default)
bin/carve --markdown README.crv      # Markdown
bin/carve --plain README.crv         # plain text
bin/carve --ansi README.crv          # ANSI-colored terminal text
echo '# Hello' | bin/carve           # render from stdin
bin/carve merge base.crv ours.crv theirs.crv # structural three-way merge
~~~

`AstMerge::merge()` exposes the same conservative merge to applications: it
combines independent field edits, insertions, deletions, and moves, and returns
explicit JSON-Pointer conflicts instead of choosing an ambiguous winner.
`AstPatch::create()` and `AstPatch::apply()` provide position-independent patch
replay. Position metadata is intentionally regenerated after serialization.

`--html` / `--markdown` (`--md`) / `--plain` (`--plain-text`) / `--ansi` select
the format. `--json` (`--ast`) emits the parsed AST instead of rendering it, and
`--from-json` reads an encoded AST instead of Carve source, so a tree can be
produced by one tool and rendered by another. The field names are the ones PART 12
of the spec pins, so a tree from another engine reads correctly - and one this
decoder cannot fully understand is rejected rather than silently decoded into the
wrong document. `--json` asks the parser to track source positions and publishes
them (PART 12 §4); the other formats do not, since tracking costs work on every
parse and only this one publishes the result.
See [`docs/ast-json.md`](docs/ast-json.md). `--stamp-info` and `--stamp-check`
report a document's provenance marker (see below). `-o FILE` writes to a file; `-w`/`--warnings` and `--strict` report
parse warnings (exit 1 under `--strict`); `-x`/`--xhtml` and `-s`/`--safe` apply
to HTML output only. Run `bin/carve --help` for the full list.

## ProseMirror / Tiptap

The AST converts to a ProseMirror document and back, so a Tiptap editor in the
browser and PHP rendering on the server can share one source of truth without a
Node runtime:

~~~ php
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;

$json = (new ProseMirrorRenderer())->renderJson($converter->parse($source));
$document = (new ProseMirrorToCarve())->convertJson($json);
~~~

Node and mark names come from the map published by carve-grammars rather than
being restated here. Types the editor model cannot hold are reported by
`droppedTypes()` and `degradedTypes()` instead of vanishing. Full contract, the
fidelity numbers and the application-node pattern:
[`docs/prosemirror.md`](docs/prosemirror.md).

## Stored documents and spec versions

`carve fmt --stamp` records the spec version a document was last processed under:

~~~
%% carve-version: 0.1; generated-by: carve-php 0.1.5
~~~

That marker is what makes the spec's
[upgrade procedure](https://markup-carve.github.io/carve/versioning) actionable -
when moving a stored document to a newer spec version you only review the
`[behavior]` changelog entries between its stamped version and the target. Read
it back with:

~~~ php
use MarkupCarve\Carve\Stamp;

Stamp::read($source);          // ['version' => '0.1', 'generatedBy' => 'carve-php 0.1.5'] or null
Stamp::needsReview($source);   // true when the document predates this engine's spec version
~~~

An unstamped document answers `needsReview() === true`: its provenance is
unknown, and assuming it is current is the unsafe direction. From the CLI:

~~~ bash
bin/carve --stamp-info doc.crv    # report version and writer
bin/carve --stamp-check doc.crv   # exit 1 when the document predates this spec version
~~~

`--stamp-check` is meant for a repository of stored `.crv` files: run it over the
directory in CI and a document left behind by a spec upgrade fails the build
instead of silently rendering differently.

## Sandbox

Try this implementation live in the
[Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) - explore syntax
and extensions, inspect output, and share snippets via pastebin-style links. It
also powers the [wp-carve](https://github.com/markup-carve/wp-carve) WordPress
plugin.

## Extension Matchers

Carve-PHP supports parse-stage extension matchers alongside render hooks and
document transforms. Matchers are tried only where core syntax declines, so
core parsing always wins first.

~~~ php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\MatcherContext;

$converter = new CarveConverter();

$converter->getParser()->getInlineParser()->addInlineMatcher(
    function (string $text, int $pos, MatcherContext $ctx): ?array {
        if (!preg_match('/\G\{\{([a-z]+)\}\}/', $text, $m, 0, $pos)) {
            return null;
        }

        return ['node' => new Text('VAR:' . $m[1]), 'end' => $pos + strlen($m[0])];
    },
    priority: 0,
    triggerChars: '{', // only run this matcher at a `{`
);
~~~

`MatcherContext` exposes definition tables (`getReference()`, `hasFootnote()`,
`getAbbreviation()`) and recursive parse helpers (`parseInlines()`,
`parseBlocks()`). Matchers run by descending `priority`, then registration
order. `addInlinePattern()` and `addBlockPattern()` remain available as regex
sugar over the same matcher contract.

For a raw-closure `addInlineMatcher()`, pass `triggerChars` (the literal first
bytes the matcher can ever fire on, e.g. `'{'` above) so the parser only invokes
it at those positions. Without it, the matcher runs at **every** scan position
and disables the per-character fast path for the whole document — a measurable
slowdown on long inputs. A matcher registered through `addInlinePattern()`
derives its trigger bytes from the pattern automatically.

The normative extension contract lives in
[`carve/docs/extensions.md`](https://github.com/markup-carve/carve/blob/main/docs/extensions.md).
Extensions bundled with this package (such as `PlusBulletExtension`) are
documented in [`docs/extensions.md`](docs/extensions.md).

## Untrusted input

Raw passthrough renders **verbatim** unless a safe mode is set, so anything you
did not author needs configuring first:

```php
$converter = new CarveConverter(safeMode: SafeMode::strict());
$converter->setProfile(Profile::comment());
```

`SafeMode` governs raw HTML, URL schemes and event-handler attributes; `Profile`
governs which constructs are allowed at all (four presets, per-feature reasons,
length caps) and pairs with `LinkPolicy` for destinations. Full recipe, defaults
table and checklist: [`docs/security.md`](docs/security.md).

## Linting

`carve lint` reports constructs that parse cleanly but do not mean what the
author intended - Markdown habits such as `**bold**` that render as literal
asterisks, semantic span attributes that lose their value (`[x]{kbd="V"}`) or
sit outside a span (`` `c`{kbd} ``), and, per host and off by default, the
at-word and hash-number tokens a platform re-linkifies out of published output:

```sh
carve lint doc.crv
carve lint --platform github doc.crv
```

Rules, options and what each one does and does not read:
[`docs/lint.md`](docs/lint.md).
