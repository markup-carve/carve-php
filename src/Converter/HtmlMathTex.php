<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMComment;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * @internal
 */
final class HtmlMathTex
{
    /**
     * @var list<string>
     */
    private const ENCODINGS = ['application/x-tex', 'text/x-tex', 'latex'];

    /**
     * @param \DOMElement $node
     * @param list<string> $encodings
     *
     * @return array{tier: int, content: string}
     */
    public static function resolve(DOMElement $node, array $encodings = self::ENCODINGS): array
    {
        foreach ($node->childNodes as $semantics) {
            if (!$semantics instanceof DOMElement || strtolower(HtmlDomLoader::elementName($semantics)) !== 'semantics') {
                continue;
            }
            foreach ($semantics->childNodes as $annotation) {
                if (!$annotation instanceof DOMElement || strtolower(HtmlDomLoader::elementName($annotation)) !== 'annotation') {
                    continue;
                }
                $encoding = strtolower(trim($annotation->getAttribute('encoding')));
                if (!in_array($encoding, $encodings, true)) {
                    continue;
                }
                $content = trim($annotation->textContent);
                if ($content !== '') {
                    return ['tier' => 1, 'content' => $content];
                }
            }
        }

        $alttext = trim($node->getAttribute('alttext'));
        if ($alttext !== '') {
            return ['tier' => 2, 'content' => $alttext];
        }
        $alt = self::hiddenFormulaImageAlt($node);
        if ($alt !== '') {
            return ['tier' => 3, 'content' => $alt];
        }

        return ['tier' => 4, 'content' => ''];
    }

    /**
     * The alt of a formula's fallback image, where the page hid the MathML so
     * the image renders instead (markup-carve/carve#2361). Adjacency alone is
     * no evidence.
     */
    public static function hiddenFormulaImageAlt(DOMElement $math): string
    {
        if (!self::mathIsHidden($math)) {
            return '';
        }
        $image = self::fallbackImage($math);

        return $image === null ? '' : trim($image->getAttribute('alt'));
    }

    /**
     * Whether an `<img>` is the fallback image of a formula that imported as
     * math with the image's alt as its content, so the formula imports once.
     */
    public static function isDroppedFormulaImage(DOMElement $image): bool
    {
        $alt = trim($image->getAttribute('alt'));
        if ($alt === '') {
            return false;
        }
        $previous = self::adjacentElement($image, false);
        if ($previous === null) {
            return false;
        }
        $math = strtolower(HtmlDomLoader::elementName($previous)) === 'math' ? $previous : self::soleMath($previous);
        if ($math === null || self::fallbackImage($math) !== $image) {
            return false;
        }

        return self::resolve($math)['content'] === $alt;
    }

    private static function adjacentElement(DOMNode $node, bool $forward): ?DOMElement
    {
        $sibling = $forward ? $node->nextSibling : $node->previousSibling;
        while ($sibling !== null && self::isBlankOrComment($sibling)) {
            $sibling = $forward ? $sibling->nextSibling : $sibling->previousSibling;
        }

        return $sibling instanceof DOMElement ? $sibling : null;
    }

    /**
     * The `<math>` a `<span>` holds and nothing else.
     */
    private static function soleMath(DOMElement $wrapper): ?DOMElement
    {
        if (strtolower(HtmlDomLoader::elementName($wrapper)) !== 'span') {
            return null;
        }
        $math = null;
        foreach ($wrapper->childNodes as $child) {
            if (self::isBlankOrComment($child)) {
                continue;
            }
            if ($math !== null || !$child instanceof DOMElement || strtolower(HtmlDomLoader::elementName($child)) !== 'math') {
                return null;
            }
            $math = $child;
        }

        return $math;
    }

    /**
     * The `<img>` that renders a formula for a reader without MathML: the
     * next element after the `<math>`, or after a `<span>` holding only it.
     */
    private static function fallbackImage(DOMElement $math): ?DOMElement
    {
        $found = self::adjacentElement($math, true);
        $wrapper = $math->parentNode;
        if ($found === null && $wrapper instanceof DOMElement && self::soleMath($wrapper) === $math) {
            $found = self::adjacentElement($wrapper, true);
        }

        return $found !== null && strtolower(HtmlDomLoader::elementName($found)) === 'img' ? $found : null;
    }

    private static function mathIsHidden(DOMElement $math): bool
    {
        if (self::hiddenByStyle($math)) {
            return true;
        }
        $wrapper = $math->parentNode;

        return $wrapper instanceof DOMElement && self::soleMath($wrapper) === $math && self::hiddenByStyle($wrapper);
    }

    /**
     * The effective inline `display` is `none`: the last declaration wins, an
     * `!important` one first.
     */
    private static function hiddenByStyle(DOMElement $node): bool
    {
        $display = null;
        $important = false;
        foreach (explode(';', $node->getAttribute('style')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || strtolower(trim($parts[0])) !== 'display') {
                continue;
            }
            $value = strtolower(trim($parts[1]));
            $isImportant = preg_match('/!\s*important$/D', $value) === 1;
            if ($important && !$isImportant) {
                continue;
            }
            $display = trim((string)preg_replace('/!\s*important$/D', '', $value));
            $important = $isImportant;
        }

        return $display === 'none';
    }

    private static function isBlankOrComment(DOMNode $node): bool
    {
        return $node instanceof DOMComment
            || ($node instanceof DOMText && preg_match('/^[ \t\n\r\f]*$/D', $node->textContent) === 1);
    }
}
