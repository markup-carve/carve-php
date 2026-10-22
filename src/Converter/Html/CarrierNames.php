<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Html;

use DOMElement;
use SplObjectStorage;
use WeakMap;

/**
 * The reserved roundtrip carrier names, and the deprecated spelling of each.
 *
 * PART 10 section 13a reserves a SET, not a prefix: a `data-carve-` name this
 * enumeration does not list is an ordinary authored attribute and reaches the
 * document unchanged. `data-carve-stored-source` and `data-carve-tree-kind` are
 * both real author attributes in the suite, which is why asking the prefix was
 * never the same question as asking the set.
 *
 * @internal
 */
final class CarrierNames
{
    /**
     * @var string
     */
    public const PREFIX = 'data-carve-';

    /**
     * The spelling released versions exported, accepted on import only.
     *
     * @var string
     */
    public const LEGACY_PREFIX = 'data-djot-';

    /**
     * Every reserved carrier that carries the prefix, without it.
     *
     * The core inventory of PART 10 section 13a plus the carriers this
     * implementation's extensions write: an admonition's title and kind, a
     * heading reference's target, an inline footnote's payload, and the source
     * level a heading shift records.
     *
     * @var list<string>
     */
    public const SUFFIXES = [
        'abbreviations',
        'admonition-title',
        'admonition-type',
        'autolink',
        'col-widths',
        'caption-number',
        'div',
        'escaped',
        'explicit-id',
        'generated-id',
        'ext',
        'group-generated',
        'fence-content',
        'fence-generated',
        'fence-language',
        'footnote-label',
        'heading-ref',
        'heading-ref-display',
        'id-slot',
        'inline-footnote',
        'inline-footnote-class',
        'inline-footnote-html',
        'label',
        'line-block',
        'raw',
        'ref',
        'selected',
        'source-level',
        'src',
        'substitution',
        'tab-title',
        'tight',
        'xref',
    ];

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return array_values(array_filter(array_map(static fn (string $suffix): string => self::PREFIX . $suffix, self::SUFFIXES), self::isReserved(...)));
    }

    public static function isReserved(string $name): bool
    {
        $lower = self::canonicalName($name);

        return $lower !== 'data-carve-ext' && str_starts_with($lower, self::PREFIX)
            && in_array(substr($lower, strlen(self::PREFIX)), self::SUFFIXES, true);
    }

    public static function isStoredSourceMarker(string $name): bool
    {
        return in_array(strtolower($name), ['data-carve-src', 'data-djot-src'], true);
    }

    public static function canonicalName(string $name): string
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, self::LEGACY_PREFIX)) {
            $canonical = self::PREFIX . substr($lower, strlen(self::LEGACY_PREFIX));
            if (in_array(substr($canonical, strlen(self::PREFIX)), self::SUFFIXES, true)) {
                return $canonical;
            }
        }

        return $lower;
    }

    /**
     * Keyed by element, not node path: a path costs a walk over the element's siblings.
     * The storage holds each element, so its PHP wrapper and identity live as long as the document.
     *
     * @var \WeakMap<\DOMDocument, \SplObjectStorage<\DOMElement, array<string, true>>>|null
     */
    private static ?WeakMap $usedAttributes = null;

    private static function markUsed(DOMElement $element, string $name): void
    {
        $name = self::readName($element, $name);
        $document = $element->ownerDocument;
        if ($document === null || !$element->hasAttribute($name)) {
            return;
        }
        self::$usedAttributes ??= new WeakMap();
        if (!isset(self::$usedAttributes[$document])) {
            /** @var \SplObjectStorage<\DOMElement, array<string, true>> $storage */
            $storage = new SplObjectStorage();
            self::$usedAttributes[$document] = $storage;
        }
        $used = self::$usedAttributes[$document];
        $names = $used[$element] ?? [];
        $names[$name] = true;
        $used[$element] = $names;
    }

    public static function wasUsed(DOMElement $element, string $name): bool
    {
        $document = $element->ownerDocument;
        if ($document === null || !isset(self::$usedAttributes[$document])) {
            return false;
        }
        $used = self::$usedAttributes[$document];

        return isset($used[$element]) && isset($used[$element][$name]);
    }

    /**
     * @param \DOMElement $element
     * @param string $name
     * @param (callable(string): bool)|null $accept
     */
    public static function consumeAttribute(DOMElement $element, string $name, ?callable $accept = null): string
    {
        $value = self::peekAttribute($element, $name);
        if ($accept === null || $accept($value)) {
            self::markUsed($element, $name);
        }

        return $value;
    }

    /**
     * @param \DOMElement $element
     * @param string $name
     * @param (callable(string): bool)|null $accept
     */
    public static function consumePresence(DOMElement $element, string $name, ?callable $accept = null): bool
    {
        self::consumeAttribute($element, $name, $accept);

        return self::peekPresence($element, $name);
    }

    public static function peekAttribute(DOMElement $element, string $name): string
    {
        return $element->getAttribute(self::readName($element, $name));
    }

    public static function peekPresence(DOMElement $element, string $name): bool
    {
        return $element->hasAttribute(self::readName($element, $name));
    }

    private static function readName(DOMElement $element, string $name): string
    {
        // Canonical carriers win without changing the attributes serialized as raw HTML.
        if ($element->hasAttribute($name) || (!self::isReserved($name) && $name !== 'data-carve-ext')) {
            return $name;
        }

        return self::LEGACY_PREFIX . substr($name, strlen(self::PREFIX));
    }
}
