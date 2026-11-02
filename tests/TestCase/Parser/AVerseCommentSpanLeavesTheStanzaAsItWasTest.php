<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span in a line block leaves nothing however it ends, so the line after it
 * folds only where a stanza with content was open before the span (carve#4956).
 */
final class AVerseCommentSpanLeavesTheStanzaAsItWasTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'closed span in an item' => [
                "- ::: |\n  x\n  %%%\n  c\n  %%%\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </li>\n</ul>",
            ],
            'span ended by S1 in an item' => [
                "- ::: |\n  x\n  %%%\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </li>\n</ul>",
            ],
            'span ended by S1 in a nested item' => [
                "- a\n  - ::: |\n    x\n    %%%\nb\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>x<br>\nb</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'span ended by S1 with a body' => [
                "- ::: |\n  x\n  %%%\n  c\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </li>\n</ul>",
            ],
            'blank inside a closed span' => [
                "- ::: |\n  x\n  %%%\n\n  %%%\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </li>\n</ul>",
            ],
            'blank before the S1 line' => [
                "- ::: |\n  x\n  %%%\n\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x</p>\n    </div>\n  </li>\n</ul>\n<p>b</p>",
            ],
            'no stanza before the span' => [
                "- a\n  - ::: |\n    %%%\nb\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>b</p>",
            ],
            'blank ended the stanza' => [
                "- ::: |\n  x\n\n  %%%\n  c\n  %%%\nb\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>x</p>\n    </div>\n  </li>\n</ul>\n<p>b</p>",
            ],
            'closed span in a quote' => [
                "> ::: |\n> x\n> %%%\n> y\n> %%%\nb\n",
                "<blockquote>\n  <div class=\"line-block\">\n    <p>x<br>\nb</p>\n  </div>\n</blockquote>",
            ],
            'span ended by S1 in a quote' => [
                "> ::: |\n> x\n> %%%\nb\n",
                "<blockquote>\n  <div class=\"line-block\">\n    <p>x<br>\nb</p>\n  </div>\n</blockquote>",
            ],
            'span ended by S1 in a description' => [
                ":: t\n: ::: |\n  x\n  %%%\nb\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineAfterTheSpanReadsAsWithoutIt(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
