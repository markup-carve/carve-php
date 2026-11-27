<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * For the libxml fallback's pre-parse scan: whether a tag stands in SVG or MathML content, where raw text element
 * names are ordinary elements, and which foreign elements a breakout tag closes. An approximation of the HTML tree
 * builder, linear in the input: `annotation-xml` and implied end tags are not modeled.
 *
 * @internal
 */
final class LegacyForeignContent
{
    /**
     * Start tags that end foreign content (the HTML Standard's breakout list, without `font`).
     *
     * @var array<string, true>
     */
    private const BREAKOUT = [
        'b' => true,
        'big' => true,
        'blockquote' => true,
        'body' => true,
        'br' => true,
        'center' => true,
        'code' => true,
        'dd' => true,
        'div' => true,
        'dl' => true,
        'dt' => true,
        'em' => true,
        'embed' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'head' => true,
        'hr' => true,
        'i' => true,
        'img' => true,
        'li' => true,
        'listing' => true,
        'menu' => true,
        'meta' => true,
        'nobr' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        'ruby' => true,
        's' => true,
        'small' => true,
        'span' => true,
        'strike' => true,
        'strong' => true,
        'sub' => true,
        'sup' => true,
        'table' => true,
        'tt' => true,
        'u' => true,
        'ul' => true,
        'var' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const MATH_TEXT = [
        'mi' => true,
        'mo' => true,
        'mn' => true,
        'ms' => true,
        'mtext' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const VOIDS = [
        'area' => true,
        'base' => true,
        'basefont' => true,
        'bgsound' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'frame' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'keygen' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    /**
     * End tags that end foreign content.
     *
     * @var array<string>
     */
    private const BREAKOUT_END = ['br', 'p'];

    /**
     * The HTML Standard's case adjustments of SVG element names.
     *
     * @var array<string, string>
     */
    private const SVG_NAMES = [
        'altglyph' => 'altGlyph',
        'altglyphdef' => 'altGlyphDef',
        'altglyphitem' => 'altGlyphItem',
        'animatecolor' => 'animateColor',
        'animatemotion' => 'animateMotion',
        'animatetransform' => 'animateTransform',
        'clippath' => 'clipPath',
        'feblend' => 'feBlend',
        'fecolormatrix' => 'feColorMatrix',
        'fecomponenttransfer' => 'feComponentTransfer',
        'fecomposite' => 'feComposite',
        'feconvolvematrix' => 'feConvolveMatrix',
        'fediffuselighting' => 'feDiffuseLighting',
        'fedisplacementmap' => 'feDisplacementMap',
        'fedistantlight' => 'feDistantLight',
        'fedropshadow' => 'feDropShadow',
        'feflood' => 'feFlood',
        'fefunca' => 'feFuncA',
        'fefuncb' => 'feFuncB',
        'fefuncg' => 'feFuncG',
        'fefuncr' => 'feFuncR',
        'fegaussianblur' => 'feGaussianBlur',
        'feimage' => 'feImage',
        'femerge' => 'feMerge',
        'femergenode' => 'feMergeNode',
        'femorphology' => 'feMorphology',
        'feoffset' => 'feOffset',
        'fepointlight' => 'fePointLight',
        'fespecularlighting' => 'feSpecularLighting',
        'fespotlight' => 'feSpotLight',
        'fetile' => 'feTile',
        'feturbulence' => 'feTurbulence',
        'foreignobject' => 'foreignObject',
        'glyphref' => 'glyphRef',
        'lineargradient' => 'linearGradient',
        'radialgradient' => 'radialGradient',
        'textpath' => 'textPath',
    ];

    /**
     * The HTML Standard's case adjustments of SVG attribute names.
     *
     * @var array<string, string>
     */
    private const SVG_ATTRIBUTES = [
        'attributename' => 'attributeName',
        'attributetype' => 'attributeType',
        'basefrequency' => 'baseFrequency',
        'baseprofile' => 'baseProfile',
        'calcmode' => 'calcMode',
        'clippathunits' => 'clipPathUnits',
        'diffuseconstant' => 'diffuseConstant',
        'edgemode' => 'edgeMode',
        'filterunits' => 'filterUnits',
        'glyphref' => 'glyphRef',
        'gradienttransform' => 'gradientTransform',
        'gradientunits' => 'gradientUnits',
        'kernelmatrix' => 'kernelMatrix',
        'kernelunitlength' => 'kernelUnitLength',
        'keypoints' => 'keyPoints',
        'keysplines' => 'keySplines',
        'keytimes' => 'keyTimes',
        'lengthadjust' => 'lengthAdjust',
        'limitingconeangle' => 'limitingConeAngle',
        'markerheight' => 'markerHeight',
        'markerunits' => 'markerUnits',
        'markerwidth' => 'markerWidth',
        'maskcontentunits' => 'maskContentUnits',
        'maskunits' => 'maskUnits',
        'numoctaves' => 'numOctaves',
        'pathlength' => 'pathLength',
        'patterncontentunits' => 'patternContentUnits',
        'patterntransform' => 'patternTransform',
        'patternunits' => 'patternUnits',
        'pointsatx' => 'pointsAtX',
        'pointsaty' => 'pointsAtY',
        'pointsatz' => 'pointsAtZ',
        'preservealpha' => 'preserveAlpha',
        'preserveaspectratio' => 'preserveAspectRatio',
        'primitiveunits' => 'primitiveUnits',
        'refx' => 'refX',
        'refy' => 'refY',
        'repeatcount' => 'repeatCount',
        'repeatdur' => 'repeatDur',
        'requiredextensions' => 'requiredExtensions',
        'requiredfeatures' => 'requiredFeatures',
        'specularconstant' => 'specularConstant',
        'specularexponent' => 'specularExponent',
        'spreadmethod' => 'spreadMethod',
        'startoffset' => 'startOffset',
        'stddeviation' => 'stdDeviation',
        'stitchtiles' => 'stitchTiles',
        'surfacescale' => 'surfaceScale',
        'systemlanguage' => 'systemLanguage',
        'tablevalues' => 'tableValues',
        'targetx' => 'targetX',
        'targety' => 'targetY',
        'textlength' => 'textLength',
        'viewbox' => 'viewBox',
        'viewtarget' => 'viewTarget',
        'xchannelselector' => 'xChannelSelector',
        'ychannelselector' => 'yChannelSelector',
        'zoomandpan' => 'zoomAndPan',
    ];

    /**
     * The open foreign elements and HTML integration points, innermost last: `[mode, key, name, depth]` with mode
     * `svg`, `math` or `html`, key the name an end tag matches (`mathtext` for a MathML text integration point), depth
     * the number of open HTML elements when it opened.
     *
     * @var array<array{0: string, 1: string, 2: string, 3: int}>
     */
    private array $stack = [];

    /**
     * How often each name is on the stack.
     *
     * @var array<string, int>
     */
    private array $onStack = [];

    /**
     * Open HTML elements by name, so an end tag in foreign content can tell an ancestor from a stray tag.
     *
     * @var array<string, int>
     */
    private array $open = [];

    /**
     * HTML element names in opening order.
     *
     * @var array<string>
     */
    private array $htmlNames = [];

    private int $depth = 0;

    /**
     * Marks an element of the libxml tree as foreign by its place, as the tree builder would, and gives it the names
     * the tree builder gives an SVG or MathML element.
     *
     * @param string $parentSpace `svg`, `math` or `` (HTML): the namespace of the parent.
     * @param \MarkupCarve\Carve\Import\HtmlNode $parent
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     *
     * @return string The namespace of the node.
     */
    public static function place(string $parentSpace, HtmlNode $parent, HtmlNode $node): string
    {
        $name = $node->name;
        if ($parentSpace === '' || self::integrationPoint($parentSpace, $parent, $name)) {
            if ($name !== 'svg' && $name !== 'math') {
                return '';
            }
            $space = $name;
        } else {
            // In foreign content `svg` and `math` stay in the parent's namespace, but for `svg` in `annotation-xml`.
            $space = $name === 'svg' && $parent->name === 'annotation-xml' ? 'svg' : $parentSpace;
        }
        $node->foreign = true;
        $names = $space === 'svg' ? self::SVG_ATTRIBUTES : ['definitionurl' => 'definitionURL'];
        if ($space === 'svg') {
            $node->name = self::SVG_NAMES[$name] ?? $name;
        }
        foreach ($node->attrs as $attribute => $value) {
            if (isset($names[$attribute])) {
                $node->attrs = self::rename($node->attrs, $names);

                break;
            }
        }

        return $space;
    }

    private static function integrationPoint(string $space, HtmlNode $parent, string $child): bool
    {
        if ($space === 'svg') {
            return in_array($parent->name, ['foreignObject', 'desc', 'title'], true);
        }
        if (isset(self::MATH_TEXT[$parent->name])) {
            return $child !== 'mglyph' && $child !== 'malignmark';
        }

        return $parent->name === 'annotation-xml'
            && in_array(strtolower($parent->attrs['encoding'] ?? ''), ['text/html', 'application/xhtml+xml'], true);
    }

    /**
     * The value of a start tag's first attribute whose name matches the pattern, `` for one without a value.
     *
     * @param string $source
     * @param string $name
     *
     * @return string|null
     */
    private static function attribute(string $source, string $name): ?string
    {
        $attributes = substr($source, strcspn($source, " \t\n\f\r/>"));
        preg_match_all('/[\s\/]+([^\s\/>=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/', $attributes, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match('/^' . $name . '$/i', $match[1])) {
                return ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, string> $names
     *
     * @return array<string, string>
     */
    private static function rename(array $attrs, array $names): array
    {
        $renamed = [];
        foreach ($attrs as $attribute => $value) {
            $renamed[$names[$attribute] ?? $attribute] = $value;
        }

        return $renamed;
    }

    public function foreign(): bool
    {
        $top = $this->top();

        return $top !== null && $top[0] !== 'html';
    }

    /**
     * Whether a CDATA section is text here: the current node is a foreign element.
     */
    public function cdata(): bool
    {
        $top = $this->top();

        return $top !== null && ($top[0] !== 'html' || $top[3] === $this->depth);
    }

    /**
     * Whether an HTML element of this name is open, counting no end tag the tree builder implies.
     */
    public function opened(string $name): bool
    {
        return ($this->open[$name] ?? 0) > 0;
    }

    /**
     * The end tag of a raw text element in HTML content, which closes that element and nothing else.
     */
    public function ended(string $name): void
    {
        if (($this->open[$name] ?? 0) > 0) {
            $this->open[$name]--;
            $this->depth--;
            array_pop($this->htmlNames);
        }
    }

    /**
     * Whether an end tag of this name closes an open foreign element.
     */
    public function closesForeign(string $name): bool
    {
        return ($this->onStack[$name] ?? 0) > 0;
    }

    /**
     * An HTML child of a foreign integration point is the current node for end-tag processing. A foreign ancestor's
     * end tag cannot jump over it; the in-body rules ignore that tag until the HTML child closes.
     */
    public function htmlInsideIntegration(): bool
    {
        $top = $this->top();

        return $top !== null && $top[0] === 'html' && $this->depth > $top[3];
    }

    public function foreignAncestor(string $name): bool
    {
        return $this->htmlInsideIntegration() && ($this->onStack[$name] ?? 0) > 0;
    }

    public function openedInsideIntegration(string $name): bool
    {
        $top = $this->top();
        if ($top === null || $top[0] !== 'html') {
            return false;
        }

        return in_array($name, array_slice($this->htmlNames, $top[3]), true);
    }

    /**
     * @param string $name Lowercased tag name.
     * @param bool $end
     * @param bool $selfClosing
     * @param string $source The whole tag, read for the attributes of `font` and `annotation-xml`.
     *
     * @return array<string> The foreign elements a breakout closes, innermost first.
     */
    public function tag(string $name, bool $end, bool $selfClosing, string $source = ''): array
    {
        if ($end) {
            return $this->close($name);
        }
        $top = $this->top();
        $namespace = $top === null || $top[0] === 'html' || ($name === 'svg' && $top[1] === 'annotation-xml');
        if (($name === 'svg' || $name === 'math') && $namespace) {
            $mode = $name;
        } elseif ($top === null || $top[0] === 'html') {
            $mode = $top !== null && $top[1] === 'mathtext' && in_array($name, ['mglyph', 'malignmark'], true) ? 'math' : null;
            if ($mode === null && !$selfClosing && !isset(self::VOIDS[$name])) {
                $this->open[$name] = ($this->open[$name] ?? 0) + 1;
                $this->depth++;
                $this->htmlNames[] = $name;
            }
        } elseif (isset(self::BREAKOUT[$name]) || ($name === 'font' && self::attribute($source, '(?:color|face|size)') !== null)) {
            $closed = $this->leave();
            $this->tag($name, false, $selfClosing);

            return $closed;
        } else {
            $mode = $this->childMode($top[0], $name, $source);
        }
        if ($mode !== null && !$selfClosing) {
            $this->push($mode === 'mathtext' ? ['html', 'mathtext', $name, $this->depth] : [$mode, $name, $name, $this->depth]);
        }

        return [];
    }

    private function childMode(string $parent, string $name, string $source): string
    {
        if ($parent === 'math' && $name === 'annotation-xml') {
            $encoding = strtolower(self::attribute($source, 'encoding') ?? '');

            return in_array($encoding, ['text/html', 'application/xhtml+xml'], true) ? 'html' : 'math';
        }
        if ($parent === 'svg' && in_array($name, ['foreignobject', 'desc', 'title'], true)) {
            return 'html';
        }

        return $parent === 'math' && isset(self::MATH_TEXT[$name]) ? 'mathtext' : $parent;
    }

    /**
     * @param string $name
     *
     * @return array<string>
     */
    private function close(string $name): array
    {
        if ($this->foreign() && in_array($name, self::BREAKOUT_END, true)) {
            return $this->leave();
        }
        $key = isset(self::MATH_TEXT[$name]) ? 'mathtext' : $name;
        if (($this->onStack[$key] ?? 0) > 0) {
            while (($top = $this->pop()) !== null && $top[1] !== $key) {
                // Pops up to and including the element the end tag names.
            }

            return [];
        }
        if (($this->open[$name] ?? 0) === 0) {
            return [];
        }
        $this->open[$name]--;
        $this->depth--;
        array_pop($this->htmlNames);

        // An end tag for an open HTML ancestor closes the foreign content in between.
        return $this->leave();
    }

    /**
     * @return array<string>
     */
    private function leave(): array
    {
        $closed = [];
        while ($this->foreign()) {
            $closed[] = $this->pop()[2] ?? '';
        }

        return $closed;
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: int} $entry
     *
     * @return void
     */
    private function push(array $entry): void
    {
        $this->stack[] = $entry;
        $this->onStack[$entry[1]] = ($this->onStack[$entry[1]] ?? 0) + 1;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int}|null
     */
    private function pop(): ?array
    {
        $entry = array_pop($this->stack);
        if ($entry !== null) {
            $this->onStack[$entry[1]]--;
        }

        return $entry;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int}|null
     */
    private function top(): ?array
    {
        return $this->stack[count($this->stack) - 1] ?? null;
    }
}
