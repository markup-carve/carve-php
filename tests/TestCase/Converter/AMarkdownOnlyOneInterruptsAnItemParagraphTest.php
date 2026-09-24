<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only an ordered marker starting at 1 interrupts a paragraph in CommonMark, so
 * any other under an item's open paragraph is text of it. Carve opens a nested
 * list there, so the import escapes the marker (converter corpus case 69).
 */
class AMarkdownOnlyOneInterruptsAnItemParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a 2 under an item paragraph' => ["- a\n  2. b\n", "- a\n  2\\. b\n", "<ul>\n  <li>a\n2. b</li>\n</ul>\n"],
            'a paren delimiter' => ["1. a\n   3) b\n", "1. a\n   3\\) b\n", "<ol>\n  <li>a\n3) b</li>\n</ol>\n"],
            'a two-digit start after a continuation' => ["- a\n  b\n  10. c\n", "- a\n  b\n  10\\. c\n", "<ul>\n  <li>a\nb\n10. c</li>\n</ul>\n"],
            'a 0 start' => ["- a\n  0. b\n", "- a\n  0\\. b\n", "<ul>\n  <li>a\n0. b</li>\n</ul>\n"],
            'two markers in a row' => ["- a\n  2. b\n  3. c\n", "- a\n  2\\. b\n  3\\. c\n", "<ul>\n  <li>a\n2. b\n3. c</li>\n</ul>\n"],
            'slack past the content column' => ["- a\n    2. b\n", "- a\n  2\\. b\n", "<ul>\n  <li>a\n2. b</li>\n</ul>\n"],
            'in a quoted item' => ["> - a\n>   2. b\n", "> - a\n>   2\\. b\n", "<blockquote>\n  <ul>\n    <li>a\n2. b</li>\n  </ul>\n</blockquote>\n"],
            'in a nested quote' => ["> > - a\n> >   2. b\n", "> > - a\n> >   2\\. b\n", "<blockquote>\n  <blockquote>\n    <ul>\n      <li>a\n2. b</li>\n    </ul>\n  </blockquote>\n</blockquote>\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMarkerStaysParagraphText(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedLists(): array
    {
        return [
            'a 1 still opens one' => ["- a\n  1. b\n", "- a\n  1. b\n"],
            'after a blank' => ["- a\n\n  2. b\n", "{loose}\n- a\n\n  2. b\n"],
            'the paragraph belongs to a deeper item' => ["- a\n  - b\n  2. c\n", "- a\n  - b\n  2. c\n"],
            'a sibling of an open list' => ["1. a\n   1. b\n   2. c\n", "1. a\n   1. b\n   2. c\n"],
        ];
    }

    #[DataProvider('nestedLists')]
    public function testAMarkerThatInterruptsNothingStillOpensAList(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }
}
