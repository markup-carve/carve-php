<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\InlineParserEngineInterface;

/**
 * Holds a directive label for one line, then hands it to the inline parser.
 */
final class DirectiveLabelParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{
    private DirectiveLabel $block;

    public function __construct(private string $label)
    {
        $this->block = new DirectiveLabel();
    }

    public function getBlock(): DirectiveLabel
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $inlineParser->parse($this->label, $this->block);
    }
}
