<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use PHPUnit\Framework\TestCase;

class CommentFenceCloserTest extends TestCase
{
    public function testCommentClosersKeepTheirPrefixAndLengthRules(): void
    {
        $parser = new FencedBlockParser();
        $lines = ['', '%%', '%%%', '%%%% tail', '%%%tail', "%%%\nbody", str_repeat('%', 70000)];
        for ($byte = 0; $byte < 256; $byte++) {
            $lines[] = chr($byte) . '%%%';
        }
        foreach ($lines as $line) {
            foreach ([0, 2, 3, 4, 70000] as $length) {
                $expected = preg_match('/^(%{3,})/', $line, $matches) === 1
                    && strlen($matches[1]) === $length;
                self::assertSame($expected, $parser->isFencedCommentCloser($line, $length));
                $anyColumn = preg_match('/^(%{3,})/', ltrim($line, " \t"), $matches) === 1
                    && strlen($matches[1]) === $length;
                self::assertSame($anyColumn, $parser->isFencedCommentCloserAnyColumn(" \t" . $line, $length));
            }
        }
    }
}
