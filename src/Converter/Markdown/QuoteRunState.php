<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

/**
 * List markers and lazy paragraph state for one Markdown quote run.
 *
 * @internal
 */
final class QuoteRunState
{
    /**
     * @var array<string, \MarkupCarve\Carve\Converter\Markdown\ListMarkers>
     */
    public array $markers = [];

    /**
     * @var array{prefix: string, text: string, itemQuote?: string}|null
     */
    public ?array $previous = null;

    public ?string $lazyPrefix = null;

    public function reset(): void
    {
        $this->markers = [];
        $this->clearPrevious();
    }

    public function clearPrevious(): void
    {
        $this->previous = null;
        $this->lazyPrefix = null;
    }
}
