<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

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
                    $out[] = ['unused-snippet', $child->start + 1, $child->start + 2 + strlen($name), '/children/' . $index, ['name' => $name]];
                }

                continue;
            }
            if ($child->type === 'paragraph') {
                $id = self::definitionId($child, $source);
                if ($id !== null) {
                    $out[] = ['snippet-definition-id', $child->start, $id, '/children/' . $index, []];
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
    private static function usedNames(Node $document): array
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
     * C2-B-236: the end of the marker of a top-level paragraph whose first line a marker with an id made text, or null.
     *
     * @param \MarkupCarve\Carve\Node $paragraph
     * @param string $source
     *
     * @return int|null
     */
    private static function definitionId(Node $paragraph, string $source): ?int
    {
        $start = $paragraph->start;
        if ($start < 0 || ($start > 0 && $source[$start - 1] !== "\n") || substr($source, $start, 2) !== '{$') {
            return null;
        }
        $end = strpos($source, "\n", $start);
        $end = $end === false ? strlen($source) : $end;
        $marker = Syntax::marker($source, $start, $end);
        if ($marker === null || !$marker[3]) {
            return null;
        }
        $value = $marker[2];
        if (($source[$value] ?? '') !== ' ' || trim(substr($source, $value + 1, $end - $value - 1)) === '') {
            return null;
        }

        return $value;
    }
}
