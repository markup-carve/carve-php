<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;
use RuntimeException;

final class HtmlDomLoader
{
    public static function load(string $html): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('HTML import requires the PHP DOM extension (ext-dom).');
        }
        // The HTML input stream normalizes CR LF and a lone CR to LF before
        // tokenizing; libxml keeps them (carve-php#2497).
        $html = str_replace(["\r\n", "\r"], "\n", $html);
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
