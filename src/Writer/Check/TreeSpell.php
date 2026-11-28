<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\Context;
use MarkupCarve\Carve\Writer\GroupWrite;
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
        $open = [];
        foreach ([self::IN_EMPHASIS => 'emphasis', self::IN_STRONG => 'strong'] as $flag => $type) {
            if (($flags & $flag) !== 0) {
                $open[$type] = true;
            }
        }
        foreach ($nodes as $at => $node) {
            $array = $node->toArray();
            TreeGroupWriter::markForms($w, $node, $array, $open);
            $list[] = $array;
            self::collect($w, $node, self::LIST . '/' . $at, $escapes);
        }
        $state = new State([], false, false);
        $state->repairTrace = new RepairTrace($escapes, false);
        $c = new Context();
        $c->counts = Inline::counts($list);
        $c->inCell = ($flags & self::IN_CELL) !== 0;
        $c->inBracket = ($flags & self::IN_BRACKET) !== 0;
        $c->openEnd = true;
        // The paired nodes around the list: a nested one of the same type takes the braced form (C2-W-069).
        foreach ([self::IN_EMPHASIS => 'emphasis', self::IN_STRONG => 'strong'] as $flag => $type) {
            if (($flags & $flag) !== 0) {
                $c->open[$type] = 1;
                $c->marks .= $type === 'strong' ? '*' : '/';
            }
        }
        $c->writerState = $state;
        $outer = GroupWrite::startSpelled();
        try {
            $out = (new Inline($state))->content($list, self::LIST, $c, $next);
        } finally {
            $glued = GroupWrite::takeSpelled($outer);
        }
        // carve-js `gluedPart` braces a pair glued to its neighbors in every write, a probe's and a spelling's too.
        if ($glued !== []) {
            TreeGroupWriter::glue($w, $nodes, $glued, self::LIST);
        }

        return $out;
    }

    /**
     * Where the text `node` from `from` ends after `bytes` of its written form (carve-js `spelledEnd`): each asked
     * escape and each backslash of C2-W-097 and pipe of C2-W-098 add one byte.
     */
    public function spelledEnd(WriterState $w, Node $node, int $from, int $bytes, int $flags): int
    {
        $value = $node->value ?? '';
        $escapes = array_flip($w->escapesOf($node));
        $rest = $bytes;
        $cursor = [0, false, ''];
        $valueLength = strlen($value);
        for ($at = $from; $rest > 0 && $at < $valueLength; $at++) {
            $rest -= 1 + (isset($escapes[$at]) || self::alwaysEscaped($value, $at, $flags, $cursor) ? 1 : 0);
        }

        return $rest === 0 ? $at : -1;
    }

    /**
     * The escapes the text `$node` is written with: those asked for and those of C2-W-097 and C2-W-098 (carve-js
     * `escapeOffsets`).
     *
     * @return list<int>
     */
    public function escapeOffsets(WriterState $w, Node $node, int $flags): array
    {
        $offsets = array_flip($w->escapesOf($node));
        $value = $node->value ?? '';
        if (str_contains($value, '\\') || (($flags & self::IN_CELL) !== 0 && str_contains($value, '|'))) {
            $cursor = [0, false, ''];
            $valueLength = strlen($value);
            for ($at = 0; $at < $valueLength; $at++) {
                if (self::alwaysEscaped($value, $at, $flags, $cursor)) {
                    $offsets[$at] = true;
                }
            }
        }
        $list = array_keys($offsets);
        sort($list);

        return $list;
    }

    /**
     * Whether the character `at` of a text takes a backslash whatever the reparse finds (carve-js `alwaysEscaped`):
     * a backslash that the next character of the same text would make an escape (C2-W-097), outside a quoted brace
     * value (C2-I-033), and a pipe in a cell (C2-W-098).
     *
     * @param string $value
     * @param int $at
     * @param int $flags
     * @param array{int, bool, string} $cursor The scan of `inQuotedBrace`: its offset, inside a brace, the open quote.
     */
    private static function alwaysEscaped(string $value, int $at, int $flags, array &$cursor): bool
    {
        $char = $value[$at];
        if ($char === '|') {
            return ($flags & self::IN_CELL) !== 0;
        }
        if ($char !== '\\' || $at + 1 >= strlen($value)) {
            return false;
        }
        $next = $value[$at + 1];

        return ($next === ' ' || Compare::isAsciiPunctuation($next)) && !self::inQuotedBrace($value, $at, $cursor);
    }

    /**
     * C2-I-033: a quoted brace value is opaque, including its backslashes (carve-js `inQuotedBrace`).
     *
     * @param string $value
     * @param int $at
     * @param array{int, bool, string} $cursor
     */
    private static function inQuotedBrace(string $value, int $at, array &$cursor): bool
    {
        while ($cursor[0] < $at) {
            $char = $value[$cursor[0]];
            if ($cursor[2] !== '') {
                if ($char === '\\') {
                    $cursor[0] += 2;

                    continue;
                }
                if ($char === $cursor[2]) {
                    $cursor[2] = '';
                }
            } elseif ($char === '{') {
                $cursor[1] = true;
            } elseif ($char === '}') {
                $cursor[1] = false;
            } elseif ($cursor[1] && ($char === '"' || $char === "'")) {
                $cursor[2] = $char;
            }
            $cursor[0]++;
        }

        return $cursor[2] !== '';
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
