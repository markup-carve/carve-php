<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §23 under carve#5755 and carve#5885: a verse lead is its per-frame column
 * less the opener's, and the opener's own indentation is no frame (corpus 1071, 1073).
 */
final class VerseLeadPerFrameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-2' => [
                "- a\n  ::: |\n      x\n  :::\n",
                "<ul>\n  <li>a\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;&nbsp;&nbsp;x</p>\n    </div>\n  </li>\n</ul>",
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-3' => [
                ":: t\n: d\n  ::: |\n  \tx\n  :::\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;&nbsp;&nbsp;x</p>\n    </div>\n  </dd>\n</dl>",
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-4' => [
                "> - a\n>   ::: |\n>   \tx\n>   :::\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;&nbsp;&nbsp;x</p>\n      </div>\n    </li>\n  </ul>\n</blockquote>",
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave' => [
                "- a\n  ::: |\n  \tx\n  :::\n",
                "<ul>\n  <li>a\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;&nbsp;&nbsp;x</p>\n    </div>\n  </li>\n</ul>",
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-2' => [
                "- a\n   ::: |\n    \tx\n   :::\n",
                "<ul>\n  <li>a\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;&nbsp;x</p>\n    </div>\n  </li>\n</ul>",
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-3' => [
                "- a\n  \t::: |\n    \tx\n  \t:::\n",
                "<ul>\n  <li>a\n    <div class=\"line-block\">\n      <p>x</p>\n    </div>\n  </li>\n</ul>",
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-4' => [
                "> - a\n>    ::: |\n>   \tx\n>    :::\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;&nbsp;x</p>\n      </div>\n    </li>\n  </ul>\n</blockquote>",
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame' => [
                "- a\n   ::: |\n  \tx\n   :::\n",
                "<ul>\n  <li>a\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;&nbsp;x</p>\n    </div>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testVerseLeadIsMeasuredPerFrame(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
