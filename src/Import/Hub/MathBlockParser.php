<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * A `$$` fence of `micromark-extension-math`, read as a fenced code block is: closed by a run at least as long.
 */
final class MathBlockParser extends AbstractBlockContinueParser implements BlockStartParserInterface
{
    private MathBlock $block;

    /**
     * @var array<string>
     */
    private array $lines = [];

    private bool $opened = false;

    public function __construct(private int $length = 0, private int $offset = 0)
    {
        $this->block = new MathBlock();
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== '$') {
            return BlockStart::none();
        }
        $indent = $cursor->getIndent();
        $fence = $cursor->matchInPlace('/\G[ \t]*\${2,}(?![^$]*\$)/');
        if ($fence === null) {
            return BlockStart::none();
        }
        $cursor->advanceToEnd();

        return BlockStart::of(new self(strlen(ltrim($fence, " \t")), $indent))->at($cursor);
    }

    public function getBlock(): MathBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): BlockContinue
    {
        if (!$cursor->isIndented() && $cursor->getNextNonSpaceCharacter() === '$') {
            if (preg_match('/^[ \t]*(\${2,})[ \t]*$/', $cursor->getRemainder(), $match) === 1 && strlen($match[1]) >= $this->length) {
                $cursor->advanceToEnd();

                return BlockContinue::finished();
            }
        }
        $offset = $this->offset;
        while ($offset > 0 && $cursor->advanceBySpaceOrTab()) {
            $offset--;
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        if (!$this->opened) {
            $this->opened = true;

            return;
        }
        $this->lines[] = $line;
    }

    public function closeBlock(): void
    {
        $this->block->literal = implode("\n", $this->lines);
    }
}
