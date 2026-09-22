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

## Usage

~~~ php
use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
$html = $converter->convert('# Hello /Carve/');
~~~

HTML, Markdown, Djot and BBCode can return a versioned migration-fidelity report
through each converter's `convertWithFidelityReport()` method. It uses one
`preserved`, `normalized`, `degraded`, and `dropped` vocabulary while retaining
format-specific diagnostic codes. HTML also has its detailed import report; see
[docs/html-import.md](https://github.com/markup-carve/carve-php/blob/main/docs/html-import.md).
Until Markdown, Djot and BBCode provide construct-level evidence, their reports
fail closed with a `dropped` / `fallback` `fidelity-unverified` diagnostic.
The migration CLI writes this envelope with `--report FILE` (or `--report -`
for stderr), and `--check-loss` exits non-zero for degraded or dropped content.
Opaque raw HTML is degraded even when its bytes survive because it is not
modeled or editable by the importer.

Besides HTML the converter renders Markdown, plain text and ANSI. The
Markdown writer's options are in [docs/markdown-output.md](https://github.com/markup-carve/carve-php/blob/main/docs/markdown-output.md),
and every node can carry its source line - [docs/source-lines.md](https://github.com/markup-carve/carve-php/blob/main/docs/source-lines.md).

A document can pull in other files with `{{ chapter.crv }}`. It is opt-in and
off by default - the core parser performs no file I/O - and the resolver you
supply is the security boundary: [docs/includes.md](https://github.com/markup-carve/carve-php/blob/main/docs/includes.md).

Source-aware tools can prepare stale-safe structured formatting changes through
`CarveConverter::toCarvePatch()`; see the
[source-preserving patch guide](https://github.com/markup-carve/carve-php/blob/main/docs/source-patches.md).

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
- [Source-preserving patches](https://github.com/markup-carve/carve-php/blob/main/docs/source-patches.md) - stale-safe UTF-8 edits.
- [Stored documents](https://github.com/markup-carve/carve-php/blob/main/docs/stored-documents.md) - spec versions and stored content.
- [ProseMirror / Tiptap](https://github.com/markup-carve/carve-php/blob/main/docs/prosemirror.md) - editor interchange.
- [AST JSON](https://github.com/markup-carve/carve-php/blob/main/docs/ast-json.md) - the interchange format.
- [Integrated definition layout](https://github.com/markup-carve/carve-php/blob/main/docs/integrated-definition-layout.md) - collecting and resolving reference, footnote and abbreviation definitions.
- [Configured conversion fast path](https://github.com/markup-carve/carve-php/blob/main/docs/configured-conversion-fast-path.md) - reusing a configured converter.
