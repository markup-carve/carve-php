<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;

/**
 * The four semantic span names core does not reserve, plus the deprecated
 * `:name[…]` spelling for all seven (spec PART 9 §10, docs/extensions.md §11).
 *
 * Core reserves `abbr`, `time` and `kbd` as span attributes: the first two
 * carry data the author would otherwise lose, and the third is the one name
 * every comparable system ships. `samp`, `var`, `cite` and `dfn` carry no data
 * and collide with no core clause, so they are opt-in - a core processor leaves
 * them as ordinary attributes (`<span samp="">x</span>`).
 *
 * `[x]{samp}` renders `<samp>x</samp>`, and `[CSS]{dfn="Cascading Style Sheets"}`
 * renders `<dfn title="Cascading Style Sheets">CSS</dfn>`.
 *
 * THE `:name[…]` SPELLING IS SOFT-DEPRECATED HERE, not revived. It was released
 * behavior in carve-js and carve-rs, so removing it outright would break
 * documents that shipped; it is scheduled for removal in 0.2. Write the span
 * attribute instead - it is the only spelling that can express a combination,
 * since `:dfn[:abbr[CSS]]` does not nest while `[CSS]{dfn abbr="…"}` does.
 *
 * This class was this package's ORIGINAL home for the attribute form, briefly a
 * deprecated no-op while the names sat in core, and is now the specified Tier-2
 * extension all three engines ship.
 */
class SemanticSpanExtension implements ExtensionInterface
{
    /**
     * The four names core does not reserve.
     *
     * @var array<string>
     */
    public const NAMES = ['samp', 'var', 'cite', 'dfn'];

    public function register(CarveConverter $converter): void
    {
        // Declarative: the nesting order, the value mapping and the riding rule
        // live in the renderer, and this names what it adds. A second copy of
        // that logic here is how one feature becomes two that drift.
        $converter->getHtmlRenderer()->addSemanticSpanNames(self::NAMES);
    }
}
