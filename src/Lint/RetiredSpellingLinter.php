<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Block\TableParser;
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

    protected TableParser $tableParser;

    public function __construct(?TableParser $tableParser = null)
    {
        $this->tableParser = $tableParser ?? new TableParser();
    }

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $warnings = [];
        $offset = 0;
        $lineNumber = 0;
        // A table row inside a fenced code or raw block is the construct being
        // written ABOUT - this package's own docs show the retired spelling in
        // one - so the scan skips it, exactly as the Markdown-habit pass does.
        $inFence = false;
        $fenceMarker = '';
        foreach (explode("\n", $source) as $line) {
            $lineNumber++;
            $fence = VerbatimFence::delimiter($line);
            if ($fence !== null) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceMarker = $fence;
                } elseif (str_starts_with($fence, $fenceMarker)) {
                    $inFence = false;
                    $fenceMarker = '';
                }

                $offset += strlen($line) + 1;

                continue;
            }
            if ($inFence) {
                $offset += strlen($line) + 1;

                continue;
            }
            foreach ($this->retiredCellOrders($line) as $finding) {
                $warnings[] = new LintWarning(
                    line: $lineNumber,
                    column: $finding['column'] + 1,
                    rule: self::RULE_TABLE_CELL_ATTRIBUTE_BEFORE_MARKER,
                    message: $finding['message'],
                    start: $offset + $finding['column'],
                    end: $offset + $finding['column'] + $finding['length'],
                );
            }
            $offset += strlen($line) + 1;
        }

        return $warnings;
    }

    /**
     * @return list<array{column: int, length: int, message: string}>
     */
    protected function retiredCellOrders(string $line): array
    {
        if (!$this->tableParser->isTableRow($line)) {
            return [];
        }

        $findings = [];
        foreach ($this->tableParser->parseTableCellsWithAttributes($line) as $cell) {
            // A block the parser took off a MARKER RUN is already in the T10
            // position, so it is not the retired order.
            if ($cell['attributes'] === '' || $cell['marker'] !== '') {
                continue;
            }
            // The block occupies `{` + payload + `}` at the head of the raw
            // cell, so the character after it is at a known index.
            $blockWidth = strlen($cell['attributes']) + 2;
            $sigil = $cell['raw'][$blockWidth] ?? '';
            $alignment = BlockParser::TABLE_ALIGNMENT_MARKERS[$sigil] ?? null;
            if ($alignment === null) {
                continue;
            }

            $block = substr($cell['raw'], 0, $blockWidth);
            $findings[] = [
                'column' => $cell['cellOffset'],
                'length' => $blockWidth + 1,
                'message' => sprintf(
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
            ];
        }

        return $findings;
    }
}
