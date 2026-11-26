<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Generated\ImportTables;
use MarkupCarve\Carve\Generated\Tables;

/**
 * C2-M-052 to C2-M-055: the attributes of an element as `attrs`, `lang` and `dir` of its node.
 */
final class Attrs
{
    /**
     * @var array<string, string>
     */
    private const ALIGN_STYLES = [
        'text-align: left;' => 'left',
        'text-align: right;' => 'right',
        'text-align: center;' => 'center',
    ];

    /**
     * @var array<string, true>
     */
    private const DIRS = ['ltr' => true, 'rtl' => true, 'auto' => true];

    public static function isCarrier(string $name): bool
    {
        return isset(ImportTables::CARRIERS[$name]);
    }

    /**
     * C2-I-030: a key is an ASCII letter or `_`, then letters, digits, `_` and `-`.
     */
    public static function isKey(string $name): bool
    {
        return $name !== '' && strspn($name, Tables::ATTR_KEY_FIRST, 0, 1) === 1
            && strspn($name, Tables::ATTR_KEY_REST, 1) === strlen($name) - 1;
    }

    /**
     * C2-M-052, C2-M-054: why an attribute is dropped wherever it stands, or null for one the tree can keep.
     */
    public static function dropped(string $name, string $value): ?string
    {
        foreach (ImportTables::ATTRIBUTES as $row => [$match, $treatment]) {
            if ($treatment !== 'drop' || !($match === 'prefix' ? str_starts_with($name, $row) : $name === $row)) {
                continue;
            }

            return $match === 'prefix' ? 'an event handler' : 'a dropped attribute';
        }
        if ($name === 'dir') {
            return isset(self::DIRS[$value]) ? null : 'an invalid direction';
        }

        return self::isKey($name) || self::isCarrier($name) ? null : 'a name that is no key';
    }

    /**
     * The authored attributes of an element, without `omit`, the reserved names (C2-H-186) and the names that C2-M-052
     * drops. With `alignStyle`, an exact alignment declaration is the key `align` (C2-M-075).
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string> $omit
     * @param bool $alignStyle
     * @param \MarkupCarve\Carve\Import\ImportContext|null $context
     *
     * @return array<string, mixed> The fields `attrs`, `lang` and `dir` of the node.
     */
    public static function of(HtmlNode $node, array $omit = [], bool $alignStyle = false, ?ImportContext $context = null): array
    {
        $entries = [];
        $styleAlign = $alignStyle ? self::alignOf($node->attr('style')) : null;
        foreach ($node->attrs as $name => $value) {
            $name = (string)$name;
            if ($name === 'align' && $styleAlign !== null) {
                $context?->report($node, 'import-attribute-dropped', 'Align attribute dropped: style takes precedence');

                continue;
            }
            if (in_array($name, $omit, true) || $name === 'lang' || $name === 'dir' || self::isCarrier($name)) {
                if ($name === 'data-carve-source-level' && !in_array($name, $omit, true)) {
                    $context?->ignoredCarrier($node, $name, 'the element is no heading');
                }

                continue;
            }
            if (self::dropped($name, $value) !== null) {
                continue;
            }
            $align = $name === 'style' && $alignStyle ? (self::ALIGN_STYLES[$value] ?? null) : null;
            $entries[] = $align === null ? [$name, $value] : ['align', $align];
        }
        $out = [];
        $lang = $node->attr('lang');
        if ($lang !== null && $lang !== '') {
            $out['lang'] = $lang;
        }
        $dir = $node->attr('dir');
        if ($dir !== null && isset(self::DIRS[$dir])) {
            $out['dir'] = $dir;
        }
        $attrs = self::attrsOf($entries);
        if ($attrs !== null) {
            $out['attrs'] = $attrs;
        }

        return $out;
    }

    public static function alignOf(?string $style): ?string
    {
        return $style === null ? null : (self::ALIGN_STYLES[$style] ?? null);
    }

    /**
     * @param array<array{0: string, 1: string}> $entries
     *
     * @return array<string, mixed>|null
     */
    public static function attrsOf(array $entries): ?array
    {
        $attrs = [];
        foreach ($entries as [$key, $value]) {
            if ($key === 'id') {
                $attrs['id'] = $value;
            } elseif ($key === 'class') {
                $names = array_values(array_filter(preg_split('/[ \t\n\f\r]+/', $value) ?: [], static fn (string $s): bool => $s !== ''));
                if ($names !== []) {
                    $attrs['classes'] = array_merge($attrs['classes'] ?? [], $names);
                }
            } else {
                $attrs['keyValues'][] = [$key, $value];
            }
        }

        return $attrs === [] ? null : $attrs;
    }

    /**
     * The fields of `of()` with the classes `drop` removed, as the renderer adds them to every element of a kind.
     *
     * @param array<string, mixed> $mapped
     * @param array<string> $drop
     *
     * @return array<string, mixed>
     */
    public static function withoutClasses(array $mapped, array $drop): array
    {
        $attrs = J::attrs($mapped);
        if (!isset($attrs['classes'])) {
            return $mapped;
        }
        $classes = array_values(array_diff(J::classes($attrs), $drop));
        if ($classes === []) {
            unset($attrs['classes']);
        } else {
            $attrs['classes'] = $classes;
        }
        $mapped['attrs'] = $attrs;

        return self::tidy($mapped);
    }

    /**
     * The fields of `of()` without the keys `drop`, which the renderer derives from the node.
     *
     * @param array<string, mixed> $mapped
     * @param array<string> $drop
     *
     * @return array<string, mixed>
     */
    public static function withoutKeys(array $mapped, array $drop): array
    {
        $attrs = J::attrs($mapped);
        if (!isset($attrs['keyValues'])) {
            return $mapped;
        }
        $pairs = array_values(array_filter(J::pairs($attrs), static fn (array $p): bool => !in_array($p[0], $drop, true)));
        if ($pairs === []) {
            unset($attrs['keyValues']);
        } else {
            $attrs['keyValues'] = $pairs;
        }
        $mapped['attrs'] = $attrs;

        return self::tidy($mapped);
    }

    /**
     * The fields of `of()` with the keys of `map` renamed in place, unless the target key is there already
     * (C2-M-044, the reverse of C2-H-210).
     *
     * @param array<string, mixed> $mapped
     * @param array<string, string> $map
     *
     * @return array<string, mixed>
     */
    public static function renamedKeys(array $mapped, array $map): array
    {
        $attrs = J::attrs($mapped);
        if (!isset($attrs['keyValues'])) {
            return $mapped;
        }
        $pairs = J::pairs($attrs);
        $present = array_column($pairs, 0);
        foreach ($pairs as $i => [$key, $value]) {
            $target = $map[$key] ?? null;
            if ($target !== null && !in_array($target, $present, true)) {
                $pairs[$i] = [$target, $value];
            }
        }
        $attrs['keyValues'] = $pairs;
        $mapped['attrs'] = $attrs;

        return $mapped;
    }

    /**
     * @param array<string, mixed> $mapped
     *
     * @return array<string, mixed>
     */
    private static function tidy(array $mapped): array
    {
        if (isset($mapped['attrs']) && $mapped['attrs'] === []) {
            unset($mapped['attrs']);
        }

        return $mapped;
    }
}
