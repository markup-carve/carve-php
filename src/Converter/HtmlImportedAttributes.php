<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * Attributes and reporting decisions from one element.
 *
 * @internal
 *
 * @phpstan-import-type Attrs from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 */
final class HtmlImportedAttributes
{
    /**
     * @phpstan-param Attrs $attrs
     *
     * @param array $attrs
     * @param bool $urlListCarrier
     */
    public function __construct(
        public readonly array $attrs,
        public readonly bool $urlListCarrier,
    ) {
    }
}
