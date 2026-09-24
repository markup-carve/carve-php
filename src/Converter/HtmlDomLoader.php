<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;

final class HtmlDomLoader
{
    public static function load(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $document->encoding = 'UTF-8';
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
