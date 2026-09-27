<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use DOMElement;

/**
 * @internal
 */
final class HtmlAttributePolicy
{
    /**
     * @param string $name
     * @param string $mode
     * @param array<string> $skip
     */
    public static function isStripped(string $name, string $mode, array $skip): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'on')
            || $lower === 'srcdoc'
            || $lower === 'formaction'
            || (str_starts_with($lower, 'data-djot-')
                && ($lower !== 'data-djot-ref' || $mode === 'roundtrip'))
            || in_array($name, $skip, true)
            || in_array($lower, $skip, true);
    }

    /**
     * @param \DOMElement $node
     * @param list<string> $skip
     * @param string $mode
     * @param array<string, string> $alignmentClasses
     * @param string|null $alignment
     * @param \Closure(string): bool $derivedRole
     */
    public static function read(
        DOMElement $node,
        array $skip,
        string $mode,
        array $alignmentClasses,
        ?string $alignment,
        Closure $derivedRole,
    ): HtmlImportedAttributes {
        $skip = array_fill_keys(array_map('strtolower', $skip), true);
        $skip['style'] = true;
        $urlListCarrier = false;
        $attrs = [];
        $classes = [];
        $keyValues = [];
        $order = [];
        foreach ($node->attributes as $attribute) {
            $name = strtolower(HtmlDomLoader::attributeName($attribute));
            if (isset($skip[$name]) || self::isStripped($name, $mode, []) || ($name === 'role' && $derivedRole($attribute->value))) {
                continue;
            }
            if ($name === 'id') {
                $attrs['id'] = $attribute->value;

                continue;
            }
            if ($name === 'class') {
                $classes = preg_split('/\s+/', $attribute->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1) {
                continue;
            }
            // A quoted value stops at the line break (markup-carve/carve#2385).
            if (preg_match('/[\r\n]/', $attribute->value) === 1) {
                continue;
            }
            $keyValues[$name] = $attribute->value;
            if (in_array($name, ['srcset', 'imagesrcset', 'ping', 'attributionsrc'], true)) {
                $urlListCarrier = true;
            }
        }
        $tag = strtolower(HtmlDomLoader::elementName($node));
        if ($alignment !== null && !in_array($tag, ['td', 'th'], true)) {
            $alignmentClass = $alignmentClasses[$alignment] ?? null;
            if (is_string($alignmentClass) && $alignmentClass !== '') {
                if (!in_array($alignmentClass, $classes, true)) {
                    $classes[] = $alignmentClass;
                }
            } elseif ($mode !== 'safe') {
                $keyValues['align'] = $alignment;
            }
        }
        if ($classes !== []) {
            $attrs['classes'] = $classes;
        }
        if ($keyValues !== []) {
            $attrs['keyValues'] = $keyValues;
        }
        if (array_key_exists('id', $attrs)) {
            $order[] = '#id';
        }
        if ($classes !== []) {
            $order[] = '.class';
        }
        foreach ($keyValues as $name => $_value) {
            $order[] = $name;
        }
        if ($order !== []) {
            $attrs['order'] = $order;
        }

        return new HtmlImportedAttributes($attrs, $urlListCarrier);
    }
}
