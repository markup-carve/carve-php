# carve-php

PHP parser and renderer for [Carve](https://github.com/markup-carve/carve), a post-Markdown lightweight markup language with visual mnemonics and human-centered design.

## Origins

Carve-PHP is a hard fork of [djot-php](https://github.com/php-collective/djot-php) by the PHP Collective. The fork preserves the architecture, AST, renderer pipeline, profiles, and extensions, and replaces Djot's syntax rules with Carve's. The MIT license carries over; copyright lines remain in `LICENSE`.

For the original Djot implementation, use [`php-collective/djot`](https://packagist.org/packages/php-collective/djot) instead.

## Installation

~~~ bash
composer require markup-carve/carve-php
~~~

## Usage

~~~ php
use Carve\CarveConverter;

$converter = new CarveConverter();
$html = $converter->toHtml('# Hello /Carve/');
~~~

Besides HTML, the same AST renders to Markdown, plain text, and ANSI via the
`CarveConverter::markdown()`, `::plainText()`, and `::ansi()` factories:

~~~ php
$markdown = CarveConverter::markdown()->convert('# Hello /Carve/');
$ansi = CarveConverter::ansi()->convert('# Hello /Carve/');
~~~

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
use Carve\CarveConverter;
use Carve\Node\Inline\Text;
use Carve\Parser\MatcherContext;

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
