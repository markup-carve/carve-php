# Bundled extensions

This page documents the extensions shipped with `carve-php`. The normative,
language-level extension contract (taxonomy, matcher/transform/renderer stages,
registration) lives upstream in
[`carve/docs/extensions.md`](https://github.com/markup-carve/carve/blob/main/docs/extensions.md).

## PlusBulletExtension

By default Carve does not treat `+` as a bullet marker; it is reserved as the
list-continuation marker. `PlusBulletExtension` re-enables `+` alongside `-` and
`*`, with one deliberate difference: a `+` is only a bullet when followed by a
space and non-empty content. A content-less `+` (bare, or trailing whitespace
only) stays the continuation marker, so the two never collide. `+ +` follows the
same first-block-item syntax as `- +` / `* +` (the trailing `+` is the
first-block sentinel), not a literal `+` item.

~~~ php
use Carve\CarveConverter;
use Carve\Extension\PlusBulletExtension;

$converter = new CarveConverter();
$converter->addExtension(new PlusBulletExtension());

$converter->convert("+ Apple\n+ Banana\n"); // <ul><li>Apple</li><li>Banana</li></ul>
$converter->convert("+ [ ] todo\n");         // task list item
$converter->convert("+\n");                  // <p>+</p> - still the continuation marker
~~~
