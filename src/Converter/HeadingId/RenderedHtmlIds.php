<?php

declare(strict_types=1);

namespace Carve\Converter\HeadingId;

/**
 * Heading ids scraped from the already-published HTML of the Djot document.
 *
 * The most robust source: the rendered page is the literal truth, whatever
 * produced the ids (stock slug, custom transformer, permalink extension, manual
 * ids, an older renderer version). Prefer this over re-rendering when you have
 * the published output.
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
