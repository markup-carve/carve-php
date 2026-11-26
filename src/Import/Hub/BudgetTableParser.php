<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Extension\Table\TableParser;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\InlineParserEngineInterface;

/**
 * A league/commonmark table parser that refuses a row past the inline budget before keeping it.
 */
final class BudgetTableParser implements BlockContinueParserWithInlinesInterface
{
    public function __construct(private TableParser $table, private InlineBudgetParser $budget)
    {
    }

    public function getBlock(): AbstractBlock
    {
        return $this->table->getBlock();
    }

    public function isContainer(): bool
    {
        return $this->table->isContainer();
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return $this->table->canHaveLazyContinuationLines();
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return $this->table->canContain($childBlock);
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return $this->table->tryContinue($cursor, $activeBlockParser);
    }

    public function addLine(string $line): void
    {
        $this->budget->checkLine($line);
        $this->table->addLine($line);
    }

    public function closeBlock(): void
    {
        $this->table->closeBlock();
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $this->table->parseInlines($inlineParser);
    }
}
