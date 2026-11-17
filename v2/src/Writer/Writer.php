<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use MarkupCarve\Carve2\Carve2;

/**
 * The canonical writer (`writer.md`): a tree, and optionally the layout sidecar returned with it (C2-W-001), to Carve
 * source. Options: `alignTables` (C2-W-068), `normalize` (C2-W-127).
 */
final class Writer
{
    /**
     * C2-W-134: the nearest source and the report.
     *
     * @param array<string, mixed> $tree A decoded 2.0 tree.
     * @param array<mixed>|null $layout A layout record (`source-layout.md`).
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\Writer\WriteResult
     */
    public static function writeWithDiagnostics(array $tree, ?array $layout = null, array $options = []): WriteResult
    {
        $w = new State(self::facts($layout), ($options['alignTables'] ?? false) === true, ($options['normalize'] ?? false) === true);
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        self::collect($children, $w);
        self::rootLosses($tree, $children, $w);
        (new Blocks($w))->blocks($children, '/children', '');
        while ($w->lines !== [] && end($w->lines) === '') {
            array_pop($w->lines);
        }
        if ($w->lines === []) {
            return new WriteResult('', $w->losses);
        }
        $source = implode("\n", $w->lines) . "\n";
        // C2-W-099: a first block that would read as another tree on line 1 stands below one blank line.
        $first = is_array($children[0] ?? null) ? $children[0] : [];
        if (str_starts_with($source, '---') && ($first['type'] ?? '') !== 'frontmatter') {
            $source = "\n" . $source;
        }

        return new WriteResult($source, $w->losses);
    }

    /**
     * The sidecar entries by path (C2-L-010).
     *
     * @param array<mixed>|null $layout
     *
     * @return array<string, array<mixed>>
     */
    private static function facts(?array $layout): array
    {
        $facts = [];
        foreach (is_array($layout['nodes'] ?? null) ? $layout['nodes'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $facts[$entry['path']] = $entry;
            }
        }

        return $facts;
    }

    /**
     * C2-W-134: the source alone; a loss throws.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve2\Writer\CarveWriteError
     *
     * @return string
     */
    public static function write(array $tree, ?array $layout = null, array $options = []): string
    {
        $result = self::writeWithDiagnostics($tree, $layout, $options);
        if ($result->diagnostics !== []) {
            throw new CarveWriteError($result->diagnostics);
        }

        return $result->source;
    }

    /**
     * `carve fmt`: parse with the sidecar, then write (C2-W-001, C2-W-087).
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<mixed>|null $layout The sidecar of this parse, where the caller has one.
     *
     * @return \MarkupCarve\Carve2\Writer\WriteResult
     */
    public static function formatWithDiagnostics(string $source, array $options = [], ?array $layout = null): WriteResult
    {
        $tree = json_decode(Carve2::toJson($source), true, 100000, JSON_THROW_ON_ERROR);

        return self::writeWithDiagnostics(is_array($tree) ? $tree : [], $layout, $options);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<mixed>|null $layout
     *
     * @throws \MarkupCarve\Carve2\Writer\CarveWriteError
     *
     * @return string
     */
    public static function format(string $source, array $options = [], ?array $layout = null): string
    {
        $result = self::formatWithDiagnostics($source, $options, $layout);
        if ($result->diagnostics !== []) {
            throw new CarveWriteError($result->diagnostics);
        }

        return $result->source;
    }

    /**
     * @param array<mixed> $nodes
     * @param \MarkupCarve\Carve2\Writer\State $w
     *
     * @return void
     */
    private static function collect(array $nodes, State $w): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'abbreviation_def' && is_string($node['key'] ?? null)) {
                $w->abbreviations[$node['key']] = true;
            }
            if (is_array($node['children'] ?? null)) {
                self::collect($node['children'], $w);
            }
        }
    }

    /**
     * C2-W-011, C2-W-117: root `metadata` has no spelling, except `fields` beside a frontmatter node.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed> $children
     * @param \MarkupCarve\Carve2\Writer\State $w
     *
     * @return void
     */
    private static function rootLosses(array $tree, array $children, State $w): void
    {
        $metadata = is_array($tree['metadata'] ?? null) ? $tree['metadata'] : [];
        $frontmatter = false;
        foreach ($children as $child) {
            $frontmatter = $frontmatter || (is_array($child) && ($child['type'] ?? '') === 'frontmatter');
        }
        foreach (array_keys($metadata) as $field) {
            if ($field !== 'fields' || !$frontmatter) {
                $w->lose('', 'document', 'metadata/' . $field);
            }
        }
        foreach (['attrs', 'lang', 'dir', 'data'] as $field) {
            if (isset($tree[$field])) {
                $w->lose('', 'document', $field);
            }
        }
    }
}
