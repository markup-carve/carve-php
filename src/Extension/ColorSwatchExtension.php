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

            $label = $this->escapeHtml($color);
            $style = $renderer->escapeAttribute('background-color:' . $color);

            $event->setHtml('<span' . $this->openAttributes($node, $renderer) . '>'
                . '<span class="swatch-chip" style="' . $style . '"></span> '
                . $label . '</span>');
        });
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
     * ahead of any author classes, then id / key-values in source order.
     */
    protected function openAttributes(Node $node, HtmlRenderer $renderer): string
    {
        $classes = [self::KIND];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $node->getAttributes();
        unset($attrs['class']);

        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        $out = ' class="' . $renderer->escapeAttribute(implode(' ', $classes)) . '"';

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
