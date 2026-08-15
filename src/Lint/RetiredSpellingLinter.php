<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;

/**
 * Source written to a spelling Carve has since redefined.
 *
 * A retired spelling is the worst kind of change to carry silently: the
 * document still parses, nothing errors, and the output is different. Only the
 * author knows which reading was meant, so this pass REPORTS rather than
 * rewrites - a formatter that edited the source here would change rendered
 * output on a document that is currently correct, which PART 11 §1 forbids.
 *
 * One rule so far.
 *
 * `table-cell-attribute-before-marker`: a table cell whose attribute block is
 * immediately followed by `<`, `>` or `~`. Under PART 9 §5 T10 the block binds
 * AFTER the kind and alignment markers, so that sigil is now ordinary content;
 * under the retired order it was the cell's alignment. Both readings parse, so
 * the message names both spellings and lets the author pick.
 *
 * IT WALKS THE AST AND THEN READS THE SOURCE THE CELL CAME FROM, rather than
 * scanning lines for a row shape. A table row is a row wherever it stands: in a
 * block quote, in a list item, at any content column its container gives it. A
 * line scan would have to reconstruct all of that to decide whether `|...|` is
 * a row at all, and would still report a fenced EXAMPLE of the retired spelling
 * as if it were a document - this package's own `docs/lint.md` shows one. The
 * parser has already answered both questions, so this asks it.
 *
 * ONLY A BLOCK AT THE CELL'S OWN START is reported, because that is the only
 * position where the two orders disagree. `|>{.x}< a |` carries its marker in
 * front of the block, which the retired order did not admit at all - its `<`
 * was content then and is content now, so there is nothing to choose between.
 */
class RetiredSpellingLinter
{
    /**
     * @var string
     */
    public const RULE_TABLE_CELL_ATTRIBUTE_BEFORE_MARKER = 'table-cell-attribute-before-marker';

    /**
     * The alignment each retired sigil used to mean, named for the message.
     *
     * Keyed off the parser's own marker set so a fourth sigil cannot be added
     * there and silently go unreported here.
     *
     * @var array<string, string>
     */
    private const ALIGNMENT_NAMES = [
        TableCell::ALIGN_LEFT => 'left-aligned',
        TableCell::ALIGN_RIGHT => 'right-aligned',
        TableCell::ALIGN_CENTER => 'centered',
    ];

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();

        $warnings = [];
        $this->collect($converter->parse($source), $source, SourceOffsets::map($source), $warnings);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string $source
     * @param array<int, int>|null $byteAt
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     */
    private function collect(Node $node, string $source, ?array $byteAt, array &$warnings): void
    {
        if ($node instanceof TableCell) {
            $warning = $this->retiredOrder($node, $source, $byteAt);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }
        foreach ($node->getChildren() as $child) {
            $this->collect($child, $source, $byteAt, $warnings);
        }
    }

    /**
     * The finding for one cell, or null when its source is not the retired
     * order.
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param string $source
     * @param array<int, int>|null $byteAt
     */
    private function retiredOrder(TableCell $cell, string $source, ?array $byteAt): ?LintWarning
    {
        if ($cell->getAttributes() === []) {
            return null;
        }
        $pos = $cell->getPos();
        // A cell whose content was merged from a continuation row declines a
        // position, and PART 12 §4 forbids inventing one. Without the span
        // there is no source to read, so there is nothing to report on.
        if ($pos === null) {
            return null;
        }
        // The span covers the cell as written, marker run and block included,
        // which is exactly the stretch the two orders disagree about. It counts
        // CODEPOINTS and the slice below counts bytes, so it is converted first.
        $length = strlen($source);
        $start = SourceOffsets::toByte($pos->startOffset, $byteAt, $length);
        $raw = substr($source, $start, SourceOffsets::toByte($pos->endOffset, $byteAt, $length) - $start);
        if (($raw[0] ?? '') !== '{') {
            return null;
        }
        $close = strpos($raw, '}');
        if ($close === false) {
            return null;
        }
        $sigil = $raw[$close + 1] ?? '';
        $alignment = BlockParser::TABLE_ALIGNMENT_MARKERS[$sigil] ?? null;
        if ($alignment === null) {
            return null;
        }

        $block = substr($raw, 0, $close + 1);

        return new LintWarning(
            line: $pos->startLine,
            column: $pos->startColumn,
            rule: self::RULE_TABLE_CELL_ATTRIBUTE_BEFORE_MARKER,
            message: sprintf(
                'The `%s` after this cell\'s attribute block is content, not alignment: '
                    . 'a cell\'s attributes bind after its markers. Write `%s%s` for a %s cell, '
                    . 'or leave `%s%s` as a literal `%s`.',
                $sigil,
                $sigil,
                $block,
                self::ALIGNMENT_NAMES[$alignment],
                $block,
                $sigil,
                $sigil,
            ),
            start: $start,
            end: $start + $close + 2,
        );
    }
}
