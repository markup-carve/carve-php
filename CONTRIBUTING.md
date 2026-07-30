# Contributing to carve-php

Thanks for your interest in contributing.

## Getting Started

```bash
git clone https://github.com/markup-carve/carve-php.git
cd carve-php
composer install

# The spec corpus is a submodule; the conformance tests need it.
git submodule update --init
```

Without the submodule, corpus-driven tests fail with "the corpus was not found"
rather than skipping.

## Development Workflow

### Running Tests

```bash
composer test                                  # phpunit, the whole suite
vendor/bin/phpunit tests/TestCase/CarveConverterTest.php
vendor/bin/phpunit --filter testHeading
```

There is a single `default` suite covering `tests/`. Part of it is driven by the
shared spec corpus in `tests/spec`, so a spec bump can change expectations
without any local edit.

### Code Style

PHP Collective coding standards:

```bash
composer cs-check
composer cs-fix     # phpcbf, fixes most findings automatically
```

### Static Analysis

```bash
composer stan       # phpstan, level 9
```

### Everything at once

```bash
composer check      # cs-check + test
```

### Fuzzing

```bash
composer fuzz         # php-fuzzer against fuzz/target.php
composer fuzz-strict  # the strict-profile target
```

Worth running when you touch the parser: crashes and infinite loops surface here
long before anyone files them.

## Project Layout

```
src/
├── CarveConverter.php   # main entry point (parse / render / convert)
├── Parser/              # block and inline parsing
├── Node/                # AST node types (Block/, Inline/)
├── Renderer/            # HTML, Markdown, Carve, ANSI, PlainText
├── Converter/           # Markdown/HTML/Djot/Bbcode to Carve
├── Extension/           # opt-in extensions
├── Transform/           # AST transformers
├── Lint/                # linting
├── Profile.php          # allowed constructs for untrusted input
├── SafeMode.php         # raw HTML / URL / attribute handling
└── LinkPolicy.php       # link destination rules
```

## Writing Tests

Match the shape of the surrounding test class:

```php
public function testHeadingRendersAsH1(): void
{
    $document = $this->converter->parse('# Title');

    $this->assertSame("<h1>Title</h1>\n", $this->renderer->render($document));
}
```

Two conventions worth knowing:

- Prefer `assertSame()` over `assertEquals()` for rendered strings.
- If your change affects behavior documented in `docs/`, add or extend the test
  that pins that documentation - see `tests/TestCase/Documentation/` for the
  pattern. A wrong security doc is worse than a missing one.

## Spec Changes

This repository implements the language; it does not define it. Syntax and
semantics live in [markup-carve/carve](https://github.com/markup-carve/carve)
(`resources/grammar.ebnf` plus the corpus). If a change would alter what valid
Carve means, open the discussion there first - the other implementations
(carve-js, carve-rs, carve-rb, carve-py, carve-go) are held to the same corpus.

## Pull Requests

- One logical change per PR, with a test that fails without it.
- `composer check` and `composer stan` green.
- Say what behavior changed in the description; the commit body is where the
  reasoning belongs.

## Sandbox

The [Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) runs this
implementation live, which makes it a quick way to reproduce a parsing question
before filing it.
