<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every line of a Markdown HTML block is literal content of it, indentation
 * included, so the import keeps each one in the raw block as written. A line
 * four columns in used to leave the block as indented code.
 */
class AMarkdownHtmlBlockKeepsItsLinesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a line four columns in' => ["<div>\n    code\n</div>\n", "```=html\n<div>\n    code\n</div>\n```\n"],
            'a line two columns in' => ["<div>\n  text\n</div>\n", "```=html\n<div>\n  text\n</div>\n```\n"],
            'a tab-indented line' => ["<div>\n\ttabbed\n</div>\n", "```=html\n<div>\n\ttabbed\n</div>\n```\n"],
            'a comment' => ["<!--\n    indented\n-->\n", "```=html\n<!--\n    indented\n-->\n```\n"],
            'in a quote' => ["> <div>\n>     code\n> </div>\n", "> ```=html\n> <div>\n>     code\n> </div>\n> ```\n"],
            'in a list item' => ["- a\n\n  <div>\n      code\n  </div>\n", "{loose}\n- a\n\n  ```=html\n  <div>\n      code\n  </div>\n  ```\n"],
            'a whitespace-only line' => ["<script>\n    \n</script>\n", "```=html\n<script>\n    \n</script>\n```\n"],
            'an unclosed comment at the end' => ["# h\n<!--\n\tx\n", "# h\n\n```=html\n<!--\n\tx\n```\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLinesStayInTheBlock(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testALineLeavingTheItemEndsTheBlock(): void
    {
        $this->assertSame(
            "{loose}\n- a\n\n  ```=html\n  <div>\n  x\n  ```\ntext\n",
            (new MarkdownToCarve())->convert("- a\n\n  <div>\n  x\ntext\n"),
        );
    }
}
