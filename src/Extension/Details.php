<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `::: details "Title"` as a native disclosure (`carve.details`); static mode writes it expanded.
 */
final class Details
{
    public static function bundle(): Bundle
    {
        $render = static fn (Node $node, RenderHost $host): string => self::disclosure($node, $host, $host->label('details', 'Details'));

        return new Bundle([self::definition('carve.details', 'details', $render, $render)]);
    }

    /**
     * The definition of a titled div with the slots `summary` and `body`.
     *
     * @param string $name
     * @param string $source
     * @param \Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string $render
     * @param \Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string $static
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function definition(string $name, string $source, Closure $render, Closure $static): ExtensionDefinition
    {
        return new ExtensionDefinition(
            $name,
            '1',
            ['summary', 'body'],
            matcher: ['form' => 'div', 'name' => $source],
            transform: static function (Claim $claim) use ($name): ?Node {
                if ($claim->form !== 'div') {
                    return null;
                }
                $title = PlainText::nodes($claim->node->f['title'] ?? []);
                $body = $claim->node->children ?? [];
                $node = new Node('block_extension', []);
                $node->f['name'] = $name;
                $node->f['version'] = '1';
                $node->f['slots'] = ['summary' => $title, 'body' => $body];
                $node->f['fallback'] = self::titledFallback($title, $body);

                return $node;
            },
            renderer: $render,
            buildTimeRenderer: $static,
        );
    }

    /**
     * C2-X-042 precedent: the title as a `strong` paragraph, then the body.
     *
     * @param array<\MarkupCarve\Carve\Node> $title
     * @param array<\MarkupCarve\Carve\Node> $body
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function titledFallback(array $title, array $body): array
    {
        $out = $title === [] ? [] : [new Node('paragraph', [new Node('strong', array_map(Extensions::copy(...), $title))])];

        return [...$out, ...array_map(Extensions::copy(...), $body)];
    }

    /**
     * Writes `head`, the body one level in, then `tail`, indented to the block depth like core containers.
     */
    public static function container(string $head, string $inner, string $body, string $tail, RenderHost $host): string
    {
        $lines = [$head, '  ' . $inner];
        if ($body !== '') {
            $lines[] = (string)preg_replace('/^(?=.)/m', '  ', $body);
        }
        $lines[] = $tail;

        return str_replace("\n", "\n" . str_repeat('  ', $host->blockDepth), implode("\n", $lines));
    }

    public static function attrs(Node $node, RenderHost $host, ?string $base = null): string
    {
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;

        return AuthorAttrs::write($base === null ? $attrs : AuthorAttrs::withClass($attrs, $base), $host)
            . $host->attributes($node, true, true);
    }

    /**
     * The disclosure element of a titled div; `open` is added in static mode unless authored.
     */
    public static function disclosure(Node $node, RenderHost $host, string $label, ?string $base = null): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        $summary = PlainText::nodes($slots['summary'] ?? []);
        $text = $summary === [] ? '' : trim(($host->inlines)($summary));
        $attrs = $node->f['attrs'] ?? null;
        $authoredOpen = AuthorAttrs::value($attrs instanceof Attrs ? $attrs : null, 'open') !== null;
        $open = $host->mode === 'static' && !$authoredOpen ? ' open' : '';

        return self::container(
            '<details' . $open . self::attrs($node, $host, $base) . '>',
            '<summary>' . ($text === '' ? RenderHost::escapeText($label) : $text) . '</summary>',
            ($host->blocks)(PlainText::nodes($slots['body'] ?? [])),
            '</details>',
            $host,
        );
    }
}
