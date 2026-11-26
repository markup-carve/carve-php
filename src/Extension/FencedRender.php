<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. The diagram fences (`carve.fencedRender.<word>`): the fence body goes to a client library as text, or
 * as JSON in a script element; a host `build` function registers the static channel (C2-X-052).
 */
final class FencedRender
{
    /**
     * The qualified name of one claimed fence word.
     */
    public static function name(string $word): string
    {
        return 'carve.fencedRender.' . $word;
    }

    /**
     * @param array<string>|string $language Fence language word(s) this instance claims.
     * @param string|null $cssClass Class on the output element. Default: the first `language` word.
     * @param string|null $tag `pre` or `div`. Default `div` in json mode, else `pre`.
     * @param string $contentMode `text` or `json`.
     * @param bool $wrapInFigure
     * @param string|null $figureClass Default `{cssClass}-figure`.
     * @param string|null $label Accessible name written with `role="img"`. Default the css class; `''` writes neither.
     * @param (\Closure(string): string)|null $build Diagram source in, self-contained HTML out, for static mode.
     *
     * @throws \InvalidArgumentException
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(
        array|string $language,
        ?string $cssClass = null,
        ?string $tag = null,
        string $contentMode = 'text',
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        $words = array_values(array_filter(is_string($language) ? [$language] : $language, static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            throw new InvalidArgumentException('fencedRender requires at least one non-empty language word');
        }
        if ($contentMode !== 'text' && $contentMode !== 'json') {
            throw new InvalidArgumentException('fencedRender: unknown content mode ' . $contentMode);
        }
        $cssClass ??= $words[0];
        $tag ??= $contentMode === 'json' ? 'div' : 'pre';
        $figureClass ??= $cssClass . '-figure';
        $label ??= $cssClass;
        $figure = static fn (string $html): string => $wrapInFigure
            ? '<figure class="' . RenderHost::escapeAttribute($figureClass) . '">' . $html . '</figure>' : $html;
        $attributes = static fn (Node $node, RenderHost $host): string => $host->attributes(self::named($node, $cssClass, $label));
        $render = static function (Node $node, RenderHost $host) use ($contentMode, $tag, $figure, $attributes): string {
            $source = self::source($node);
            $body = $contentMode === 'json'
                ? '<script type="application/json">' . str_replace('</', '<\/', $source) . '</script>'
                : str_replace(['&', '<'], ['&amp;', '&lt;'], $source);

            return $figure('<' . $tag . $attributes($node, $host) . '>' . $body . '</' . $tag . '>');
        };
        // A `<div>` in static mode: the output is a rendered image, not source text.
        $static = $build === null ? null : static fn (Node $node, RenderHost $host): string => $figure(
            '<div' . $attributes($node, $host) . '>' . $build(self::source($node)) . '</div>',
        );
        $definitions = [];
        foreach ($words as $word) {
            $definitions[] = new ExtensionDefinition(
                self::name($word),
                '1',
                matcher: ['form' => 'fence', 'name' => $word],
                transform: self::transform(...),
                renderer: $render,
                buildTimeRenderer: $static,
            );
        }

        return new Bundle($definitions);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'fence') {
            return null;
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = self::name($claim->name);
        $node->f['version'] = '1';
        $node->f['props'] = ['language' => $claim->name];
        $node->f['payload'] = [['format' => 'text/plain', 'value' => PlainText::str($claim->node->f['value'] ?? '')]];
        $node->f['fallback'] = [self::bareFallback($claim->node)];

        return $node;
    }

    /**
     * C2-X-035: the attributes live on the extension node, so the fallback does not repeat them.
     */
    public static function bareFallback(Node $node): Node
    {
        $copy = Extensions::copy($node);
        unset($copy->f['attrs']);

        return $copy;
    }

    /**
     * The fence body without its final line break, as the client libraries read it.
     */
    public static function source(Node $node): string
    {
        $payload = $node->f['payload'] ?? null;
        $value = is_array($payload) && is_array($payload[0] ?? null) ? ($payload[0]['value'] ?? '') : '';
        $value = is_string($value) ? $value : '';

        return str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
    }

    /**
     * The css class ahead of the author's classes; `role="img"` never without a name, the author's own winning.
     */
    private static function named(Node $node, string $cssClass, string $label): Node
    {
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $authoredName = NodeAttributes::authored($attrs, 'aria-label') || NodeAttributes::authored($attrs, 'aria-labelledby');
        $pairs = [];
        if (!NodeAttributes::authored($attrs, 'role') && ($authoredName || $label !== '')) {
            $pairs[] = ['role', 'img'];
        }
        if ($label !== '' && !$authoredName) {
            $pairs[] = ['aria-label', $label];
        }

        return NodeAttributes::with($node, [$cssClass], $pairs);
    }

    /**
     * The `mermaid` preset; the parameters are those of `bundle()`.
     */
    public static function mermaid(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('mermaid', $cssClass, $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `d2` preset; the parameters are those of `bundle()`.
     */
    public static function d2(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('d2', $cssClass, $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `dot, graphviz` preset; the parameters are those of `bundle()`.
     */
    public static function graphviz(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle(['dot', 'graphviz'], $cssClass ?? 'graphviz', $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `wavedrom` preset; the parameters are those of `bundle()`.
     */
    public static function wavedrom(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('wavedrom', $cssClass, $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `abc` preset; the parameters are those of `bundle()`.
     */
    public static function abc(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('abc', $cssClass, $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `plantuml, puml` preset; the parameters are those of `bundle()`.
     */
    public static function plantuml(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle(['plantuml', 'puml'], $cssClass ?? 'plantuml', $tag, 'text', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `vega-lite` preset; the parameters are those of `bundle()`.
     */
    public static function vegaLite(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('vega-lite', $cssClass, $tag, 'json', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * The `chart` preset; the parameters are those of `bundle()`.
     */
    public static function chart(
        ?string $cssClass = null,
        ?string $tag = null,
        bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
        ?Closure $build = null,
    ): Bundle {
        return self::bundle('chart', $cssClass, $tag, 'json', $wrapInFigure, $figureClass, $label, $build);
    }

    /**
     * Every diagram preset; `$builders` maps a preset's css class (`mermaid`, `d2`, `graphviz`, `wavedrom`, `abc`,
     * `plantuml`, `vega-lite`, `chart`) to its build-time renderer.
     *
     * @param array<string, \Closure(string): string> $builders
     */
    public static function presets(array $builders = []): Bundle
    {
        return Bundle::merge(
            self::mermaid(build: $builders['mermaid'] ?? null),
            self::d2(build: $builders['d2'] ?? null),
            self::graphviz(build: $builders['graphviz'] ?? null),
            self::wavedrom(build: $builders['wavedrom'] ?? null),
            self::abc(build: $builders['abc'] ?? null),
            self::plantuml(build: $builders['plantuml'] ?? null),
            self::vegaLite(build: $builders['vega-lite'] ?? null),
            self::chart(build: $builders['chart'] ?? null),
        );
    }
}
