<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\Text;
use MarkupCarve\Carve\Writer\Writer;

/**
 * A `GroupWriter` over the PHP block writer: the group is written as a document of its own, with the escapes of the
 * state keyed by the paths the group's nodes take there. The paragraphs' places come from the marked write.
 */
final class TreeGroupWriter implements GroupWriter
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options = [])
    {
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    public function write(WriterState $w, array $group): void
    {
        [$tree, $escapes] = self::document($w, $group);
        $candidate = Writer::bareCandidate($tree, null, $this->options, $escapes);
        $source = $candidate['source'];
        $w->lines = explode("\n", str_ends_with($source, "\n") ? substr($source, 0, -1) : $source);
        $w->starts = [];
        $lineStarts = [0];
        for ($at = strpos($source, "\n"); $at !== false; $at = strpos($source, "\n", $at + 1)) {
            $lineStarts[] = $at + 1;
        }
        foreach ($group as $at => $node) {
            self::notePlaces($w, $node, "/children/{$at}", $candidate['ranges'], $lineStarts);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public function pairEscapes(WriterState $w, array $group): array
    {
        [$tree, $escapes] = self::document($w, $group);
        $texts = [];
        foreach ($group as $at => $node) {
            self::noteTexts($node, "/children/{$at}", $texts);
        }
        $found = [];
        foreach (Text::treePairOpeners($tree, $escapes) as $path => $offsets) {
            $text = $texts[$path] ?? null;
            if ($text === null) {
                continue;
            }
            foreach (array_keys($offsets) as $offset) {
                if (!isset($escapes[$path][$offset])) {
                    $found[] = new Escape($text, $offset);
                }
            }
        }

        return $found;
    }

    /**
     * The group as a document of its own, with the escapes of `w` by the paths its text nodes take there.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return array{array<string, mixed>, array<string, array<int, true>>}
     */
    private static function document(WriterState $w, array $group): array
    {
        $roots = [];
        $children = [];
        foreach ($group as $at => $node) {
            $roots[$node->path] = "/children/{$at}";
            $children[] = $node->toArray();
        }
        $escapes = [];
        foreach ($w->escapes as $id => $offsets) {
            $path = self::rebased($w->escaped[$id]->path ?? null, $roots);
            if ($path === null) {
                continue;
            }
            foreach ($offsets as $offset) {
                $escapes[$path][$offset] = true;
            }
        }

        return [['type' => 'document', 'children' => $children], $escapes];
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param string $path
     * @param array<string, \MarkupCarve\Carve\Writer\Check\Node> $texts
     */
    private static function noteTexts(Node $node, string $path, array &$texts): void
    {
        if ($node->type === 'text') {
            $texts[$path] = $node;
        }
        foreach ($node->slots as $name => $list) {
            foreach ($list as $at => $child) {
                $own = $child->path === "{$node->path}/{$name}" ? "{$path}/{$name}" : "{$path}/{$name}/{$at}";
                self::noteTexts($child, $own, $texts);
            }
        }
    }

    /**
     * @param string|null $path
     * @param array<string, string> $roots
     */
    private static function rebased(?string $path, array $roots): ?string
    {
        if ($path === null) {
            return null;
        }
        foreach ($roots as $from => $to) {
            if ($path === $from || str_starts_with($path, $from . '/')) {
                return $to . substr($path, strlen($from));
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param string $path
     * @param array<string, array{int, int}> $ranges
     * @param list<int> $lineStarts
     */
    private static function notePlaces(WriterState $w, Node $node, string $path, array $ranges, array $lineStarts): void
    {
        if ($node->type === 'paragraph') {
            // The marked write ranges the inlines, so a paragraph starts where its first inline starts.
            $start = null;
            foreach ($ranges as $own => [$from]) {
                if (str_starts_with($own, $path . '/') && ($start === null || $from < $start)) {
                    $start = $from;
                }
            }
            if ($start === null) {
                return;
            }
            $line = 0;
            while (isset($lineStarts[$line + 1]) && $lineStarts[$line + 1] <= $start) {
                $line++;
            }
            $column = $start - $lineStarts[$line];
            if ($column < WriterState::PLACE_COLUMNS) {
                $w->starts[] = [$node, $line * WriterState::PLACE_COLUMNS + $column];
            }

            return;
        }
        foreach ($node->slots as $name => $list) {
            foreach ($list as $at => $child) {
                $own = $child->path === "{$node->path}/{$name}" ? "{$path}/{$name}" : "{$path}/{$name}/{$at}";
                self::notePlaces($w, $child, $own, $ranges, $lineStarts);
            }
        }
    }
}
