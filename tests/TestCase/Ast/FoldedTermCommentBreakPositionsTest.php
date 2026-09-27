<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class FoldedTermCommentBreakPositionsTest extends TestCase
{
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
            $this->assertSame([
                [$eol . '>   ', [1, 7, 2, 5]],
                [$eol . '> ', [2, 12, 3, 3]],
            ], array_values($spans));
        }
    }
}
