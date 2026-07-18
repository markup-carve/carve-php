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
~~~

`--html` / `--markdown` (`--md`) / `--plain` (`--plain-text`) / `--ansi` select
the format. `-o FILE` writes to a file; `-w`/`--warnings` and `--strict` report
parse warnings (exit 1 under `--strict`); `-x`/`--xhtml` and `-s`/`--safe` apply
to HTML output only. Run `bin/carve --help` for the full list.

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

## License

MIT — see [LICENSE](LICENSE).
