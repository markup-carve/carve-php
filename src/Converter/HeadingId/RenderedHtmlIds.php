<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\HeadingId;

/**
 * Heading ids scraped from the already-published HTML of the Djot document.
 *
 * Preserves the published IDs, including custom or older renderer output.
 * Prefer this source when the published HTML is available.
 *
 * Example:
 *   $carve = (new DjotToCarve())
 *       ->preserveHeadingIds(new RenderedHtmlIds(file_get_contents('page.html')))
 *       ->convert($djotSource);
 */
final class RenderedHtmlIds implements HeadingIdSource
{
    public function __construct(protected string $html)
    {
    }

    /**
     * @return array<int, string>
     */
    public function idsInOrder(string $djotSource): array
    {
        return HtmlHeadingIds::extract($this->html);
    }
}
