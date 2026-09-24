<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition cannot interrupt a CommonMark paragraph but can a Carve one, so
 * a definition-shaped continuation line has its bracket escaped (#2082).
 */
class ADefinitionShapedParagraphLineStaysTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'after paragraph text' => ["text\n[p]: /x", "text\n\\[p]: /x"],
            'a lazy line after a quote' => ["> text\n[p]: /x", "> text\n> \\[p]: /x"],
            'inside a quote' => ["> text\n> [p]: /x", "> text\n> \\[p]: /x"],
            'inside a list item' => ["- item\n  [p]: /x", "- item\n  \\[p]: /x"],
            'a run of them' => ["text\n[a]: /a\n[b]: /b", "text\n\\[a]: /a\n\\[b]: /b"],
            'after an invalid definition' => ["[x]: a b c\n[x]: /u", "[x]: a b c\n\\[x]: /u"],
            'consecutive definitions' => ["[a]: /a\n[b]: /b\n\n[t][b]", "[t][b]\n\n[a]: /a\n\n[b]: /b"],
            'after a heading' => ["# h\n[p]: /x\n\n[t][p]", "# h\n\n[t][p]\n\n[p]: /x"],
            'in a quote after a blank quote line' => [">\n> [p]: /x\n>\n> [t][p]", "> \n> [t][p]\n\n[p]: /x"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLineStaysText(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
