<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Renderer\HtmlRenderer;

/**
 * The library renderer without its URL blanking: the HTML importer's URL policy (C2-M-051) decides and reports every destination.
 */
final class DjotHtmlRenderer extends HtmlRenderer
{
    protected function sanitizeUrlBaseline(string $url): string
    {
        return $url;
    }
}
