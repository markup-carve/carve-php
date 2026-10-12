<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;

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
final class HtmlCarrierNames
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
        'escaped',
        'explicit-id',
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
        'raw',
        'ref',
        'selected',
        'source-level',
        'src',
        'tab-title',
        'tight',
    ];

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return array_map(static fn (string $suffix): string => self::PREFIX . $suffix, self::SUFFIXES);
    }

    public static function isReserved(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, self::PREFIX)
            && in_array(substr($lower, strlen(self::PREFIX)), self::SUFFIXES, true);
    }

    public static function isStoredSourceMarker(string $name): bool
    {
        return in_array(strtolower($name), ['data-carve-src', 'data-djot-src'], true);
    }

    /**
     * Rename deprecated carriers except stored-source markers to the canonical spelling.
     *
     * The canonical name WINS where an element carries both, and the alias is
     * removed either way (PART 10 section 13c). Stored-source markers keep their
     * authored names. Only a name the enumeration lists moves;
     * an unlisted `data-djot-` attribute is the author's and stays untouched.
     */
    public static function canonicalize(DOMDocument $document): void
    {
        /** @var list<\DOMElement> $elements */
        $elements = iterator_to_array($document->getElementsByTagName('*'));
        foreach ($elements as $element) {
            if (!$element->hasAttributes()) {
                continue;
            }
            /** @var list<\DOMAttr> $attributes */
            $attributes = iterator_to_array($element->attributes);
            foreach ($attributes as $attribute) {
                $lower = strtolower($attribute->name);
                if (self::isStoredSourceMarker($lower)) {
                    continue;
                }
                if (!str_starts_with($lower, self::LEGACY_PREFIX)) {
                    continue;
                }
                $canonical = self::PREFIX . substr($lower, strlen(self::LEGACY_PREFIX));
                if (!self::isReserved($canonical)) {
                    continue;
                }
                $value = $attribute->value;
                $element->removeAttribute($attribute->name);
                if ($element->hasAttribute($canonical)) {
                    continue;
                }
                $element->setAttribute($canonical, $value);
            }
        }
    }
}
