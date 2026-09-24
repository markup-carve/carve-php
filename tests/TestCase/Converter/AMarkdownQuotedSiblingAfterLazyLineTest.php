<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkdownQuotedSiblingAfterLazyLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function siblingMarkers(): array
    {
        return [
            'one column of sibling slack' => ["> - a\n> x\n>  - b\n", "> - a\n>   x\n> - b\n"],
            'no sibling slack' => ["> - a\n> x\n> - b\n", "> - a\n>   x\n> - b\n"],
            'indented continuation' => ["> - a\n>   x\n>  - b\n", "> - a\n>   x\n> - b\n"],
        ];
    }

    #[DataProvider('siblingMarkers')]
    public function testLazyLineAndFollowingMarkerStayInSeparateItems(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($carve, $imported);
        self::assertSame(
            "<blockquote>\n  <ul>\n    <li>a\nx</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testRenumberedItemKeepsItsHeldParagraph(): void
    {
        $markdown = "> 9. a\n> 9. b\n>\n>    y\n";
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame(
            "<blockquote>\n  <ol start=\"9\">\n    <li><p>a</p></li>\n    <li><p>b</p>\n      <p>y</p>\n    </li>\n  </ol>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testLazyPipeLineStaysParagraphText(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - a\n> | x |\n");

        self::assertSame("> - a\n>   \\| x |\n", $imported);
        self::assertStringNotContainsString('<table>', (new CarveConverter())->convert($imported));
    }
}
