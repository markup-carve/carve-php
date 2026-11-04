<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker line folded into a paragraph opens no item (PART 0 S4, carve#5112), so its `+` is text.
 */
final class ALazyMarkerLineKeepsItsPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function foldedMarkerLines(): array
    {
        return [
            'bullet at the end of input' => [
                "> para\n- +\n",
                "<blockquote><p>para\n- +</p></blockquote>",
            ],
            'bullet before a lazy line' => [
                "> para\n- +\nx\n",
                "<blockquote><p>para\n- +\nx</p></blockquote>",
            ],
            'ordered marker' => [
                "> para\n1. +\n",
                "<blockquote><p>para\n1. +</p></blockquote>",
            ],
            'marker chain after a colon-shaped line' => [
                "> :::note\n1. - +\npara\n",
                "<blockquote><p>:::note\n1. - +\npara</p></blockquote>",
            ],
            'nested quote' => [
                "> > para\n- +\n",
                "<blockquote>\n  <blockquote><p>para\n- +</p></blockquote>\n</blockquote>",
            ],
            'indented quote' => [
                "  > para\n  - +\n   x\n",
                "<blockquote><p>para\n- +\nx</p></blockquote>",
            ],
        ];
    }

    #[DataProvider('foldedMarkerLines')]
    public function testTheFoldedLineKeepsItsPlus(string $source, string $expected): void
    {
        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    public function testANoteBodyFoldKeepsItsPlus(): void
    {
        $html = CarveConverter::create()->convert("> ::: note\n> body\n1. - +\npara\n");

        self::assertStringContainsString("<p>body\n1. - +\npara</p>", $html);
    }
}
