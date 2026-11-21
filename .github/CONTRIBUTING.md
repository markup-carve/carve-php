# Contributing to carve-php

Thanks for your interest in contributing.

## Getting started

```bash
git clone https://github.com/markup-carve/carve-php.git
cd carve-php
composer install
```

The conformance data the tests read is a copy of the specification in `spec/`, at the commit named in `spec/PINNED`.

## Checks

```bash
composer test          # PHPUnit: the corpus, the clause cases and the unit tests
composer stan          # PHPStan level 9
composer cs-check      # code style (composer cs-fix repairs most of it)
composer tables-check  # the generated tables under src/Generated are current
composer check         # cs-check, stan and test
```

`php scripts/generate-tables.php` regenerates `src/Generated/` from `spec/data/`; never edit those files by hand.

## Project layout

- `src/`: the engine. `Carve` is the public entry; the classes listed in `docs/api.md` are the public surface,
  everything else is internal.
- `bin/carve`: the command line.
- `tests/`: PHPUnit tests; `tests/fixtures/` holds the clause and include vectors.
- `spec/`: the pinned conformance corpus, trees and data tables.
- `scripts/`, `bench/`, `oracle/`: generators, gates, benchmarks and the differential tooling against carve-js.
- `docs/`: the library guide, the generated API reference (`php scripts/api-reference.php`) and the migration guide.

## Spec changes

This repository implements the language; it does not define it. Syntax and semantics live in
[markup-carve/carve](https://github.com/markup-carve/carve). If a change would alter what valid Carve means, open the
discussion there first: the other implementations are held to the same corpus, and rendered output is byte-identical
across them by design.

## Pull requests

- One logical change per PR, with a test that fails without it.
- `composer check` green.
- Say what behavior changed in the description; the commit body is where the reasoning belongs.

## Sandbox

The [Carve sandbox](https://sandbox.dereuromark.de/sandbox/carve) runs this implementation live, which makes it a quick
way to reproduce a parsing question before filing it.
