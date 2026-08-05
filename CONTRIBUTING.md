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
composer test                                  # the everyday suite
composer test-scaling                          # the scaling guards, on their own
composer test-all                              # both
composer test-coverage                         # the everyday suite, with coverage
vendor/bin/phpunit tests/TestCase/CarveConverterTest.php
vendor/bin/phpunit --filter testHeading
```

`composer test` excludes the `scaling` group and runs with `pcov.enabled=0`.
Both matter for how long a local run takes:

- The five `*ScanTest` classes that use `ScalingGuardTrait` are wall-clock
  guards against a reintroduced O(n^2) inline scan. 49 data sets each convert a
  50000-repeat input several times, which is most of the suite's runtime, and
  the measurement only means anything on an unloaded machine. They run in their
  own CI job and via `composer test-scaling`.
- `pcov` instruments every file under `src/` whenever the extension is enabled,
  even when nothing collects coverage. `pcov.enabled` is `PHP_INI_SYSTEM`, so it
  cannot be switched off from `phpunit.xml` or `ini_set()` - only a `-d` flag at
  startup works, which is what the composer scripts pass. `composer test-coverage`
  leaves it on.

There is a single `default` suite covering `tests/`. Part of it is driven by the
shared spec corpus in `tests/spec`, so a spec bump can change expectations
without any local edit. Two classes do that work:

| Test | Covers |
|------|--------|
| `tests/CarveCorpusTest.php` | the mandatory corpus, byte for byte |
| `tests/OptionalCorpusTest.php` | Tier-2 opt-in features |

A corpus category this implementation does not support yet is declared rather
than quietly absent. `CarveCorpusTest::IMPLEMENTED` lists the categories that
must pass; `KNOWN_GAPS` maps a category to the reason it does not, and is empty
today. Implementing a feature therefore means adding its category to
`IMPLEMENTED` (and dropping any `KNOWN_GAPS` entry), not only making the parser
handle it.

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

With a change in flight, run them in this order: **phpunit, then phpstan, then
phpcs**. PHPStan finds wrong types and missing properties that would otherwise
make a passing assertion lie, and `cs-fix` rewrites formatting, so running it
before the logic settles means running it twice.

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
- **Make the test able to fail.** Revert the fix and watch it go red before you
  trust it. Several bugs across the Carve implementations survived behind a check
  that structurally could not see what it was checking. One from this repository:
  the dangerous-scheme probe in `MarkdownRenderer` stripped ASCII whitespace
  only, so a `U+202F`-prefixed `javascript:` URL passed the Markdown target while
  `HtmlRenderer` blanked it - and the HTML tests stayed green throughout
  ([#462](https://github.com/markup-carve/carve-php/pull/462)).
- If your change affects behavior documented in `docs/`, add or extend the test
  that pins that documentation - see `tests/TestCase/Documentation/` for the
  pattern. A wrong security doc is worse than a missing one.

## Spec Changes

This repository implements the language; it does not define it. Syntax and
semantics live in [markup-carve/carve](https://github.com/markup-carve/carve)
(`resources/grammar.ebnf` plus the corpus). If a change would alter what valid
Carve means, open the discussion there first - the other implementations
(carve-js, carve-rs, carve-rb, carve-py, carve-go) are held to the same corpus.

Rendered output is byte-identical across implementations by design, so a change
that alters it is rarely a single-repository change. Expect to pair it with the
same fix in [carve-js](https://github.com/markup-carve/carve-js) and
[carve-rs](https://github.com/markup-carve/carve-rs), and to check whether the
spec actually pins the behavior first - where it does not, all implementations
can agree with each other and still be wrong together, which is how the scheme
bypass above went unnoticed. Link the sibling PRs from your description.

## Pull Requests

- One logical change per PR, with a test that fails without it.
- `composer check` and `composer stan` green.
- Say what behavior changed in the description; the commit body is where the
  reasoning belongs.

## Sandbox

The [Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) runs this
implementation live, which makes it a quick way to reproduce a parsing question
before filing it.
