<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Bounds and modes used by canonical escape verification.
 *
 * @internal
 */
final class CarveWriterGrammar
{
    /**
     * @var string
     */
    public const ESCAPE_MODE_CONSERVATIVE = 'conservative';

    /**
     * How many documents' worth of source one narrowing search may re-parse
     * beyond its probe count. Windowed probes are cheap, so this lets a large
     * document with many independent failing units finish the search, while
     * the total stays linear in the document.
     *
     * @var int
     */
    public const ESCAPE_SEARCH_PARSE_FACTOR = 16;

    /**
     * How many times its probe count a search may probe at most. A probe still
     * walks structures sized by the document, so the count stays logarithmic.
     *
     * @var int
     */
    public const ESCAPE_SEARCH_PROBE_FACTOR = 4;
}
