<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableCellWriterParityTest extends TestCase
{
    public function testExplicitBodyAlignmentMatchingTheColumnRemainsInTheAst(): void
    {
        $document = (new CarveConverter())->parse("|=<{.h} Name |=>{.c} Score |\n| Ann |>{.num} 9 |\n");
        $ast = (new AstCodec())->encode($document);

        self::assertSame('right', $ast['children'][0]['rows'][1]['cells'][1]['align']);
    }

    #[DataProvider('prefixedCellProvider')]
    public function testCanonicalWriterPadsPrefixedCells(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixedCellProvider(): iterable
    {
        yield 'attribute and promoted header marker' => [
            "|{.highlight} Total | 99 |\n| --- | --- |\n",
            "|={.highlight} Total |= 99 |\n",
        ];

        yield 'attribute before content that starts with equals' => [
            "|{#x}=R|\n",
            "|{#x} =R |\n",
        ];

        yield 'body alignment and attributes' => [
            "|= Item |= Cost |\n| Pen |>{.num} 9 |\n",
            "|= Item |= Cost |\n| Pen |>{.num} 9 |\n",
        ];
    }

    public function testMarkdownUsesOnlyHeaderCellAlignmentForTheColumnRule(): void
    {
        $markdown = CarveConverter::markdown()->convert("|= Item |= Cost |\n| Pen |>{.num} 9 |\n");

        self::assertSame("| Item | Cost |\n| --- | --- |\n| Pen | 9 |\n", $markdown);
    }

    #[DataProvider('emptyMarkerProvider')]
    public function testMarkdownEmptyContainerMarkerHasNoTrailingSpace(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::markdown()->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyMarkerProvider(): iterable
    {
        yield 'collected link definition in list item' => ["- [x]: /url\n", "-\n"];
        yield 'collected link definition in definition description' => [":: term\n:  [x]: /url\n", "**term**\n:\n"];
    }
}
