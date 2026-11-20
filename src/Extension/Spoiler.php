<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `:spoiler[text]` (`carve.spoiler`) and `::: spoiler "Title"` (`carve.spoilerBlock`): hidden by the host
 * stylesheet or a disclosure, revealed in static mode.
 */
final class Spoiler
{
    public static function bundle(): Bundle
    {
        $inline = new ExtensionDefinition(
            'carve.spoiler',
            '1',
            ['content'],
            matcher: ['form' => 'inline', 'name' => 'spoiler'],
            transform: self::transform(...),
            renderer: static fn (Node $node, RenderHost $host): string => self::span($node, $host, ['spoiler']),
            // Hiding means nothing offline, so static output is revealed.
            buildTimeRenderer: static fn (Node $node, RenderHost $host): string => self::span(
                $node,
                $host,
                ['spoiler', 'spoiler-revealed'],
            ),
        );
        $block = Details::definition(
            'carve.spoilerBlock',
            'spoiler',
            static fn (Node $node, RenderHost $host): string => Details::disclosure(
                $node,
                $host,
                $host->label('spoiler', 'Spoiler'),
                'spoiler',
            ),
            self::section(...),
        );

        return new Bundle([$inline, $block]);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'inline') {
            return null;
        }
        $children = $claim->node->children ?? [];
        $node = new Node('inline_extension', []);
        $node->f['name'] = 'carve.spoiler';
        $node->f['version'] = '1';
        $node->f['slots'] = ['content' => $children];
        $node->f['fallback'] = array_map(Extensions::copy(...), $children);

        return $node;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     * @param array<string> $classes
     */
    private static function span(Node $node, RenderHost $host, array $classes): string
    {
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? clone $attrs : new Attrs();
        $attrs->classes = [...$classes, ...$attrs->classes];
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];

        return '<span' . AuthorAttrs::write($attrs, $host) . '>' . ($host->inlines)(PlainText::nodes($slots['content'] ?? [])) . '</span>';
    }

    private static function section(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        $summary = PlainText::nodes($slots['summary'] ?? []);
        $title = $summary === [] ? '' : trim(($host->inlines)($summary));
        $title = $title === '' ? RenderHost::escapeText($host->label('spoiler', 'Spoiler')) : $title;
        $heading = '<h3 class="spoiler-title">' . $title . '</h3>';
        $base = clone $node;
        $attrs = $node->f['attrs'] ?? null;
        $base->f['attrs'] = AuthorAttrs::withClass($attrs instanceof Attrs ? $attrs : null, 'spoiler-revealed');
        $body = PlainText::nodes($slots['body'] ?? []);

        return Details::container(
            '<section' . Details::attrs($base, $host, 'spoiler') . '>',
            $heading,
            ($host->blocks)($body),
            '</section>',
            $host,
            Details::hasRawBlock($body),
        );
    }
}
