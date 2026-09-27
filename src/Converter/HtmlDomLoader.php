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
        $html = self::processingInstructionsAsComments($html);
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

    private static function processingInstructionsAsComments(string $html): string
    {
        $length = strlen($html);
        $out = '';
        $copied = 0;
        $offset = 0;
        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $offset++;

                continue;
            }
            if (substr_compare($html, '<!--', $offset, 4) === 0) {
                $end = strpos($html, '-->', $offset + 4);
                $offset = $end === false ? $length : $end + 3;

                continue;
            }
            if (substr_compare($html, '<?', $offset, 2) === 0) {
                $end = strpos($html, '>', $offset + 2);
                if ($end === false) {
                    break;
                }
                $data = substr($html, $offset + 1, $end - $offset - 1);
                if (!str_contains($data, '--') && !str_ends_with($data, '-')) {
                    $out .= substr($html, $copied, $offset - $copied) . '<!--' . $data . '-->';
                    $copied = $end + 1;
                }
                $offset = $end + 1;

                continue;
            }
            if (preg_match('/\G<([a-z][^ \t\n\r\f\/>]*)/i', $html, $match, 0, $offset) !== 1) {
                $offset++;

                continue;
            }
            $tag = strtolower($match[1]);
            $offset += strlen($match[0]);
            while ($offset < $length && $html[$offset] !== '>') {
                if ($html[$offset++] !== '=') {
                    continue;
                }
                while ($offset < $length && str_contains(" \t\n\r\f", $html[$offset])) {
                    $offset++;
                }
                if ($offset < $length && ($html[$offset] === '"' || $html[$offset] === "'")) {
                    $quote = $html[$offset++];
                    while ($offset < $length && $html[$offset] !== $quote) {
                        $offset++;
                    }
                    if ($offset < $length) {
                        $offset++;
                    }
                } else {
                    while ($offset < $length && !str_contains(" \t\n\r\f>", $html[$offset])) {
                        $offset++;
                    }
                }
            }
            if ($offset < $length) {
                $offset++;
            }
            if ($tag === 'plaintext') {
                break;
            }
            if (
                in_array($tag, [
                    'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript',
                ], true)
            ) {
                if (preg_match('~</' . $tag . '(?=[ \t\n\r\f/>])~i', $html, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    break;
                }
                $offset = $match[0][1] + strlen($match[0][0]);
            }
        }

        return $out . substr($html, $copied);
    }
}
