<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (PART 0 S4 and PART 9 section 11 N1: carve#4369, carve#4363, and carve#4160 for a missed note).
 */
final class AMarkerOfAnotherKindPastAMissedItemOpensItsOwnListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a marker of another kind at the missed list column opens its own list' => ["- y\n1. c\n\n   # h\n", "<ul>\n  <li>y</li>\n</ul>\n<ol>\n  <li>c\n    <h1 id=\"h\">h</h1>\n  </li>\n</ol>"];
        yield 'an indented list at a different marker starts a new list' => [" * a\n - x\n", "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>x</li>\n</ul>"];
        yield 'an indented pair after a blank reads as its column-0 spelling' => ["- p\n\n   * x\n   - y\n", "<ul>\n  <li>p\n    <ul>\n      <li>x</li>\n    </ul>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'a note in an item does not take a marker of another list kind' => ["- [^f]: n\n1. tail\n", "<ul>\n  <li></li>\n</ul>\n<ol>\n  <li>tail</li>\n</ol>"];
        yield 'a note in a quote in an item does not take it either' => ["- > para\n  > [^f]: n\n1. tail\n", "<ul>\n  <li>\n    <blockquote><p>para</p></blockquote>\n  </li>\n</ul>\n<ol>\n  <li>tail</li>\n</ol>"];
        yield 'a sibling marker still ends the note' => ["- [^f]: n\n- tail\n", "<ul>\n  <li></li>\n  <li>tail</li>\n</ul>"];
        yield 'a quote around the item still folds the line into the note' => ["> - para\n>   [^f]: n\n- tail\n", "<blockquote>\n  <ul>\n    <li>para</li>\n  </ul>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
