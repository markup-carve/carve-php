<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `:color[value]` (`carve.colorSwatch`) with a swatch chip, for a safe CSS color only. Both channels write
 * the same HTML.
 */
final class ColorSwatch
{
    /**
     * @var array<string, true>
     */
    private const NAMED_COLORS = [
        'transparent' => true,
        'currentcolor' => true,
        'aliceblue' => true,
        'antiquewhite' => true,
        'aqua' => true,
        'aquamarine' => true,
        'azure' => true,
        'beige' => true,
        'bisque' => true,
        'black' => true,
        'blanchedalmond' => true,
        'blue' => true,
        'blueviolet' => true,
        'brown' => true,
        'burlywood' => true,
        'cadetblue' => true,
        'chartreuse' => true,
        'chocolate' => true,
        'coral' => true,
        'cornflowerblue' => true,
        'cornsilk' => true,
        'crimson' => true,
        'cyan' => true,
        'darkblue' => true,
        'darkcyan' => true,
        'darkgoldenrod' => true,
        'darkgray' => true,
        'darkgreen' => true,
        'darkgrey' => true,
        'darkkhaki' => true,
        'darkmagenta' => true,
        'darkolivegreen' => true,
        'darkorange' => true,
        'darkorchid' => true,
        'darkred' => true,
        'darksalmon' => true,
        'darkseagreen' => true,
        'darkslateblue' => true,
        'darkslategray' => true,
        'darkslategrey' => true,
        'darkturquoise' => true,
        'darkvioletred' => true,
        'deeppink' => true,
        'deepskyblue' => true,
        'dimgray' => true,
        'dimgrey' => true,
        'dodgerblue' => true,
        'firebrick' => true,
        'floralwhite' => true,
        'forestgreen' => true,
        'fuchsia' => true,
        'gainsboro' => true,
        'ghostwhite' => true,
        'gold' => true,
        'goldenrod' => true,
        'gray' => true,
        'green' => true,
        'greenyellow' => true,
        'grey' => true,
        'honeydew' => true,
        'hotpink' => true,
        'indianred' => true,
        'indigo' => true,
        'ivory' => true,
        'khaki' => true,
        'lavender' => true,
        'lavenderblush' => true,
        'lawngreen' => true,
        'lemonchiffon' => true,
        'lightblue' => true,
        'lightcoral' => true,
        'lightcyan' => true,
        'lightgoldenrodyellow' => true,
        'lightgray' => true,
        'lightgreen' => true,
        'lightgrey' => true,
        'lightpink' => true,
        'lightsalmon' => true,
        'lightseagreen' => true,
        'lightskyblue' => true,
        'lightslategray' => true,
        'lightslategrey' => true,
        'lightsteelblue' => true,
        'lightyellow' => true,
        'lime' => true,
        'limegreen' => true,
        'linen' => true,
        'magenta' => true,
        'maroon' => true,
        'mediumaquamarine' => true,
        'mediumblue' => true,
        'mediumorchid' => true,
        'mediumpurple' => true,
        'mediumseagreen' => true,
        'mediumslateblue' => true,
        'mediumspringgreen' => true,
        'mediumturquoise' => true,
        'mediumvioletred' => true,
        'midnightblue' => true,
        'mintcream' => true,
        'mistyrose' => true,
        'moccasin' => true,
        'navajowhite' => true,
        'navy' => true,
        'oldlace' => true,
        'olive' => true,
        'olivedrab' => true,
        'orange' => true,
        'orangered' => true,
        'orchid' => true,
        'palegoldenrod' => true,
        'palegreen' => true,
        'paleturquoise' => true,
        'palevioletred' => true,
        'papayawhip' => true,
        'peachpuff' => true,
        'peru' => true,
        'pink' => true,
        'plum' => true,
        'powderblue' => true,
        'purple' => true,
        'rebeccapurple' => true,
        'red' => true,
        'rosybrown' => true,
        'royalblue' => true,
        'saddlebrown' => true,
        'salmon' => true,
        'sandybrown' => true,
        'seagreen' => true,
        'seashell' => true,
        'sienna' => true,
        'silver' => true,
        'skyblue' => true,
        'slateblue' => true,
        'slategray' => true,
        'slategrey' => true,
        'snow' => true,
        'springgreen' => true,
        'steelblue' => true,
        'tan' => true,
        'teal' => true,
        'thistle' => true,
        'tomato' => true,
        'turquoise' => true,
        'violet' => true,
        'wheat' => true,
        'white' => true,
        'whitesmoke' => true,
        'yellow' => true,
        'yellowgreen' => true,
    ];

    /**
     * @param string $position `before`, `after` or `none`.
     * @param string $shape `square`, `round` or `ring`.
     * @param bool $tint
     * @param bool $reveal
     *
     * @throws \InvalidArgumentException
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(string $position = 'before', string $shape = 'square', bool $tint = false, bool $reveal = false): Bundle
    {
        if (!in_array($position, ['before', 'after', 'none'], true)) {
            throw new InvalidArgumentException('Invalid swatch position');
        }
        if (!in_array($shape, ['square', 'round', 'ring'], true)) {
            throw new InvalidArgumentException('Invalid swatch shape');
        }
        $render = static function (Node $node, RenderHost $host) use ($position, $shape, $tint, $reveal): string {
            $props = $node->f['props'] ?? null;
            $value = is_array($props) ? PlainText::str($props['value'] ?? '') : '';
            $attrs = $node->f['attrs'] ?? null;
            $attrs = $attrs instanceof Attrs ? $attrs : null;
            $contrast = AuthorAttrs::value($attrs, 'contrast') !== null ? self::contrastText($value) : null;
            if ($contrast !== null) {
                $style = 'background:' . $value . ';color:' . $contrast;
                $label = AuthorAttrs::withDefault(AuthorAttrs::withClass($attrs, 'swatch-label'), 'style', $style);

                return '<span' . AuthorAttrs::write($label, $host, ['contrast']) . '>' . RenderHost::escapeText($value) . '</span>';
            }

            return self::swatch($value, $attrs, $position, $shape, $tint, $reveal, $host);
        };

        return new Bundle([
            new ExtensionDefinition(
                'carve.colorSwatch',
                '1',
                ['value'],
                matcher: ['form' => 'inline', 'name' => 'color'],
                transform: self::transform(...),
                renderer: $render,
                buildTimeRenderer: $render,
            ),
        ]);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'inline') {
            return null;
        }
        $children = $claim->node->children ?? [];
        $value = self::safeColor(self::textOf($children));
        if ($value === null) {
            return null;
        }
        $node = new Node('inline_extension', []);
        $node->f['name'] = 'carve.colorSwatch';
        $node->f['version'] = '1';
        $node->f['props'] = ['value' => $value];
        $node->f['slots'] = ['value' => $children];
        $node->f['fallback'] = array_map(Extensions::copy(...), $children);

        return $node;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     */
    private static function textOf(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (in_array($node->type, ['text', 'code', 'literal_inline'], true)) {
                $out .= PlainText::str($node->f['value'] ?? '');
            } elseif ($node->type === 'tag') {
                $out .= '#' . PlainText::str($node->f['name'] ?? '');
            } elseif ($node->children !== null) {
                $out .= self::textOf($node->children);
            }
        }

        return $out;
    }

    private static function safeColor(string $input): ?string
    {
        $value = trim($input);
        if (strlen($value) > 256) {
            return null;
        }
        if (
            preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1
            || preg_match('~^(?:rgb|rgba|hsl|hsla)\([0-9.,%\s/]*[0-9][0-9.,%\s/]*\)$~', $value) === 1
            || preg_match('/^[a-z]+$/i', $value) === 1 && isset(self::NAMED_COLORS[strtolower($value)])
        ) {
            return $value;
        }

        return null;
    }

    private static function contrastText(string $value): ?string
    {
        $hex = preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value, $m) === 1 ? $m[1] : null;
        $length = $hex === null ? 0 : strlen($hex);
        if ($hex !== null && ($length === 4 || $length === 8) && preg_match('/^0+$/', substr($hex, $length === 4 ? 3 : 6)) === 1) {
            return null;
        }
        $channels = self::channels($value, $hex);
        if ($channels === null) {
            return null;
        }
        $brightness = ($channels[0] * 299 + $channels[1] * 587 + $channels[2] * 114) / 1000;

        return $brightness >= 128 ? '#000' : '#fff';
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function channels(string $value, ?string $hex): ?array
    {
        if ($hex !== null) {
            $digits = strlen($hex) < 5
                ? [str_repeat($hex[0], 2), str_repeat($hex[1], 2), str_repeat($hex[2], 2)]
                : [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)];

            return [(int)hexdec($digits[0]), (int)hexdec($digits[1]), (int)hexdec($digits[2])];
        }
        if (preg_match('/^rgba?\((.*)\)$/', $value, $m) !== 1) {
            return null;
        }
        $parts = array_slice(preg_split('~[\s,/]+~', trim($m[1])) ?: [], 0, 3);
        if (count($parts) !== 3 || preg_grep('/^\d+$/', $parts) !== $parts) {
            return null;
        }

        return [(int)$parts[0], (int)$parts[1], (int)$parts[2]];
    }

    private static function swatch(
        string $value,
        ?Attrs $attrs,
        string $position,
        string $shape,
        bool $tint,
        bool $reveal,
        RenderHost $host,
    ): string {
        $reveal = $reveal && $position !== 'none';
        $chipClass = $shape === 'square' ? 'swatch-chip' : 'swatch-chip swatch-chip-' . $shape;
        $style = $shape === 'ring' ? 'border-color:' . $value : 'background-color:' . $value;
        $chip = '<span class="' . $chipClass . '" style="' . RenderHost::escapeAttribute($style) . '"></span>';
        $escaped = RenderHost::escapeText($value);
        $outer = $attrs === null ? new Attrs() : clone $attrs;
        $outer->classes = [
            'swatch',
            ...$outer->classes,
            ...($tint ? ['swatch-tint'] : []),
            ...($position === 'none' ? ['swatch-chip-only'] : []),
            ...($reveal ? ['swatch-reveal'] : []),
        ];
        if ($tint) {
            $outer = AuthorAttrs::withDefault($outer, 'style', 'background-color:color-mix(in srgb, ' . $value . ' 12%, transparent)');
        }
        if ($position === 'none') {
            $outer = AuthorAttrs::withDefault($outer, 'title', $value);
        }
        if ($reveal) {
            $outer = AuthorAttrs::withDefault($outer, 'tabindex', '0');
        }
        $visible = $reveal ? '<span class="swatch-val">' . $escaped . '</span>' : $escaped;
        $inner = match ($position) {
            'none' => $chip,
            'after' => $visible . ' ' . $chip,
            default => $chip . ' ' . $visible,
        };

        return '<span' . AuthorAttrs::write($outer, $host, ['contrast']) . '>' . $inner . '</span>';
    }
}
