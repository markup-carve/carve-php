<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\Inline;
use MarkupCarve\Carve\Writer\LinkFallback;
use MarkupCarve\Carve\Writer\PairGlue;
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
        if (self::escapeLoneLinkBrackets($w, $group, $tree)) {
            [$tree, $escapes] = self::document($w, $group);
        }
        PairGlue::start();
        try {
            $candidate = Writer::bareCandidate($tree, null, $this->options, $escapes);
        } finally {
            $glued = PairGlue::take();
        }
        if ($glued !== []) {
            self::glue($w, $group, $glued);
        }
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
     * carve-js `gluedPart`: a pair the write braced for its neighbors takes the braced form, glued, so the next round
     * decides it again (carve#6420).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<string, true> $paths
     */
    private static function glue(WriterState $w, array $group, array $paths): void
    {
        $pending = [];
        foreach ($group as $at => $node) {
            $pending[] = [$node, "/children/{$at}"];
        }
        while (($item = array_pop($pending)) !== null) {
            [$node, $path] = $item;
            if (isset($paths[$path])) {
                $id = spl_object_id($node);
                if (!isset($w->forms[$id])) {
                    $w->glued[$id] = true;
                }
                $w->forms[$id] = 1;
            }
            foreach ($node->slots as $name => $list) {
                foreach ($list as $at => $child) {
                    $single = $child->path === "{$node->path}/{$name}";
                    $pending[] = [$child, $single ? "{$path}/{$name}" : "{$path}/{$name}/{$at}"];
                }
            }
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
     * C2-W-125 case five: a bracket without a partner in link text would take the link's own closer. The rounds write
     * these brackets bare until their first parse asks for a change inside a link (`linkFallback`), so a bare closer
     * reports the kinds of what it lets form (C2-W-088, carve#6656). True where the escapes of `w` changed.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<string, mixed> $tree
     */
    private static function escapeLoneLinkBrackets(WriterState $w, array $group, array $tree): bool
    {
        [$positions] = LinkFallback::positions($tree);
        if ($positions === []) {
            return false;
        }
        $w->sawLink = true;
        if (!$w->linkFallback) {
            return false;
        }
        $texts = [];
        foreach ($group as $at => $node) {
            self::noteTexts($node, "/children/{$at}", $texts);
        }
        $changed = false;
        foreach ($positions as $path => $offsets) {
            $text = $texts[$path] ?? null;
            if ($text === null) {
                continue;
            }
            $id = spl_object_id($text);
            $current = $w->escapes[$id] ?? [];
            $added = array_diff(array_keys($offsets), $current);
            if ($added === []) {
                continue;
            }
            $merged = [...$current, ...$added];
            sort($merged);
            $w->escapes[$id] = $merged;
            $w->escaped[$id] = $text;
            foreach ($added as $offset) {
                $w->linkPlaced[$id][$offset] = true;
            }
            $changed = true;
        }

        return $changed;
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
            $array = $node->toArray();
            self::markForms($w, $node, $array, [], true);
            $children[] = $array;
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
     * Gives each emphasis and strong node below `node` its form (carve-js `bracedPair`): braced for its content or
     * place, which `bracedAnyway` records, or where a round asked for it (`forms`), and bare otherwise.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param array<mixed> $array `node` as its array, changed in place.
     * @param array<string, true> $open The kinds of the emphasis and strong nodes around `node`.
     * @param bool $note
     */
    public static function markForms(WriterState $w, Node $node, array &$array, array $open, bool $note = false): void
    {
        if ($node->type === 'emphasis' || $node->type === 'strong') {
            $id = spl_object_id($node);
            $anyway = PairForm::bracedAnyway($node, isset($open[$node->type]));
            if ($anyway && $note) {
                $w->bracedAnyway[$id] = true;
                $w->shared->held[] = $node;
            }
            $array[Inline::BRACED_FORM] = $anyway || ($w->forms[$id] ?? 0) > 0;
            $open[$node->type] = true;
        }
        foreach ($node->slots as $name => $list) {
            if (!is_array($array[$name] ?? null)) {
                continue;
            }
            $slot = $array[$name];
            foreach ($list as $at => $child) {
                if ($child->path === "{$node->path}/{$name}") {
                    self::markForms($w, $child, $slot, $open, $note);
                } elseif (is_array($slot[$at] ?? null)) {
                    $item = $slot[$at];
                    self::markForms($w, $child, $item, $open, $note);
                    $slot[$at] = $item;
                }
            }
            $array[$name] = $slot;
        }
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
