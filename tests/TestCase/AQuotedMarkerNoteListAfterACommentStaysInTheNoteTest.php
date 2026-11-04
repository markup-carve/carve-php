<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuotedMarkerNoteListAfterACommentStaysInTheNoteTest extends TestCase
{
    use RuleATodo;

    public function testCommentEndsParagraphBeforeNoteList(): void
    {
        $source = "> - [^n]: text\n>     - child\n>     %% k\n>       - deep\nafter [^n]\n";
        $expected = '<blockquote><ul><li></li></ul></blockquote>';
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $converter->convert($source)));
        $codec = new AstCodec();
        $tree = $codec->encode($converter->parse($source));
        self::assertSame($tree, $codec->encode($codec->decodeJson(json_encode($tree, JSON_THROW_ON_ERROR))));
    }

    public function testMarkerWithoutCommentRemainsParagraphText(): void
    {
        $source = "> - [^n]: text\n>     - child\n>       - deep\nafter [^n]\n";
        $converter = new CarveConverter();
        self::assertStringContainsString('<p>text - child - deep', preg_replace('/\s+/', ' ', $converter->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noteExtentCases(): iterable
    {
        yield 'b007' => [
            "- [^n]: text\n    - child\n    %%%\n    k\n    %%%\n      - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b083' => [
            "1. [^n]: text\n     more\n     %%%\n     k\n     %%%\n      - deep\nafter [^n]\n",
            "<ol>\n  <li></li>\n</ol>",
        ];

        yield 'b159' => [
            "> 1. [^n]: text\n>      - child\n>      %%%\n>      k\n>      %%%\n>      - deep\nafter [^n]\n",
            "<blockquote>\n  <ol>\n    <li></li>\n  </ol>\n</blockquote>",
        ];

        yield 'b003' => [
            "- [^n]: text\n    - child\n    %% k\n     - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b004' => [
            "- [^n]: text\n    - child\n    %% k\n    - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b013' => [
            "- [^n]: text\n    - child\n    [r]: /u\n      - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b018' => [
            "- [^n]: text\n    - child\n    {#x}\n      - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b027' => [
            "- [^n]: text\n    more\n    %% k\n      - deep\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>",
        ];

        yield 'b052' => [
            "1. [^n]: text\n     - child\n     %% k\n       - deep\nafter [^n]\n",
            "<ol>\n  <li></li>\n</ol>",
        ];

        yield 'b063' => [
            "1. [^n]: text\n     - child\n     [r]: /u\n       - deep\nafter [^n]\n",
            "<ol>\n  <li></li>\n</ol>",
        ];

        yield 'b093' => [
            "1. [^n]: text\n     more\n     {#x}\n       - deep\nafter [^n]\n",
            "<ol>\n  <li></li>\n</ol>",
        ];

        yield 'b104' => [
            "> - [^n]: text\n>     - child\n>     %% k\n>     - deep\nafter [^n]\n",
            "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>",
        ];

        yield 'b153' => [
            "> 1. [^n]: text\n>      - child\n>      %% k\n>       - deep\nafter [^n]\n",
            "<blockquote>\n  <ol>\n    <li></li>\n  </ol>\n</blockquote>",
        ];
    }

    #[DataProvider('noteExtentCases')]
    public function testMarkerReachingNoteBodyAfterBlockStaysInNote(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $actual));
    }
}
