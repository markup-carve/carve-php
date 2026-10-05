<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CodeSpanScanIndexTest extends TestCase
{
    public function testIndexedQueriesKeepMaximalClosersAndMidRunOpeners(): void
    {
        $parser = new InlineParser(new BlockParser());
        $method = new ReflectionMethod(InlineParser::class, 'findCodeSpanEnd');
        $sources = ['```x`` y`', '`a``b`', '\\```x``', '`````', 'é`x`'];
        for ($mask = 0; $mask < 256; $mask++) {
            $source = '';
            for ($bit = 0; $bit < 8; $bit++) {
                $source .= ($mask & (1 << $bit)) !== 0 ? '`' : 'x';
            }
            $sources[] = $source;
        }
        foreach ($sources as $source) {
            $length = strlen($source);
            for ($pos = 0; $pos < $length; $pos++) {
                $this->assertSame(
                    BracketScanner::codeSpanEnd($source, $pos),
                    $method->invoke($parser, $source, $pos),
                    $source . ' at ' . $pos,
                );
            }
        }
    }
}
