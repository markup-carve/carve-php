<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

/**
 * Reused probes while one Markdown inline line is scanned for HTML code.
 *
 * @internal
 */
final class HtmlCodeState
{
    /**
     * @var array<int, true>|null
     */
    public ?array $unchangedByEmphasis = null;

    /**
     * @var array<int>|null
     */
    public ?array $activeLinkOpeners = null;

    public int $nextLinkOpener = 0;
}
