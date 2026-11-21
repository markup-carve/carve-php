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
     *
     * @param string $head
     * @param string $inner
     * @param array<\MarkupCarve\Carve\Node> $body
     * @param string $tail
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     */
    public static function container(
        string $head,
        string $inner,
        array $body,
        string $tail,
        RenderHost $host,
    ): string {
        $pad = str_repeat('  ', $host->blockDepth);
        $lines = [$head, $pad . '  ' . $inner];
        foreach ($body as $block) {
            $html = ($host->blocks)([$block]);
            if ($html === '') {
                continue;
            }
            $rawRanges = [];
            $searchFrom = 0;
            foreach (self::rawValues([$block]) as $value) {
                $at = strpos($html, $value, $searchFrom);
                while ($at !== false && $at > 0 && $html[$at - 1] !== "\n") {
                    $at = strpos($html, $value, $at + 1);
                }
                if ($at !== false) {
                    $rawRanges[] = [$at, $at + strlen($value)];
                    $searchFrom = $at + strlen($value);
                }
            }
            $literal = false;
            $rendered = [];
            $offset = 0;
            foreach (explode("\n", $html) as $line) {
                $rawLine = false;
                foreach ($rawRanges as [$start, $end]) {
                    if ($offset >= $start && $offset < $end) {
                        $rawLine = true;

                        break;
                    }
                }
                $rendered[] = $rawLine || $literal || $line === '' ? $line : $pad . '  ' . $line;
                // An HTML pre/textarea element owns the whitespace of every
                // following line, including its closing tag line.
                foreach (preg_split('/(<\/?(?:pre|textarea)\b[^>]*>)/i', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
                    if (preg_match('/^<(pre|textarea)\b/i', $part) === 1) {
                        $literal = true;
                    } elseif (preg_match('/^<\/(pre|textarea)\b/i', $part) === 1) {
                        $literal = false;
                    }
                }
                $offset += strlen($line) + 1;
            }
            $lines[] = implode("\n", $rendered);
        }
        $lines[] = $pad . $tail;

        return implode("\n", $lines);
    }

    /**
     * The raw HTML payloads in document order, whose lines must remain byte exact.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<string>
     */
    private static function rawValues(array $nodes): array
    {
        $values = [];
        foreach ($nodes as $node) {
            if ($node->type === 'raw_block' && ($node->f['format'] ?? null) === 'html') {
                $value = PlainText::str($node->f['value'] ?? '');
                $values[] = str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
            }
            array_push($values, ...self::rawValues($node->children ?? []));
        }

        return $values;
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
            PlainText::nodes($slots['body'] ?? []),
            '</details>',
            $host,
        );
    }
}
