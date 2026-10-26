# Additional package details

Details formerly on the package front page are retained here.

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
vendor/bin/carve migrate --from markdown --directory docs --output docs-carve --check-loss
~~~

The folder command copies assets and writes `docs-carve/migration-report.json`
with files that need review. Copied assets keep their source permissions, and a
converted file is never more readable than its Markdown source.

See the [CLI guide](https://github.com/markup-carve/carve-php/blob/main/docs/cli.md) for all subcommands and flags.

## Sandbox

Try this implementation live in the
[Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) - explore syntax
and extensions, inspect output, and share snippets via pastebin-style links. It
also powers the [wp-carve](https://github.com/markup-carve/wp-carve) WordPress
plugin.

Roundtrip HTML export writes structural carriers that the default HTML importer
reads. Legacy stored-source attributes are consumed and never used as source.

## Untrusted input

Use the [safe rendering options](https://github.com/markup-carve/carve-php/blob/main/docs/security.md) for untrusted documents.
They disable raw HTML passthrough and bound nesting depth.

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/): syntax, examples, optional features and format conversion.
- [PHP guides](https://github.com/markup-carve/carve-php/blob/main/docs/README.md): configuration, importers, output formats and editor integration.
- [Djot image alternative text](djot-image-alt-fidelity.md): multiline values and export diagnostics.
- [Developer documentation](https://github.com/markup-carve/carve-php/blob/main/docs/development.md): contributing, custom renderers, parser internals and benchmarks.
- [Table source metadata](table-source-metadata.md): table body partitions and source attributes.
- [Aligned table formatting](cli.md#aligned-tables): optional `fmt --align-tables` output.
