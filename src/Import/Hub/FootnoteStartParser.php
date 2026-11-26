<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Extension\Footnote\Parser\FootnoteParser;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;
use League\CommonMark\Reference\Reference;

/**
 * league/commonmark's footnote start parser advances the cursor by the label's bytes, not its characters, so a
 * non-ASCII label cut the start of the note; this one counts characters.
 */
final class FootnoteStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $parserState->getLastMatchedBlockParser()->canHaveLazyContinuationLines()) {
            return BlockStart::none();
        }
        if (preg_match('/^\[\^([^\s^\]]+)\]\:(?:\s|$)/u', ltrim($cursor->getRemainder(), " \t"), $match) !== 1) {
            return BlockStart::none();
        }
        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(mb_strlen($match[0], 'UTF-8'));

        return BlockStart::of(new FootnoteParser(new Reference($match[1], $match[1], $match[1])))->at($cursor);
    }
}
