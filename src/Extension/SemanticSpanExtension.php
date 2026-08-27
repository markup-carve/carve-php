<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;

/**
 * The four semantic span names core does not reserve, plus the deprecated
 * `:name[…]` spelling for all seven (spec PART 9 §10, docs/extensions.md §11).
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
