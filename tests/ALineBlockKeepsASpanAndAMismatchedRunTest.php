<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 FAMILIES, colon fence: an exact-length run closes a line block; a
 * span in its payload pairs wherever its closer starts (carve-php#3137).
 */
final class ALineBlockKeepsASpanAndAMismatchedRunTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'span closer past column zero' => [
            "::: |\n```\n:::\n ```",
            "<div class=\"line-block\">\n  <p><code>\n:::\n&nbsp;</code></p>\n</div>",
        ];

        yield 'tilde run past column zero' => [
            "::: |\n~~~\n:::\n ~~~",
            "<div class=\"line-block\">\n  <p>~~~<br>\n:::<br>\n&nbsp;~~~</p>\n</div>",
        ];

        yield 'longer run in an item takes a lazy line' => [
            "- m\n  ::: |\n  ::::\nl",
            "<ul>\n  <li>m\n    <div class=\"line-block\">\n      <p>::::<br>\nl</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'shorter run in an item takes a lazy line' => [
            "- m\n  :::: |\n  :::\nl",
            "<ul>\n  <li>m\n    <div class=\"line-block\">\n      <p>:::<br>\nl</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'longer run in a quote takes a lazy line' => [
            "> ::: |\n> ::::\nl",
            "<blockquote>\n  <div class=\"line-block\">\n    <p>::::<br>\nl</p>\n  </div>\n</blockquote>",
        ];

        yield 'exact run in an item closes' => [
            "- m\n  ::: |\n  a\n  :::\nl",
            "<ul>\n  <li>m\n    <div class=\"line-block\">\n      <p>a</p>\n    </div>\n  </li>\n</ul>\n<p>l</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source . "\n"));
    }
}
