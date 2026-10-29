<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment interrupts a paragraph (PART 1 S4), so it never folds lazily after a refused `+` (carve-php#4280). Expected HTML is the spec model's.
 */
final class ACommentAfterARefusedPlusDoesNotFoldIntoTheItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'shifted nested item (#4280)' => ["- o\n\n   - q\n   +\n   x\n   +\n   %% c\n", "<ul>\n  <li>o\n    <ul>\n      <li>q\n+\nx\n+</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'shifted document list, comment at column 0' => ["   - q\n   +\n   x\n   +\n%% c\n", "<ul>\n  <li>q\n+\nx\n+</li>\n</ul>"];
        yield 'quoted host' => ["> - o\n>\n>    - q\n>    +\n>    x\n>    +\n>    %% c\n", "<blockquote>\n  <ul>\n    <li>o\n      <ul>\n        <li>q\n+\nx\n+</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>"];
        yield 'comment span' => ["   - q\n   +\n   x\n   +\n %%%\n c\n %%%\n", "<ul>\n  <li>q\n+\nx\n+</li>\n</ul>"];
        yield 'text after the comment' => ["   - q\n   +\n   x\n   +\n %% c\n y\n", "<ul>\n  <li>q\n+\nx\n+</li>\n</ul>\n<p>y</p>"];
    }

    #[DataProvider('documents')]
    public function testTheCommentStaysOutOfTheItem(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
