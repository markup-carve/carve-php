<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class FoldedTermCommentBreakPositionsTest extends TestCase
{
    use RuleATodo;

    /**
     * A break spans from the sibling before it to the sibling after it
     * (carve-php#2604), whatever the line ending.
     */
    public function testBreaksCoverTheirOriginalLineEndings(): void
    {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            $source = implode($eol, ['> :: 😀', '>   %% note', '>   more', '']);
            $pending = [(new BlockParser(false, false, false, true))->parse($source)];
            $spans = [];
            while ($pending !== []) {
                $node = array_pop($pending);
                array_push($pending, ...$node->getChildren());
                if ($node->getType() !== 'soft_break') {
                    continue;
                }
                $pos = $node->getPos();
                $this->assertNotNull($pos);
                $spans[$pos->startOffset] = [
                    mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
                    [$pos->startLine, $pos->startColumn, $pos->endLine, $pos->endColumn],
                ];
            }
            ksort($spans);
            // Rule A: the term no longer folds the comment and `more`, so no break remains.
            $this->assertSame([], array_values($spans));
        }
    }
}
