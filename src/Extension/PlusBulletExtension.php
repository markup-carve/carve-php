<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;

/**
 * Re-enables `+` as a bullet-list marker alongside `-` and `*`.
 *
 * Carve drops `+` as a bullet by default because it is reserved as the
 * list-continuation marker. This optional extension brings it back for
 * Markdown/djot familiarity, with one deliberate difference: a `+` is only a
 * bullet when followed by a space and non-empty content. A content-less `+`
 * (bare or trailing whitespace only) stays the continuation marker, so the two
 * never collide.
 *
 * `+ +` follows the existing first-block-item syntax (`<bullet> +`) just like
 * `- +` and `* +`: the trailing `+` is the first-block sentinel, opening an
 * item whose body is the flush-left block that follows. This is unchanged
 * behavior shared by every bullet marker, not a `+`-specific quirk.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new PlusBulletExtension());
 *
 * // Yields <ul><li>Apple</li><li>Banana</li></ul>
 * $converter->convert("+ Apple\n+ Banana\n");
 *
 * // Yields <p>+</p> -- still the continuation marker, not a list
 * $converter->convert("+\n");
 * ```
 */
class PlusBulletExtension implements ExtensionInterface
{
    public function register(CarveConverter $converter): void
    {
        $converter->getParser()->getListParser()->allowPlusBullet(true);
    }
}
