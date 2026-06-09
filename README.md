# carve-php

PHP parser and renderer for [Carve](https://github.com/markup-carve/carve), a post-Markdown lightweight markup language with visual mnemonics and human-centered design.

## Status

Carve's syntax is implemented - the delimiter swaps, table changes, captions, and custom extension syntax - and passes the shared Carve spec corpus.

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
);
~~~

`MatcherContext` exposes definition tables (`getReference()`, `hasFootnote()`,
`getAbbreviation()`) and recursive parse helpers (`parseInlines()`,
`parseBlocks()`). Matchers run by descending `priority`, then registration
order. `addInlinePattern()` and `addBlockPattern()` remain available as regex
sugar over the same matcher contract.

The normative extension contract lives in
[`carve/docs/extensions.md`](https://github.com/markup-carve/carve/blob/main/docs/extensions.md).
Extensions bundled with this package (such as `PlusBulletExtension`) are
documented in [`docs/extensions.md`](docs/extensions.md).

## License

MIT — see [LICENSE](LICENSE).
