<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Generated\ImportTables;

/**
 * The table "Elements" (C2-M-056, C2-M-057) and the raw content of C2-M-065 to C2-M-067.
 */
final class Elements
{
    /**
     * C2-M-011, C2-M-012: the elements whose node is a block; the head elements are dropped wherever they stand.
     *
     * @var array<string, true>
     */
    private const BLOCKS = [
        'p' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'hr' => true,
        'blockquote' => true,
        'section' => true,
        'div' => true,
        'ul' => true,
        'ol' => true,
        'menu' => true,
        'dl' => true,
        'pre' => true,
        'table' => true,
        'figure' => true,
        'aside' => true,
        'li' => true,
        'dt' => true,
        'dd' => true,
        'caption' => true,
        'figcaption' => true,
        'summary' => true,
        'legend' => true,
        'html' => true,
        'body' => true,
        'head' => true,
        'title' => true,
        'meta' => true,
        'link' => true,
        'base' => true,
    ];

    /**
     * C2-M-058: the elements whose counterpart needs a parent element.
     *
     * @var array<string, array<string>>
     */
    private const NEEDS_PARENT = [
        'li' => ['ul', 'ol', 'menu'],
        'dt' => ['dl', 'div'],
        'dd' => ['dl', 'div'],
        'caption' => ['table'],
        'figcaption' => ['figure'],
        'summary' => ['details'],
        'legend' => ['fieldset'],
    ];

    /**
     * @var array<string, true>
     */
    private const VOID = [
        'area' => true,
        'base' => true,
        'basefont' => true,
        'bgsound' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'frame' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'keygen' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const RAW_TEXT = [
        'style' => true,
        'script' => true,
        'xmp' => true,
        'iframe' => true,
        'noembed' => true,
        'noframes' => true,
        'plaintext' => true,
        'noscript' => true,
    ];

    /**
     * An element of the SVG or MathML namespace is read by the row `*` unless it is `<svg>` or `<math>`.
     */
    public static function treatment(HtmlNode $node): string
    {
        if ($node->foreign && $node->name !== 'svg' && $node->name !== 'math') {
            return ImportTables::ELEMENTS['*'];
        }

        return ImportTables::ELEMENTS[$node->name] ?? ImportTables::ELEMENTS['*'];
    }

    public static function isBlock(HtmlNode $node): bool
    {
        return (!$node->foreign && isset(self::BLOCKS[$node->name])) || self::treatment($node) === 'div';
    }

    public static function isOrphan(HtmlNode $node): bool
    {
        $parents = self::NEEDS_PARENT[$node->name] ?? null;
        if ($parents === null || $node->foreign) {
            return false;
        }
        $parent = $node->parent;
        if ($parent === null || !$parent->isElement() || !in_array($parent->name, $parents, true)) {
            return true;
        }

        // C2-M-036: a term or description stands in a `<dl>` or in a `<div>` group of one.
        return $parent->name === 'div' && $parent->parent?->name !== 'dl';
    }

    /**
     * C2-H-186: a span whose `data-carve-raw` is read: a raw format name, only text inside and no other attribute.
     */
    public static function isRawSpan(HtmlNode $node): bool
    {
        $format = $node->attr('data-carve-raw');
        if (!$node->isElement('span') || $format === null || !self::isRawFormat($format) || count($node->attrs) !== 1) {
            return false;
        }
        foreach ($node->children as $child) {
            if (!$child->isText()) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-B-225: an ASCII letter, then ASCII letters, digits, `_` and `-`.
     */
    public static function isRawFormat(string $value): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $value) === 1;
    }

    /**
     * C2-M-065: a comment, a raw element or a raw span stands among blocks on its own or in a run of inline content.
     */
    public static function isRaw(HtmlNode $node): bool
    {
        return $node->isComment() || ($node->isElement() && (self::treatment($node) === 'raw' || self::isRawSpan($node)));
    }

    /**
     * C2-M-066: with `rawHtml` off a comment, a raw element or a raw span is dropped with its content, and reported.
     */
    public static function keepsRaw(HtmlNode $node, ImportContext $context): bool
    {
        if ($context->rawHtml) {
            return true;
        }
        $context->report($node, 'import-element-dropped', 'Raw content dropped with rawHtml off');

        return false;
    }

    /**
     * C2-M-057: an element of the treatment `drop` is reported unless its row says otherwise.
     */
    public static function drop(HtmlNode $node, ImportContext $context): void
    {
        if ($node->name !== 'rp') {
            $context->report($node, 'import-element-dropped', 'HTML ' . $node->name . ' dropped');
        }
    }

    /**
     * C2-M-065, C2-M-067: the outer HTML of a comment or element as the HTML Standard serializes it, without the
     * reserved names.
     */
    public static function outer(HtmlNode $node): string
    {
        if ($node->isComment()) {
            return '<!--' . $node->name . '-->';
        }
        if ($node->isText()) {
            $parent = $node->parent;
            if ($parent !== null && !$parent->foreign && isset(self::RAW_TEXT[$parent->name])) {
                return $node->name;
            }

            return str_replace(['&', "\u{00A0}", '<', '>'], ['&amp;', '&nbsp;', '&lt;', '&gt;'], $node->name);
        }
        if (!$node->isElement()) {
            return '';
        }
        $out = '<' . $node->name;
        foreach ($node->attrs as $name => $value) {
            if (!Attrs::isCarrier((string)$name)) {
                $out .= ' ' . $name . '="' . str_replace(['&', "\u{00A0}", '"'], ['&amp;', '&nbsp;', '&quot;'], $value) . '"';
            }
        }
        $out .= '>';
        if (!$node->foreign && isset(self::VOID[$node->name])) {
            return $out;
        }
        foreach ($node->content ?? $node->children as $child) {
            $out .= self::outer($child);
        }

        return $out . '</' . $node->name . '>';
    }
}
