# carve-php

[![CI](https://img.shields.io/github/actions/workflow/status/markup-carve/carve-php/ci.yml?branch=main&style=flat-square)](https://github.com/markup-carve/carve-php/actions)
[![Coverage](https://codecov.io/gh/markup-carve/carve-php/branch/main/graph/badge.svg)](https://codecov.io/gh/markup-carve/carve-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![Total Downloads](https://img.shields.io/packagist/dt/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)

PHP parser and renderer for [Carve](https://markup-carve.github.io/carve/), a
lightweight markup language for readable source and structured documents.

Implements **Carve spec 0.1** (see [Versioning & Changelog](https://markup-carve.github.io/carve/versioning)).

## Installation

~~~ bash
composer require markup-carve/carve-php
~~~

HTML import and HTML heading-ID conversion require the PHP DOM extension
(`ext-dom`). Core Carve parsing and rendering do not require it.

## Usage

~~~ php
use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
$html = $converter->convert('# Hello /Carve/');
~~~

The converter also renders Markdown, plain text and ANSI. PHP integration
options are in the [PHP guides](https://github.com/markup-carve/carve-php/blob/main/docs/README.md).


### Straight quotes with smart typography

Set `SmartTypographyMode::QuotesSource` with `setSmartTypography()` on an
HTML, Markdown, plain-text, or ANSI renderer. It emits source runs for quotes
and apostrophes while keeping other smart substitutions enabled. The
`CarveConverter` constructor also accepts this mode as `smartTypography`.
`Glyph` remains the default and `Source` still emits every source run.
Parsing and heading IDs are unchanged; typed curly quotes and escapes keep
their existing behavior. The CLI accepts `--smart-typography quotes-source`.

## CLI

~~~ sh
vendor/bin/carve README.crv > README.html   # render (HTML by default)
vendor/bin/carve --markdown README.crv      # or --plain, --ansi, --json
vendor/bin/carve lint README.crv            # report problems, change nothing
vendor/bin/carve migrate --from html p.html # convert into Carve
~~~

See the [CLI guide](https://github.com/markup-carve/carve-php/blob/main/docs/cli.md) for all subcommands and flags.

## Sandbox

Try this implementation live in the
[Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) - explore syntax
and extensions, inspect output, and share snippets via pastebin-style links. It
also powers the [wp-carve](https://github.com/markup-carve/wp-carve) WordPress
plugin.

## Untrusted input

Use the [safe rendering options](https://github.com/markup-carve/carve-php/blob/main/docs/security.md) for untrusted documents.
They disable raw HTML passthrough and bound nesting depth.

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/): syntax, examples, optional features and format conversion.
- [PHP guides](https://github.com/markup-carve/carve-php/blob/main/docs/README.md): configuration, importers, output formats and editor integration.
- [Developer documentation](https://github.com/markup-carve/carve-php/blob/main/docs/development.md): contributing, custom renderers, parser internals and benchmarks.

Table body partitions and source attributes are documented in
[Table source metadata](docs/table-source-metadata.md).
