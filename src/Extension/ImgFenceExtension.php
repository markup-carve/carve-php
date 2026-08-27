<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * SVG `img` fence (Tier-3, ships off). Claims fenced blocks whose info word is
 * `img` (alias `image`) and renders the SVG **body** — sanitized — rather than
 * showing it as verbatim source. `svg` / `xml` are deliberately NOT claimed, so
 * an author can still syntax-highlight SVG source with those words.
 */
class ImgFenceExtension implements StaticRenderExtensionInterface
{
    /**
     * Fence attributes the extension consumes rather than emitting: the inline
     * mode flag, the `alt` text, and the now-redundant `sandbox` marker (sandbox
     * is the default; kept consumed so an explicit `{sandbox}` doesn't leak as
     * an attribute).
     *
     * @var array<string>
     */
    private const CONSUMED_KEYS = ['inline', 'alt', 'sandbox'];

    /**
     * @var array<string>
     */
    protected array $languages;

    protected SvgSanitizeOptions $options;

    protected ?HtmlRenderer $renderer = null;

    /**
     * @param array<string>|string|null $language Fence info word(s) this instance
     *   claims. Default `['img', 'image']`.
     * @param bool $allowStyle Keep the `style` attribute (value scrubbed).
     * @param bool $allowLinks Keep `<a>` and external `href`/`xlink:href`.
     * @param bool $allowAnimation Keep SMIL animation elements.
     * @param bool $allowExternalImages Keep `<image>` and its external raster `href`.
     * @param bool $allowInline Permit inline rendering for fences carrying an
     *   `{inline}` attribute. Default false: every fence is sandboxed and
     *   `{inline}` is ignored. HOST decision on purpose.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array|string|null $language = null,
        bool $allowStyle = false,
        bool $allowLinks = false,
        bool $allowAnimation = false,
        bool $allowExternalImages = false,
        protected bool $allowInline = false,
    ) {
        $languages = $language === null
            ? ['img', 'image']
            : array_values(array_filter((array)$language, fn (string $word): bool => $word !== ''));
        if ($languages === []) {
            throw new InvalidArgumentException('ImgFenceExtension requires at least one non-empty language word.');
        }

        $this->languages = $languages;
        $this->options = new SvgSanitizeOptions(
            allowStyle: $allowStyle,
            allowLinks: $allowLinks,
            allowAnimation: $allowAnimation,
            allowExternalImages: $allowExternalImages,
        );
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $this->renderer = $renderer;
        }

        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock || $this->renderer === null) {
                return;
            }
            if (!in_array($node->getLanguage(), $this->languages, true)) {
                return;
            }

            $event->setHtml($this->renderCodeBlock($node, $this->renderer));
        });
    }

    /**
     * Static render: inline SVG needs no client script — the interactive output
     * is already static, so the static render is byte-identical.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();
        if (!$node instanceof CodeBlock) {
            return false;
        }
        if (!in_array($node->getLanguage(), $this->languages, true)) {
            return false;
        }

        $event->setHtml($this->renderCodeBlock($node, $renderer));

        return true;
    }

    /**
     * Fall back to the SVG's own `<title>` for the `<img>` alt text when the
     * author gave no `{alt=…}`, so a sandboxed image is described to assistive
     * tech instead of being silently decorative (empty alt). The svg is already
     * sanitized, so this is a plain extraction; the result is escaped again on
     * output. Returns null when there is no non-empty title.
     */
    protected static function svgTitle(string $svg): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $svg, $m) !== 1) {
            return null;
        }

        $text = str_replace(
            ['&lt;', '&gt;', '&quot;', '&#39;', '&amp;'],
            ['<', '>', '"', "'", '&'],
            strip_tags($m[1]),
        );
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Render a claimed `img` fence to sandboxed `<img>` or (opt-in) inline SVG.
     */
    protected function renderCodeBlock(CodeBlock $node, HtmlRenderer $renderer): string
    {
        $result = SvgSanitizer::sanitize($node->getContent(), $this->options);
        if (!$result['ok']) {
            return $this->sourceFallback($node);
        }

        // Inline is a HOST capability: the `{inline}` fence flag only takes
        // effect when the host opted in with allowInline. Otherwise (the default,
        // and the safe posture for untrusted input) the fence is sandboxed and
        // `{inline}` is ignored — an author cannot self-elevate out of the sandbox.
        $inline = $this->allowInline && $this->consumedValue($node, 'inline') !== null;

        if (!$inline) {
            $alt = $this->consumedValue($node, 'alt') ?? self::svgTitle($result['svg']) ?? '';
            $src = 'data:image/svg+xml,' . self::encodeUriComponent($result['svg']);
            // Sandbox mode promises no fetches: drop any author source-selection
            // attribute (`src`, `srcset`) so it cannot override the sanitized
            // data URI with an external resource.
            $imgAttrs = $this->renderAuthorAttrs($node, $renderer, ['src', 'srcset']);

            return '<img src="' . $renderer->escapeAttribute($src) . '" alt="'
                . $renderer->escapeAttribute($alt) . '"' . $imgAttrs . ">\n";
        }

        $fenceAttrs = $this->renderAuthorAttrs($node, $renderer, []);
        if ($fenceAttrs === '') {
            return $result['svg'] . "\n";
        }
        // Fence attributes land on the root <svg>, so they must clear the SAME
        // SVG-specific scrub as the body — otherwise a `{fill="url(https://…)"}`
        // would reintroduce a remote fetch the sanitizer just removed. Splice
        // them onto the root, then re-sanitize (idempotent for a clean body).
        $merged = SvgSanitizer::sanitize(self::mergeIntoRoot($result['svg'], $fenceAttrs), $this->options);

        return $merged['ok'] ? $merged['svg'] . "\n" : $this->sourceFallback($node);
    }

    /**
     * Case-insensitive lookup of a consumed key in the node's attributes,
     * matching how CONSUMED_KEYS are stripped — so `{Sandbox}` / `{ALT=…}` are
     * honored, not silently dropped. Returns null when absent.
     */
    protected function consumedValue(CodeBlock $node, string $key): ?string
    {
        foreach ($node->getAttributes() as $name => $value) {
            if (strtolower((string)$name) === $key) {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Render the author fence attributes (minus the consumed keys and any extra
     * stripped names) through the SAME core hardening every other element gets
     * (strip event handlers / dangerous schemes / expression(), plus safe-mode
     * name filtering), so a `{onclick=…}` on the fence cannot smuggle a handler.
     *
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $extraStrip Additional attribute names to drop.
     */
    protected function renderAuthorAttrs(CodeBlock $node, HtmlRenderer $renderer, array $extraStrip): string
    {
        $drop = self::CONSUMED_KEYS;
        foreach ($extraStrip as $name) {
            $drop[] = strtolower($name);
        }

        $attrs = [];
        foreach ($node->getAttributes() as $name => $value) {
            if (in_array(strtolower((string)$name), $drop, true)) {
                continue;
            }
            $attrs[(string)$name] = (string)$value;
        }

        // Always-on hardening, then optional safe-mode name filtering — matching
        // HtmlRenderer::getRenderableAttributes().
        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }
        if (isset($attrs['class']) && $attrs['class'] !== '') {
            $classes = preg_split('/\s+/', trim($attrs['class'])) ?: [];
            $attrs['class'] = implode(' ', array_values(array_unique($classes)));
        }

        return $renderer->renderAttributeArray($attrs);
    }

    /**
     * A self-contained escaped code-block fallback: never blank, never raw.
     */
    protected function sourceFallback(CodeBlock $node): string
    {
        $lang = $node->getLanguage();
        $langAttr = $lang !== null && $lang !== ''
            ? ' class="language-' . StringUtil::escapeHtml($lang) . '"'
            : '';

        return '<pre><code' . $langAttr . '>' . StringUtil::escapeHtml($node->getContent()) . "\n</code></pre>\n";
    }

    /**
     * Splice a rendered attr string (` id="…" class="…"`) into the root <svg>
     * tag. The fence attributes win: any attribute the fence sets is first
     * removed from the sanitized root so the merge never emits a duplicate
     * attribute. Attributes only the root has are preserved.
     */
    protected static function mergeIntoRoot(string $svg, string $attrStr): string
    {
        if ($attrStr === '') {
            return $svg;
        }
        preg_match_all('/\s([A-Za-z_:][\w:.-]*)\s*=/', $attrStr, $nameMatches);
        $fenceNames = array_map('strtolower', $nameMatches[1]);

        // Match the root tag quote-aware so a `>` inside a quoted attribute value
        // (e.g. aria-label="1>2") is not mistaken for the tag's end.
        $result = preg_replace_callback(
            '/^<svg((?:"[^"]*"|\'[^\']*\'|[^>])*?)(\/?)>/i',
            static function (array $m) use ($attrStr, $fenceNames): string {
                $cleaned = $m[1];
                $slash = $m[2];
                foreach ($fenceNames as $name) {
                    $esc = preg_quote($name, '/');
                    $cleaned = preg_replace(
                        '/\s' . $esc . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
                        '',
                        $cleaned,
                        1,
                    ) ?? $cleaned;
                }

                return '<svg' . $attrStr . $cleaned . $slash . '>';
            },
            $svg,
            1,
        );

        return $result ?? $svg;
    }

    /**
     * Percent-encode like JavaScript `encodeURIComponent`: it leaves the marks
     * `!*'()~-_.` unencoded and encodes a space as `%20`. PHP `rawurlencode`
     * differs only by additionally encoding `! * ' ( )`, so decode those back.
     */
    protected static function encodeUriComponent(string $value): string
    {
        return strtr(rawurlencode($value), [
            '%21' => '!',
            '%2A' => '*',
            '%27' => "'",
            '%28' => '(',
            '%29' => ')',
        ]);
    }
}
