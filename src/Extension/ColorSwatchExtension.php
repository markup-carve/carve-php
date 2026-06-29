<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\ContentNodeInterface;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Inline color swatch extension (Tier-3).
 *
 * Claims the reserved inline `color` role only when its parsed inline content
 * flattens to a safe CSS color token. Invalid / unrecognized values defer to
 * the generic `<span class="ext-color">...</span>` fallback.
 */
class ColorSwatchExtension implements ExtensionInterface
{
    /**
     * The inline extension role this extension claims.
     *
     * @var string
     */
    public const INLINE_TYPE = 'color';

    /**
     * The CSS named colors (plus `transparent` / `currentcolor`) accepted as a
     * bareword color, space-separated and lowercase. Anything else alphabetic
     * (e.g. `banana`) is not a color and defers to the generic fallback.
     *
     * @var string
     */
    protected const NAMED_COLORS = ' transparent currentcolor aliceblue antiquewhite aqua aquamarine'
        . ' azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue'
        . ' chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan'
        . ' darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange'
        . ' darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey'
        . ' darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick'
        . ' floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow'
        . ' grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen'
        . ' lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen'
        . ' lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey'
        . ' lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue'
        . ' mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise'
        . ' mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive'
        . ' olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred'
        . ' papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue'
        . ' saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray'
        . ' slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white'
        . ' whitesmoke yellow yellowgreen ';

    /**
     * Base class for rendered color swatches.
     *
     * @var string
     */
    public const KIND = 'swatch';

    /**
     * Where the chip sits relative to the value: `before` the value (default),
     * `after` it, or `none` (chip only; the value becomes the element `title`).
     *
     * @var array<string>
     */
    public const POSITIONS = ['before', 'after', 'none'];

    /**
     * Chip shape: a filled `square` (default), a filled `round` dot, or a hollow
     * `ring` (the color is the border, not the fill).
     *
     * @var array<string>
     */
    public const SHAPES = ['square', 'round', 'ring'];

    /**
     * @param string $position Chip position: one of self::POSITIONS.
     * @param string $shape Chip shape: one of self::SHAPES.
     * @param bool $tint When true, a faint tint of the color is painted behind the
     *   whole swatch (via CSS color-mix; decorative, degrades where unsupported).
     * @param bool $reveal When true, the value text is collapsed and revealed on
     *   hover / keyboard focus (pure-CSS; the `swatch-reveal` class drives it). The
     *   value stays in the DOM for assistive tech. Ignored when position is `none`
     *   (which already hides the value, surfacing it via the element title).
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected string $position = 'before',
        protected string $shape = 'square',
        protected bool $tint = false,
        protected bool $reveal = false,
    ) {
        if (!in_array($this->position, self::POSITIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ColorSwatch position "%s"; expected one of: %s.',
                $this->position,
                implode(', ', self::POSITIONS),
            ));
        }
        if (!in_array($this->shape, self::SHAPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ColorSwatch shape "%s"; expected one of: %s.',
                $this->shape,
                implode(', ', self::SHAPES),
            ));
        }
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.inline_extension', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof InlineExtension) {
                return;
            }
            if ($node->getExtensionType() !== self::INLINE_TYPE) {
                return;
            }

            $contrast = array_key_exists('contrast', $node->getAttributes());
            $color = $this->safeColor($this->inlineText($node));
            if ($color === null) {
                if ($contrast) {
                    $node->removeAttribute('contrast');
                }

                return;
            }

            if ($contrast) {
                $textColor = $this->autoContrastTextColor($color);
                if ($textColor !== null) {
                    $event->setHtml($this->renderContrastLabel($node, $renderer, $color, $textColor));

                    return;
                }

                $node->removeAttribute('contrast');
            }

            $event->setHtml($this->renderSwatch($node, $renderer, $color));
        });
    }

    /**
     * Build the swatch HTML for a validated color according to the configured
     * position, shape and tint. The default (before / square / no tint) emits
     * the canonical `<span class="swatch"><span class="swatch-chip" ...></span>
     * value</span>` form.
     */
    protected function renderSwatch(Node $node, HtmlRenderer $renderer, string $color): string
    {
        $label = $this->escapeHtml($color);

        // A ring shows the color as the border; filled shapes as the background.
        $chipClass = 'swatch-chip';
        if ($this->shape !== 'square') {
            $chipClass .= ' swatch-chip-' . $this->shape;
        }
        $chipStyle = $this->shape === 'ring'
            ? 'border-color:' . $color
            : 'background-color:' . $color;
        $chip = '<span class="' . $chipClass . '" style="'
            . $renderer->escapeAttribute($chipStyle) . '"></span>';

        $extraClasses = [];
        $extraStyle = null;
        $extraAttrs = [];
        if ($this->tint) {
            $extraClasses[] = 'swatch-tint';
            $extraStyle = 'background-color:color-mix(in srgb, ' . $color . ' 12%, transparent)';
        }

        if ($this->position === 'none') {
            // Chip only: the value is not shown inline, so surface it as the
            // element title so it stays available on hover and to assistive tech.
            // `reveal` is meaningless here (there is no inline value) and ignored.
            $extraClasses[] = 'swatch-chip-only';
            $extraAttrs['title'] = $color;
            $inner = $chip;
        } else {
            // When revealing, wrap the value so CSS can collapse / expand it, make
            // the swatch keyboard-focusable, and keep the value in the DOM for AT.
            if ($this->reveal) {
                $extraClasses[] = 'swatch-reveal';
                $extraAttrs['tabindex'] = '0';
                $label = '<span class="swatch-val">' . $label . '</span>';
            }
            $inner = $this->position === 'after'
                ? $label . ' ' . $chip
                : $chip . ' ' . $label;
        }

        return '<span' . $this->openAttributes($node, $renderer, $extraClasses, $extraStyle, $extraAttrs) . '>'
            . $inner . '</span>';
    }

    /**
     * Render the contrast label variant: color value inside the colored box.
     */
    protected function renderContrastLabel(
        Node $node,
        HtmlRenderer $renderer,
        string $color,
        string $textColor,
    ): string {
        // The computed colors go last so author attributes keep their source
        // order; an explicit author `style` wins and suppresses ours (which also
        // avoids emitting a duplicate `style` attribute).
        $style = 'background:' . $color . ';color:' . $textColor;
        $hasAuthorStyle = isset($node->getAttributes()['style']);
        $styleAttr = $hasAuthorStyle
            ? ''
            : ' style="' . $renderer->escapeAttribute($style) . '"';

        return '<span'
            . $this->openAttributes($node, $renderer, [], null, [], 'swatch-label', ['contrast'])
            . $styleAttr
            . '>'
            . $this->escapeHtml($color)
            . '</span>';
    }

    /**
     * Return a trimmed color value that cannot break out of a CSS declaration.
     */
    protected function safeColor(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return $value;
        }

        // Only safe chars inside, and at least one digit (rejects `rgb(/)`).
        if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]*[0-9][0-9.,%\s\/]*\)$/', $value) === 1) {
            return $value;
        }

        // A bareword is only a color if it is an actual CSS named color (or
        // `transparent` / `currentcolor`); arbitrary words like `banana` are not.
        if (
            preg_match('/^[a-zA-Z]+$/', $value) === 1
            && str_contains(self::NAMED_COLORS, ' ' . strtolower($value) . ' ')
        ) {
            return $value;
        }

        return null;
    }

    /**
     * Compute black or white label text for integer RGB-compatible colors.
     */
    protected function autoContrastTextColor(string $color): ?string
    {
        // A fully transparent color paints no background, so a computed text
        // color would sit on the page itself and could be unreadable. Decline
        // the contrast label (fall back to the normal swatch) instead of guessing.
        if ($this->isFullyTransparentHex($color)) {
            return null;
        }

        $rgb = $this->parseIntegerRgb($color);
        if ($rgb === null) {
            return null;
        }

        [$red, $green, $blue] = $rgb;
        $brightness = intdiv(($red * 299) + ($green * 587) + ($blue * 114), 1000);

        return $brightness >= 128 ? '#000' : '#fff';
    }

    /**
     * True for hex colors whose alpha channel is fully zero (e.g. `#0000`,
     * `#00000000`).
     */
    protected function isFullyTransparentHex(string $color): bool
    {
        if (preg_match('/^#([0-9a-fA-F]{4}|[0-9a-fA-F]{8})$/', $color, $match) !== 1) {
            return false;
        }

        $alpha = strlen($match[1]) === 4 ? $match[1][3] : substr($match[1], 6, 2);

        return preg_match('/^0+$/', $alpha) === 1;
    }

    /**
     * @return array{int, int, int}|null
     */
    protected function parseIntegerRgb(string $color): ?array
    {
        if (preg_match('/^#([0-9a-fA-F]{3,4})$/', $color, $match) === 1) {
            return [
                (int)hexdec($match[1][0] . $match[1][0]),
                (int)hexdec($match[1][1] . $match[1][1]),
                (int)hexdec($match[1][2] . $match[1][2]),
            ];
        }

        if (preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color, $match) === 1) {
            return [
                (int)hexdec(substr($match[1], 0, 2)),
                (int)hexdec(substr($match[1], 2, 2)),
                (int)hexdec(substr($match[1], 4, 2)),
            ];
        }

        if (preg_match('/^rgba?\((.*)\)$/', $color, $match) !== 1) {
            return null;
        }

        $tokens = preg_split('/[\s,\/]+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || count($tokens) < 3) {
            return null;
        }

        $rgb = [];
        for ($i = 0; $i < 3; $i++) {
            if (preg_match('/^[+-]?\d+$/', $tokens[$i]) !== 1) {
                return null;
            }

            $rgb[] = max(0, min(255, (int)$tokens[$i]));
        }

        return [$rgb[0], $rgb[1], $rgb[2]];
    }

    /**
     * Build the output element's attribute string with the `swatch` base class
     * (then any extension-added classes, then author classes), an optional
     * extension style, and id / key-values in source order. Author-supplied
     * attributes win over the extension defaults on a key conflict.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $extraClasses
     * @param string|null $extraStyle
     * @param array<string, string> $extraAttrs
     * @param string $baseClass
     * @param array<string> $omitAttrs
     */
    protected function openAttributes(
        Node $node,
        HtmlRenderer $renderer,
        array $extraClasses = [],
        ?string $extraStyle = null,
        array $extraAttrs = [],
        string $baseClass = self::KIND,
        array $omitAttrs = [],
    ): string {
        $classes = [$baseClass];
        foreach ([...$extraClasses, ...$node->getClassList()] as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $node->getAttributes();
        unset($attrs['class']);
        foreach ($omitAttrs as $key) {
            unset($attrs[$key]);
        }
        // Author attributes (e.g. an explicit title) take precedence over the
        // extension defaults; array union keeps the left (author) keys.
        $attrs += $extraAttrs;

        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        $out = ' class="' . $renderer->escapeAttribute(implode(' ', $classes)) . '"';
        // Only add the extension style when the author did not set their own.
        if ($extraStyle !== null && !isset($attrs['style'])) {
            $out .= ' style="' . $renderer->escapeAttribute($extraStyle) . '"';
        }

        return $out . $renderer->renderAttributeArray($attrs);
    }

    /**
     * Flatten inline-parsed extension content back to text.
     */
    protected function inlineText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ContentNodeInterface) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } else {
                $text .= $this->inlineText($child);
            }
        }

        return $text;
    }

    /**
     * HTML-escape visible label text, matching the core renderer.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }
}
