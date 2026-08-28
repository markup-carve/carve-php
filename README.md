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
HTML, Markdown, Djot and BBCode convert in as well. Only the HTML importer
drops anything, and only it takes a mode and a loss report -
see [docs/html-import.md](https://github.com/markup-carve/carve-php/blob/main/docs/html-import.md).

Besides HTML the converter renders Markdown, plain text and ANSI. The
Markdown writer's options are in [docs/markdown-output.md](https://github.com/markup-carve/carve-php/blob/main/docs/markdown-output.md),
and every node can carry its source line - [docs/source-lines.md](https://github.com/markup-carve/carve-php/blob/main/docs/source-lines.md).

## CLI

~~~ sh
vendor/bin/carve README.crv > README.html   # render (HTML by default)
vendor/bin/carve --markdown README.crv      # or --plain, --ansi, --json
vendor/bin/carve lint README.crv            # report problems, change nothing
vendor/bin/carve migrate --from html p.html # convert into Carve
~~~

Every subcommand and flag is in [docs/cli.md](https://github.com/markup-carve/carve-php/blob/main/docs/cli.md).

## Sandbox

Try this implementation live in the
[Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) - explore syntax
and extensions, inspect output, and share snippets via pastebin-style links. It
also powers the [wp-carve](https://github.com/markup-carve/wp-carve) WordPress
plugin.

## ProseMirror / Tiptap

The AST converts to a ProseMirror document and back, so a Tiptap editor in
the browser and PHP rendering on the server share one source of truth with
no Node runtime. See [docs/prosemirror.md](https://github.com/markup-carve/carve-php/blob/main/docs/prosemirror.md).

## Untrusted input

Rendering attacker-controlled Carve needs the safe path, which escapes raw
HTML instead of emitting it and bounds nesting depth. The threat model, the
defaults and the full checklist are in [docs/security.md](https://github.com/markup-carve/carve-php/blob/main/docs/security.md).

## Linting

`carve lint` reports constructs that parse but render differently from what
the author intended. The rules and options are in [docs/lint.md](https://github.com/markup-carve/carve-php/blob/main/docs/lint.md).

## Documentation

- [Importing HTML](https://github.com/markup-carve/carve-php/blob/main/docs/html-import.md) - the loss report and the diagnostic path locator.
- [Extensions](https://github.com/markup-carve/carve-php/blob/main/docs/extensions.md) - the extension set, and writing a parse-stage matcher.
- [Command line](https://github.com/markup-carve/carve-php/blob/main/docs/cli.md) - every subcommand and flag.
- [Untrusted input](https://github.com/markup-carve/carve-php/blob/main/docs/security.md) - the threat model and the safe path.
- [Linting](https://github.com/markup-carve/carve-php/blob/main/docs/lint.md) - the lint rules and options.
- [Markdown output](https://github.com/markup-carve/carve-php/blob/main/docs/markdown-output.md) - the Markdown writer's options.
- [Source-line tracking](https://github.com/markup-carve/carve-php/blob/main/docs/source-lines.md) - carrying source positions on the AST.
- [Stored documents](https://github.com/markup-carve/carve-php/blob/main/docs/stored-documents.md) - spec versions and stored content.
- [ProseMirror / Tiptap](https://github.com/markup-carve/carve-php/blob/main/docs/prosemirror.md) - editor interchange.
- [AST JSON](https://github.com/markup-carve/carve-php/blob/main/docs/ast-json.md) - the interchange format.
- [Integrated definition layout](https://github.com/markup-carve/carve-php/blob/main/docs/integrated-definition-layout.md) - the definition-list layout.
- [Configured conversion fast path](https://github.com/markup-carve/carve-php/blob/main/docs/configured-conversion-fast-path.md) - reusing a configured converter.
