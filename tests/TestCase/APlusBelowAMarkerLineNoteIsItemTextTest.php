<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class APlusBelowAMarkerLineNoteIsItemTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function itemTextShapes(): array
    {
        return [
            'paragraph' => ["- [^a]: a\n  +\n  b\n\nr[^a]\n", "<li></li>\n</ul>"],
            'quote' => ["- [^a]: a\n  +\n  > r\n\nr[^a]\n", "<li>\n    <blockquote><p>r</p></blockquote>"],
            'list' => ["- [^a]: a\n  +\n  - s\n\nr[^a]\n", "<li>\n    <ul>\n      <li>s</li>"],
            'heading' => ["- [^a]: a\n  +\n  # h\n\nr[^a]\n", "<li>\n    <h1 id=\"h\">h</h1>"],
            'ordered item' => ["1. [^a]: a\n   +\n   b\n\nr[^a]\n", "<li></li>\n</ol>"],
        ];
    }

    #[DataProvider('itemTextShapes')]
    public function testThePlusFollowsTheOpenNoteParagraph(string $source, string $item): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($item, $html);
        self::assertStringContainsString('<li id="fn1">' . "\n" . '      <p>a' . "\n" . '+', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noteContinuationShapes(): array
    {
        return [
            'note at top level' => ["[^a]: a\n+\nb\n\nr[^a]\n"],
            'note on its own line in an item' => ["- x\n  [^a]: a\n  +\n  b\n\nr[^a]\n"],
            'note on a quoted item marker line' => ["- > [^a]: a\n  > +\n  > b\n\nr[^a]\n"],
        ];
    }

    #[DataProvider('noteContinuationShapes')]
    public function testThePlusStillContinuesTheNote(string $source): void
    {
        self::assertStringContainsString("<p>a</p>\n      <p>b<a href=\"#fnref1\"", (new CarveConverter())->convert($source));
    }
}
