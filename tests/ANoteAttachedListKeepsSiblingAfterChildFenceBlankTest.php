<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank in an attached list item's child fence does not end the list (carve#5555).
 */
final class ANoteAttachedListKeepsSiblingAfterChildFenceBlankTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'note with backtick fence' => ["[^a]: +\n- li\n  ```\n  > quoted\n\n- sibling\n\nsee[^a]\n", '<li><p>sibling</p></li>'];
        yield 'note with tilde fence' => ["[^a]: +\n- li\n  ~~~\n  payload\n\n- sibling\n\nsee[^a]\n", '<li><p>sibling</p></li>'];
    }

    #[DataProvider('documents')]
    public function testSiblingRemainsInNoteList(string $source, string $sibling): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString($sibling, $html);
        self::assertStringNotContainsString("</section>\n<ul>", $html);
    }
}
