# Contributing to carve-php

Thank you for your interest in contributing to carve-php, the PHP implementation of
[Carve](https://github.com/markup-carve/carve).

## Getting Started

```bash
# Clone the repository, including the spec submodule the corpus tests need
git clone --recurse-submodules https://github.com/markup-carve/carve-php.git
cd carve-php

# Install dependencies
composer install
```

If you cloned without `--recurse-submodules`, fetch the spec afterwards:

```bash
git submodule update --init tests/spec
```

`tests/spec` is the [carve](https://github.com/markup-carve/carve) spec repository. The
conformance corpus lives there, so `CarveCorpusTest` and `OptionalCorpusTest` are
skipped or fail without it.

## Development Workflow

### Running Tests

```bash
# Run everything
composer test

# Run one test file
vendor/bin/phpunit tests/TestCase/CarveConverterTest.php

# Run one test method (note: `phpunit File.php::testName` is NOT valid)
vendor/bin/phpunit --filter testHeading
```

The suite is a single `default` suite; there is no separate spec suite to opt out of.
The corpus is exercised by ordinary test classes:

| Test | Covers |
|------|--------|
| `tests/CarveCorpusTest.php` | the mandatory spec corpus, byte-for-byte |
| `tests/OptionalCorpusTest.php` | Tier-2 opt-in features, visible as skipped when unimplemented |
| `tests/TestCase/**` | this implementation's own unit and integration tests |

A corpus category this implementation does not yet support is declared rather than
silently absent — see the `IMPLEMENTED` and `KNOWN_GAPS` lists in `CarveCorpusTest`.

### Code Style

This project uses [php-collective/code-sniffer](https://github.com/php-collective/code-sniffer),
configured in `phpcs.xml`.

```bash
# Check code style
composer cs-check

# Auto-fix what can be fixed automatically
composer cs-fix
```

### Static Analysis

```bash
# PHPStan, level 9 (see phpstan.neon)
composer stan
```

### Full Check

```bash
# Code style + tests
composer check
```

Run the checks in this order when you have a change in flight: **phpunit, then
phpstan, then phpcs**. PHPStan catches wrong types and missing properties that would
otherwise make a passing assertion lie, and `cs-fix` can rewrite formatting once the
logic is settled.

### Fuzzing

```bash
composer fuzz          # parser/renderer, lenient
composer fuzz-strict   # additionally asserts the strict invariants
```

## Project Structure

```
src/
├── CarveConverter.php      # Main entry point (parse, convert, toCarve)
├── Parser/
│   ├── BlockParser.php     # Block-level parsing
│   └── InlineParser.php    # Inline content parsing
├── Renderer/
│   ├── HtmlRenderer.php    # HTML output, and the URL/attribute hardening
│   ├── CarveRenderer.php   # Canonical source writer (PART 11)
│   ├── MarkdownRenderer.php
│   ├── PlainTextRenderer.php
│   └── AnsiRenderer.php
├── Node/                   # AST node classes
│   ├── Block/
│   └── Inline/
├── Extension/              # Tier-2 and Tier-3 features
├── Converter/              # HTML-to-Carve, Markdown-to-Carve
├── Transform/              # AST transforms
├── Filter/                 # Profile filtering
├── Lint/                   # carve lint rules
├── Event/                  # Render customization events
├── Exception/
└── Util/
```

## Writing Tests

Tests live in `tests/TestCase/`. Follow the existing patterns:

```php
public function testFeatureName(): void
{
    $carve = 'input text';
    $expected = "<p>expected output</p>\n";

    $this->assertSame($expected, $this->converter->convert($carve));
}
```

Prefer `assertSame` over `assertEquals` for strings — it checks type as well as value.
For partial output:

```php
public function testFeatureContains(): void
{
    $result = $this->converter->convert($carve);

    $this->assertStringContainsString('expected part', $result);
}
```

Two habits matter more here than in most projects:

- **Make the test able to fail.** Revert the fix and watch it go red before you trust
  it. Several bugs in this repo's history survived behind checks that could not fail.
- **Pin behaviour against the spec, not against current output.** If a divergence from
  carve-js or carve-rs is involved, resolve it against `tests/spec/resources/grammar.ebnf`
  and the corpus, not by majority vote between implementations.

## Cross-Implementation Changes

Carve has several implementations, and the HTML output is byte-identical across them
by design. If a change alters rendered output, it usually needs the same change in
[carve-js](https://github.com/markup-carve/carve-js) and
[carve-rs](https://github.com/markup-carve/carve-rs), and sometimes a spec change in
[carve](https://github.com/markup-carve/carve) first. Mention the sibling PRs in your
description.

## Pull Request Guidelines

1. Create a feature branch from `main`
2. Write tests for new functionality
3. Ensure the suite passes: `composer test`
4. Ensure static analysis passes: `composer stan`
5. Ensure code style passes: `composer cs-check`
6. Submit a pull request with a clear description of what changed and why

## Reporting Issues

When reporting bugs, please include:

- PHP version
- Minimal Carve input that reproduces the issue
- Expected output
- Actual output

If the same input behaves differently in carve-js or carve-rs, say so — a
cross-implementation divergence is tracked differently from a single-engine bug.

## Resources

- [Carve specification](https://github.com/markup-carve/carve) — `resources/grammar.ebnf` is normative
- [Carve documentation site](https://markup-carve.github.io/carve/)
- [Extension authoring](docs/extensions.md) — the only doc file in this repo today;
  the rest of the prose lives in `README.md` and on the site above

carve-php is a hard fork of [djot-php](https://github.com/php-collective/djot-php) by
the PHP Collective; see `README.md` for what the fork preserved and `LICENSE` for the
copyright lines that carry over.
