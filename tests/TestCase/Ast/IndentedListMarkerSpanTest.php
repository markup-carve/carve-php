<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndentedListMarkerSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'different bullet contents' => ["  - alpha\n  - beta\n", ['- alpha', '- beta']];
        yield 'different ordered contents' => ["  1. one\n  2. two\n", ['1. one', '2. two']];
        yield 'tab-indented markers' => ["\t- alpha\n\t- beta\n", ['- alpha', '- beta']];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('lists')]
    public function testEachItemStartsAtItsAuthoredMarker(string $source, array $expected): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $list = $document->getChildren()[0];
        $actual = [];
        foreach ($list->getChildren() as $item) {
            $position = $item->getPos();
            self::assertNotNull($position);
            $actual[] = mb_substr($source, $position->startOffset, $position->endOffset - $position->startOffset, 'UTF-8');
        }

        self::assertSame($expected, $actual);
    }
}
