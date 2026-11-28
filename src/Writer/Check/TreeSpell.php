<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\Context;
use MarkupCarve\Carve\Writer\Inline;
use MarkupCarve\Carve\Writer\RepairTrace;
use MarkupCarve\Carve\Writer\State;

/**
 * `Spell` over the PHP inline writer: the nodes are written as one inline list of a paragraph, with the escapes of the
 * writer state keyed by the paths the nodes take in that list. `IN_CELL`, `IN_BRACKET`, `IN_EMPHASIS` and `IN_STRONG`
 * set the matching context; the note flag has no context of its own in the PHP writer, which reads it from the tree.
 */
final class TreeSpell implements Spell
{
    /**
     * @var string
     */
    private const LIST = '/children/0/children';

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $flags
     */
    public function inlines(WriterState $w, array $nodes, int $flags): string
    {
        return self::write($w, $nodes, $flags, -1);
    }

    /**
     * carve-js `writeInline`: the node alone, not as a list, so nothing is placed for the end of a list. The PHP
     * writer reads the end of a list from `next` -1; 0 stands for a following character that is no mark.
     */
    public function inline(WriterState $w, Node $node, int $flags): string
    {
        return self::write($w, [$node], $flags, 0);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $next
     * @param int $flags
     */
    private static function write(WriterState $w, array $nodes, int $flags, int $next): string
    {
        if ($nodes === []) {
            return '';
        }
        $escapes = [];
        $list = [];
        foreach ($nodes as $at => $node) {
            $list[] = $node->toArray();
            self::collect($w, $node, self::LIST . '/' . $at, $escapes);
        }
        $state = new State([], false, false);
        $state->repairTrace = new RepairTrace($escapes, false);
        $c = new Context();
        $c->counts = Inline::counts($list);
        $c->inCell = ($flags & self::IN_CELL) !== 0;
        $c->inBracket = ($flags & self::IN_BRACKET) !== 0;
        // The paired nodes around the list: a nested one of the same type takes the braced form (C2-W-069).
        foreach ([self::IN_EMPHASIS => 'emphasis', self::IN_STRONG => 'strong'] as $flag => $type) {
            if (($flags & $flag) !== 0) {
                $c->open[$type] = 1;
                $c->marks .= $type === 'strong' ? '*' : '/';
            }
        }
        $c->writerState = $state;

        return (new Inline($state))->content($list, self::LIST, $c, $next);
    }

    public function spelledEnd(WriterState $w, Node $node, int $from, int $bytes, int $flags): int
    {
        $value = $node->value ?? '';
        $escapes = array_flip($w->escapesOf($node));
        $rest = $bytes;
        $inCell = ($flags & self::IN_CELL) !== 0;
        $valueLength = strlen($value);
        for ($at = $from; $rest > 0 && $at < $valueLength; $at++) {
            $always = $inCell && $value[$at] === '|';
            $rest -= 1 + (isset($escapes[$at]) || $always ? 1 : 0);
        }

        return $rest === 0 ? $at : -1;
    }

    /**
     * The escapes the text `$node` is written with: those asked for, and in a cell each `|`.
     *
     * @return list<int>
     */
    public function escapeOffsets(WriterState $w, Node $node, int $flags): array
    {
        $offsets = array_flip($w->escapesOf($node));
        if (($flags & self::IN_CELL) !== 0) {
            $value = $node->value ?? '';
            for ($at = strpos($value, '|'); $at !== false; $at = strpos($value, '|', $at + 1)) {
                $offsets[$at] = true;
            }
        }
        $list = array_keys($offsets);
        sort($list);

        return $list;
    }

    /**
     * C2-W-080: a div whose last class is `hardbreaks`, with no title or group label and no soft break below, is
     * written with the hard-break opener (`Blocks::usesHardBreakOpener` with no fact).
     */
    public function usesHardBreakOpener(WriterState $w, Node $div): bool
    {
        $attrs = $div->field('attrs');
        $classes = is_array($attrs) && is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [];
        if ($classes === [] || end($classes) !== 'hardbreaks' || $div->field('title') !== null || isset($div->slots['title'])) {
            return false;
        }

        return $div->field('groupLabel') === null && !self::softBreakBelow($div);
    }

    private static function softBreakBelow(Node $node): bool
    {
        foreach ($node->children() as $child) {
            if ($child->type === 'soft_break' || self::softBreakBelow($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     */
    private static function collect(WriterState $w, Node $node, string $path, array &$escapes): void
    {
        if ($node->type === 'text') {
            foreach ($w->escapesOf($node) as $at) {
                $escapes[$path][$at] = true;
            }
        }
        foreach ($node->slots as $name => $list) {
            foreach ($list as $at => $child) {
                // A single node field, as a caption, has no index in its path (`Node::mirror`).
                $single = $child->path !== '' && $child->path === "{$node->path}/{$name}";
                self::collect($w, $child, $single ? "{$path}/{$name}" : "{$path}/{$name}/{$at}", $escapes);
            }
        }
    }
}
