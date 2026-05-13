# carve-php

PHP parser and renderer for [Carve](https://github.com/markup-carve/carve), a post-Djot lightweight markup language with visual mnemonics and human-centered design.

> [!WARNING]
> **Alpha — syntax fork in progress.**
> This repository was forked from [`php-collective/djot-php`](https://github.com/php-collective/djot-php) on 2026-05-13 and is being adapted to Carve syntax. While the migration is in flight, the parser still recognises Djot syntax in most places. Do not depend on the API yet.

## Status

The `Carve\` namespace and class names are in place. The actual Carve syntax rules (delimiter swaps, table changes, captions, custom extension syntax) are being applied in tracked steps — see the project roadmap.

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

## License

MIT — see [LICENSE](LICENSE).
