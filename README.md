# carve-php

[![CI](https://img.shields.io/github/actions/workflow/status/markup-carve/carve-php/ci.yml?branch=main&style=flat-square)](https://github.com/markup-carve/carve-php/actions)
[![Coverage](https://codecov.io/gh/markup-carve/carve-php/branch/main/graph/badge.svg)](https://codecov.io/gh/markup-carve/carve-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![Total Downloads](https://img.shields.io/packagist/dt/markup-carve/carve-php?style=flat-square)](https://packagist.org/packages/markup-carve/carve-php)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)

carve-php parses and renders Carve in PHP. It implements Carve spec 0.1.
Read the [Carve documentation](https://markup-carve.github.io/carve/) for the language.

## Install

```sh
composer require markup-carve/carve-php
```

## Usage

```php
use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
$document = $converter->parse('# Hello /Carve/');
$html = $converter->render($document);
```

CLI basics:

```sh
vendor/bin/carve README.crv > README.html
vendor/bin/carve lint README.crv
vendor/bin/carve fmt README.crv
vendor/bin/carve migrate --from html page.html
```

### Carve 0.2

The same package carries the Carve 0.2 engine under `MarkupCarve\Carve2`, with its own program `vendor/bin/carve2`:

```php
use MarkupCarve\Carve2\Carve2;

$html = Carve2::render("# Hello /Carve/\n");
```

Read the [0.2 library guide](docs/v2/README.md), the [API reference](docs/v2/api.md) and the
[0.1 to 0.2 migration guide](docs/v2/migration.md).

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/)
- [Sandbox](https://sandbox.dereuromark.de/sandbox/carve)
- [Engine guides](docs/README.md)
- [Security for untrusted input](docs/security.md)
- [Development and contributing](docs/development.md)
