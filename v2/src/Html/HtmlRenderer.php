<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;

/**
 * Renders a resolved tree to HTML (C2-H).
 */
final class HtmlRenderer
{
    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param \MarkupCarve\Carve2\Options $opts
     *
     * @return string
     */
    public static function render(Node $doc, Options $opts): string
    {
        return '';
    }
}
