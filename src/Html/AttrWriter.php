<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;

/**
 * Writes the attributes of one element in the order of C2-H-009 and filters authored values
 * (C2-H-013, C2-H-017, C2-H-161, C2-H-162, C2-H-116).
 */
final class AttrWriter
{
    /**
     * C2-H-115: the reserved carrier names.
     *
     * @var array<string, true>
     */
    public const RESERVED = [
        'data-marker' => true,
        'data-carve-explicit-id' => true,
        'data-carve-generated-id' => true,
        'data-carve-ref' => true,
        'data-carve-ref-collapsed' => true,
        'data-carve-ref-shortcut' => true,
        'data-carve-source-level' => true,
        'data-carve-autolink' => true,
        'data-carve-footnote-label' => true,
        'data-carve-inline-footnote' => true,
        'data-carve-raw' => true,
        'data-carve-abbreviations' => true,
        'data-carve-label' => true,
        'data-carve-tight' => true,
        'data-carve-xref' => true,
        'data-carve-caption-number' => true,
        'data-carve-div' => true,
        'data-carve-line-block' => true,
        'data-carve-src' => true,
    ];

    public function __construct(private Options $o, private RenderLog $log)
    {
    }

    /**
     * @param array<array{0: string, 1: string|null}> $structural Written first, in order; a null value is a presence attribute.
     * @param string|null $id Null takes the authored id; the empty string writes none.
     * @param \MarkupCarve\Carve\Attrs|null $attrs The authored attributes.
     * @param string|null $lang The `lang` field.
     * @param array<string> $base Base classes before the authored ones.
     * @param array<string> $tail Generated classes after the authored ones.
     * @param array<array{0: string, 1: string|null}> $generated Generated attributes after the authored keys.
     * @param array{decl?: string, first?: bool, align?: bool} $style `decl` (a generated style declaration), `first` (the authored style
     *   comes first), `align` (an authored `align` key becomes a declaration, C2-H-015).
     * @param int|null $sourceLine
     * @param string|null $dir The `dir` field (C2-A-031).
     *
     * @return string
     */
    public function build(
        array $structural,
        ?string $id,
        ?Attrs $attrs,
        ?string $lang = null,
        array $base = [],
        array $tail = [],
        array $generated = [],
        array $style = [],
        ?int $sourceLine = null,
        ?string $dir = null,
    ): string {
        if (
            $attrs === null && $lang === null && $dir === null && $sourceLine === null && $base === [] && $tail === [] && $generated === []
            && !isset($style['decl'])
        ) {
            // Nothing else to merge: the structural attributes, then the id; `align` and `first` act on authored attributes only.
            if ($id === null || $id === '') {
                return $structural === [] ? '' : self::join($structural, $this->o->xhtml);
            }
            if ($structural === []) {
                return ' id="' . Escape::attr($id) . '"';
            }
        }
        $out = [];
        $seen = [];
        foreach ($structural as [$name, $value]) {
            $out[] = [$name, $value];
            $seen[strtolower($name)] = true;
        }
        if ($sourceLine !== null) {
            $seen['data-source-line'] = true;
        }
        $classes = $this->classes($base, $attrs !== null ? $attrs->classes : [], $tail);
        $this->identity($out, $seen, self::writtenId($id, $attrs), $classes, $lang, $dir);
        if ($attrs !== null) {
            $this->authored($out, $seen, $attrs, $style);
        }
        foreach ($generated as [$name, $value]) {
            if (!isset($seen[strtolower($name)])) {
                $out[] = [$name, $value];
                $seen[strtolower($name)] = true;
            }
        }
        if (isset($style['decl']) && !isset($seen['style'])) {
            $out[] = ['style', $style['decl']];
        }
        if ($sourceLine !== null) {
            $out[] = ['data-source-line', (string)$sourceLine];
        }

        return self::join($out, $this->o->xhtml);
    }

    /**
     * The id, the class and the language, each unless a structural attribute holds it.
     *
     * @param array<array{0: string, 1: string|null}> $out
     * @param array<string, true> $seen
     * @param string|null $id
     * @param string $classes
     * @param string|null $lang
     * @param string|null $dir
     *
     * @return void
     */
    private function identity(array &$out, array &$seen, ?string $id, string $classes, ?string $lang, ?string $dir): void
    {
        if ($id !== null && !isset($seen['id'])) {
            $out[] = ['id', $id];
            $seen['id'] = true;
        }
        if ($classes !== '' && !isset($seen['class'])) {
            $out[] = ['class', $classes];
            $seen['class'] = true;
        }
        if ($lang !== null) {
            $out[] = ['lang', UrlPolicy::authoredValueFails($lang) ? '' : $lang];
            $seen['lang'] = true;
        }
        // C2-H-009, C2-H-013: the direction beside the language; the field wins over a `dir` key.
        if ($dir !== null) {
            $out[] = ['dir', $dir];
            $seen['dir'] = true;
        }
    }

    /**
     * An explicit '' means no id; an authored empty id (a bare `id` key) is written as `id=""` (C2-A-032).
     *
     * @param string|null $id
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     *
     * @return string|null
     */
    private static function writtenId(?string $id, ?Attrs $attrs): ?string
    {
        return $id === '' ? null : $id ?? $attrs?->id;
    }

    public static function dirOf(Node $node): ?string
    {
        $dir = $node->f['dir'] ?? null;

        return is_string($dir) ? $dir : null;
    }

    /**
     * @param array<array{0: string, 1: string|null}> $out
     * @param array<string, true> $seen
     * @param \MarkupCarve\Carve\Attrs $attrs
     * @param array{decl?: string, first?: bool, align?: bool} $style
     *
     * @return void
     */
    private function authored(array &$out, array &$seen, Attrs $attrs, array $style): void
    {
        $decl = $style['decl'] ?? null;
        $alignDecl = ($style['align'] ?? false) === true ? self::alignDecl($attrs->get('align')) : null;
        $hasStyle = $attrs->get('style') !== null;
        foreach ($attrs->pairs as [$name, $value]) {
            $lower = strtolower($name);
            if (!self::validName($name) || $this->dropped($lower) || isset($seen[$lower])) {
                continue;
            }
            if ($lower === 'align' && $alignDecl !== null) {
                if (!$hasStyle) {
                    $out[] = ['style', self::mergeStyle(null, $decl === null ? $alignDecl : $decl . ' ' . $alignDecl, true)];
                    $seen['style'] = true;
                }

                continue;
            }
            $value = $this->filterValue($lower, $value);
            if ($lower === 'style') {
                $gen = trim(($decl ?? '') . ' ' . ($alignDecl ?? ''));
                $value = self::mergeStyle($value, $gen === '' ? null : $gen, ($style['first'] ?? true) === true);
            }
            $out[] = [$name, $value];
            $seen[$lower] = true;
        }
    }

    /**
     * C2-H-015: an authored `align` becomes a declaration.
     *
     * @param string|null $align
     *
     * @return string|null
     */
    private static function alignDecl(?string $align): ?string
    {
        $value = strtolower(trim($align ?? '', " \t\n\r\f\v"));

        return in_array($value, ['left', 'right', 'center'], true) ? 'text-align: ' . $value . ';' : null;
    }

    /**
     * @param string|null $authored
     * @param string|null $generated
     * @param bool $authoredFirst
     *
     * @return string
     */
    private static function mergeStyle(?string $authored, ?string $generated, bool $authoredFirst): string
    {
        if ($generated === null || $generated === '') {
            return $authored ?? '';
        }
        if ($authored === null || $authored === '') {
            return $generated;
        }
        if ($authoredFirst) {
            // C2-H-009: trimmed as carve-js trims, and only a missing `;` is added.
            $trimmed = preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $authored) ?? trim($authored);
            if ($trimmed === '') {
                return $generated;
            }

            return $trimmed . (str_ends_with($trimmed, ';') ? '' : ';') . ' ' . $generated;
        }

        return rtrim($generated, '; ') . '; ' . $authored;
    }

    /**
     * @param string $lower
     *
     * @return bool
     */
    private function dropped(string $lower): bool
    {
        if (in_array($lower, Tables::URL_DROPPED_ATTRIBUTES['name'], true)) {
            return true;
        }
        foreach (Tables::URL_DROPPED_ATTRIBUTES['prefix'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        // C2-H-116: dropped with roundtrip on or off.
        if (isset(self::RESERVED[$lower])) {
            $this->log->loss('carrier-attribute-dropped', 'warning', ['key' => $lower]);

            return true;
        }

        return false;
    }

    /**
     * C2-H-161, C2-H-162: the value tests for an authored value.
     *
     * @param string $lower
     * @param string $value
     *
     * @return string
     */
    public function filterValue(string $lower, string $value): string
    {
        if (UrlPolicy::authoredValueFails($value)) {
            return '';
        }
        if (isset(Tables::URL_LIST_ATTRIBUTES[$lower])) {
            $sep = Tables::URL_LIST_ATTRIBUTES[$lower] ? '/[\s,]+/' : '/\s+/';
            // C2-H-161: one character class run, linear; authored URL list values only.
            foreach (preg_split($sep, $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (UrlPolicy::authoredValueFails($token)) {
                    return '';
                }
                if (!UrlPolicy::passes($token, $this->o)) {
                    // C2-H-161 applies the policy of C2-H-160, which reports the failed destination.
                    $this->log->loss('destination-denied', 'error');

                    return '';
                }
            }
        }
        if ($lower === 'style' && self::styleFails($value)) {
            return '';
        }

        return $value;
    }

    /**
     * C2-H-162: CSS escapes decoded, whitespace removed, ASCII lowered, then the patterns.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function styleFails(string $value): bool
    {
        // C2-H-162: a backslash and bounded alternatives (at most six hex digits), linear; authored style only.
        $decoded = (string)preg_replace_callback(
            '/\\\\(?:([0-9A-Fa-f]{1,6})[ \t\n\f\r]?|(\r\n|[\n\r\f])|(.))/s',
            static function (array $m): string {
                if ($m[1] !== null) {
                    $cp = (int)hexdec($m[1]);

                    return $cp === 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF) ? "\u{FFFD}" : mb_chr($cp, 'UTF-8');
                }
                if ($m[2] !== null) {
                    return '';
                }

                return (string)$m[3];
            },
            $value,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
        // C2-H-162: one class run, linear.
        $flat = strtolower((string)preg_replace('/\s+/u', '', $decoded));
        foreach (Tables::URL_STYLE_PATTERNS as $pattern) {
            if (str_contains($flat, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-H-012: base classes, then authored ones (each tested, C2-H-161), then generated; each once.
     *
     * @param array<string> $base
     * @param array<string> $authored
     * @param array<string> $tail
     *
     * @return string
     */
    private function classes(array $base, array $authored, array $tail): string
    {
        $seen = [];
        foreach ($base as $c) {
            $seen[$c] = true;
        }
        foreach ($authored as $c) {
            if ($c !== '' && !UrlPolicy::authoredValueFails($c)) {
                $seen[$c] = true;
            }
        }
        foreach ($tail as $c) {
            $seen[$c] = true;
        }

        return implode(' ', array_map('strval', array_keys($seen)));
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private static function validName(string $name): bool
    {
        return $name !== '' && strpbrk($name, " \t\n\f\r\"'>/=<`\0") === false;
    }

    /**
     * @param array<array{0: string, 1: string|null}> $attrs
     * @param bool $xhtml
     *
     * @return string
     */
    public static function join(array $attrs, bool $xhtml = false): string
    {
        $out = '';
        foreach ($attrs as [$name, $value]) {
            $out .= $value === null && !$xhtml ? ' ' . $name : ' ' . $name . '="' . Escape::attr($value ?? '') . '"';
        }

        return $out;
    }
}
