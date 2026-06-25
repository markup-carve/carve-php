<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\ContentNodeInterface;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\InlineExtension;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Node;
use Carve\Renderer\HtmlRenderer;
use InvalidArgumentException;

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
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected string $position = 'before',
        protected string $shape = 'square',
        protected bool $tint = false,
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

            $color = $this->safeColor($this->inlineText($node));
            if ($color === null) {
                return;
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
            $extraClasses[] = 'swatch-chip-only';
            $extraAttrs['title'] = $color;
            $inner = $chip;
        } elseif ($this->position === 'after') {
            $inner = $label . ' ' . $chip;
        } else {
            $inner = $chip . ' ' . $label;
        }

        return '<span' . $this->openAttributes($node, $renderer, $extraClasses, $extraStyle, $extraAttrs) . '>'
            . $inner . '</span>';
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
     * Build the output element's attribute string with the `swatch` base class
     * (then any extension-added classes, then author classes), an optional
     * extension style, and id / key-values in source order. Author-supplied
     * attributes win over the extension defaults on a key conflict.
     *
     * @param \Carve\Node\Node $node
     * @param \Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $extraClasses
     * @param string|null $extraStyle
     * @param array<string, string> $extraAttrs
     */
    protected function openAttributes(
        Node $node,
        HtmlRenderer $renderer,
        array $extraClasses = [],
        ?string $extraStyle = null,
        array $extraAttrs = [],
    ): string {
        $classes = [self::KIND];
        foreach ([...$extraClasses, ...$node->getClassList()] as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $node->getAttributes();
        unset($attrs['class']);
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
