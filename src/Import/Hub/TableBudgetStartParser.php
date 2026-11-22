<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Extension\Table\TableParser;
use League\CommonMark\Extension\Table\TableStartParser;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * league/commonmark's table start, with the table's rows checked against the inline budget as they are added.
 */
final class TableBudgetStartParser implements BlockStartParserInterface
{
    private TableStartParser $tables;

    public function __construct(private InlineBudgetParser $budget, int $maxAutocompletedCells)
    {
        $this->tables = new TableStartParser($maxAutocompletedCells);
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        $start = $this->tables->tryStart($cursor, $parserState);
        $state = $start?->getCursorState();
        if ($start === null || $state === null) {
            return $start;
        }
        $parsers = [];
        foreach ($start->getBlockParsers() as $parser) {
            $parsers[] = $parser instanceof TableParser ? new BudgetTableParser($parser, $this->budget) : $parser;
        }
        $cursor->restoreState($state);
        $budgeted = BlockStart::of(...$parsers)->at($cursor);

        return $start->isReplaceActiveBlockParser() ? $budgeted->replaceActiveBlockParser() : $budgeted;
    }
}
