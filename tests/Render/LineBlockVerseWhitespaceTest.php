<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-043 (carve#6762): a line block keeps the spaces and tabs of its lines.
 */
final class LineBlockVerseWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lines(): array
    {
        return [
            'a multibyte character is one column' => ["ä\tb", 'ä&nbsp;&nbsp;&nbsp;b'],
            'a tab at column 3 is one space' => ["abc\td", 'abc d'],
            'one leading space' => [' a', '&nbsp;a'],
            'a run in link text' => ['[a  b](u)', '<a href="u">a&nbsp;&nbsp;b</a>'],
            'columns restart in each text node' => ["/ab/\tc", '<em>ab</em>&nbsp;&nbsp;&nbsp;&nbsp;c'],
            'escaped text keeps its columns' => ["a&b\tc", 'a&amp;b c'],
        ];
    }

    #[DataProvider('lines')]
    public function testLineWhitespace(string $line, string $html): void
    {
        $this->assertSame(
            "<div class=\"line-block\">\n  <p>" . $html . "</p>\n</div>",
            Carve::toHtml("::: |\n" . $line . "\n:::\n"),
        );
    }

    public function testParagraphKeepsItsSpaces(): void
    {
        $this->assertSame("<p>a  b\tc</p>", Carve::toHtml("a  b\tc\n"));
    }
}
