<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use LogicException;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Snippet\Expander;

final class SnippetFootnotes
{
    /**
     * Source-local references in winning snippet definitions. The source lint sees these even before a copy renders.
     *
     * @param array<string, mixed> $document
     *
     * @return array{0: array<string, true>, 1: list<array{0: int, 1: int, 2: string, 3: string}>}
     */
    public static function authored(array $document): array
    {
        $notes = self::localNotes($document);
        $winners = [];
        $used = [];
        $unresolved = [];
        $stack = [[$document, '', false]];
        while ($stack !== []) {
            [$node, $path, $inSnippet] = array_pop($stack);
            if (($node['type'] ?? null) === 'snippet_definition') {
                $name = $node['name'] ?? null;
                if (!is_string($name) || isset($winners[$name])) {
                    continue;
                }
                $winners[$name] = true;
                $inSnippet = true;
            }
            if ($inSnippet && ($node['type'] ?? null) === 'footnote_ref' && is_string($node['key'] ?? null)) {
                $range = Support::bounds($node);
                if (isset($notes[$node['key']])) {
                    $used["\0" . $notes[$node['key']]] = true;
                } elseif ($range !== null) {
                    $unresolved[] = [$range[0], $range[1], $path, $node['key']];
                }
            }
            array_push($stack, ...array_reverse(self::children($node, $path, $inSnippet)));
        }

        return [$used, $unresolved];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, int>
     */
    private static function localNotes(array $document): array
    {
        $notes = [];
        foreach (is_array($document['children'] ?? null) ? $document['children'] : [] as $child) {
            if (!is_array($child) || ($child['type'] ?? null) !== 'footnote' || !is_string($child['key'] ?? null)) {
                continue;
            }
            $start = Support::bounds($child)[0] ?? null;
            if ($start !== null) {
                $notes[$child['key']] ??= $start;
            }
        }

        return $notes;
    }

    /**
     * @param array<string, mixed> $node
     * @param bool $inSnippet
     * @param string $path
     *
     * @return list<array{0: array<string, mixed>, 1: string, 2: bool}>
     */
    private static function children(array $node, string $path, bool $inSnippet): array
    {
        $children = [];
        foreach ($node as $key => $value) {
            if ($key === 'pos' || $key === 'attrs' || !is_array($value)) {
                continue;
            }
            if (is_string($value['type'] ?? null)) {
                $children[] = [$value, $path . '/' . $key, $inSnippet];

                continue;
            }
            foreach ($value as $index => $child) {
                if (is_int($index) && is_array($child) && is_string($child['type'] ?? null)) {
                    $children[] = [$child, $path . '/' . $key . '/' . $index, $inSnippet];
                }
            }
        }

        return $children;
    }

    /**
     * @param \MarkupCarve\Carve\Node $document The authored, resolved document, with includes already expanded if enabled.
     *
     * @throws \LogicException
     *
     * @return array<string, true> Definition file and start offset.
     */
    public static function used(Node $document): array
    {
        $copy = Extensions::copy($document);
        Expander::expand($copy, false);
        $resolution = Resolver::resolve($copy, null, Options::from([], ['parse', 'resolve']), true);
        $facts = $resolution->facts ?? throw new LogicException('Footnote lint resolution has no facts');
        $used = [];
        foreach ($facts->noteMarks as $index) {
            $definition = $facts->nodes[$index]->noteDefinition;
            if ($definition !== null && $definition->start >= 0) {
                $used[($definition->file ?? '') . "\0" . $definition->start] = true;
            }
        }

        return $used;
    }
}
