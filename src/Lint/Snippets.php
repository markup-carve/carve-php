<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Snippet\Expander;
use MarkupCarve\Carve\Snippet\Syntax;

/**
 * C2-N-096 and C2-B-236: the snippet rules, which read the authored tree and its registry.
 */
final class Snippets
{
    /**
     * The findings of the two rules on `$document`, as arguments of Context::report().
     *
     * @param \MarkupCarve\Carve\Node $document
     * @param string $source
     *
     * @return array<array{0: string, 1: int, 2: int, 3: string, 4: array<string, string>}>
     */
    public static function findings(Node $document, string $source): array
    {
        if (!str_contains($source, '{$')) {
            return [];
        }
        $out = [];
        $registry = Expander::registry($document);
        $used = $registry === [] ? [] : self::usedNames($document);
        foreach ($document->children ?? [] as $index => $child) {
            if ($child->type === 'snippet_definition') {
                $name = is_string($child->f['name'] ?? null) ? $child->f['name'] : '';
                if ($child->start >= 0 && !isset($used[$name]) && ($registry[$name][0] ?? null) === $child) {
                    $end = $child->start + 2 + strlen($name);
                    $out[] = ['unused-snippet', $child->start + 1, $end, '/children/' . $index, ['name' => $name]];
                }

                continue;
            }
            if ($child->type === 'paragraph') {
                $range = self::definitionId($child, $source);
                if ($range !== null) {
                    $out[] = ['snippet-definition-id', $range[0], $range[1], '/children/' . $index, []];
                }
            }
        }

        return $out;
    }

    /**
     * The names every authored use names, those inside definitions included (C2-N-096).
     *
     * @param \MarkupCarve\Carve\Node $document
     *
     * @return array<string, true>
     */
    public static function usedNames(Node $document): array
    {
        $names = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node->type === 'snippet_use' && is_string($node->f['name'] ?? null)) {
                $names[$node->f['name']] = true;
            }
            Expander::eachList($node, static function (array &$nodes) use (&$pending): void {
                array_push($pending, ...$nodes);
            });
        }

        return $names;
    }

    /**
     * C2-B-236: the marker range of a top-level paragraph whose first line a marker with an id, or an id in the
     * attribute lines above it, made text, or null.
     *
     * @param \MarkupCarve\Carve\Node $paragraph
     * @param string $source
     *
     * @return array{0: int, 1: int}|null
     */
    private static function definitionId(Node $paragraph, string $source): ?array
    {
        $start = $paragraph->start;
        if ($start < 0 || ($start > 0 && $source[$start - 1] !== "\n") || ($source[$start] ?? '') !== '{') {
            return null;
        }
        $lineAttrs = new Attrs();
        $end = self::lineEnd($source, $start);
        while (substr($source, $start, 2) !== '{$' && LineShapes::attributeLine($source, $start, $end, $lineAttrs)) {
            $next = strpos($source, "\n", $start);
            if ($next === false || $next + 1 >= $paragraph->end) {
                return null;
            }
            $start = $next + 1;
            $end = self::lineEnd($source, $start);
        }
        if (substr($source, $start, 2) !== '{$') {
            return null;
        }
        $marker = Syntax::marker($source, $start, $end);
        if ($marker === null || (!$marker[3] && $lineAttrs->id === null)) {
            return null;
        }
        $value = $marker[2];
        if (($source[$value] ?? '') !== ' ' || trim(substr($source, $value + 1, $end - $value - 1)) === '') {
            return null;
        }

        return [$start, $value];
    }

    private static function lineEnd(string $source, int $start): int
    {
        $end = strpos($source, "\n", $start);
        $end = $end === false ? strlen($source) : $end;

        return $end > $start && $source[$end - 1] === "\r" ? $end - 1 : $end;
    }
}
