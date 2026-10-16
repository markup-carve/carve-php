<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuotedItemCommentEndsTheQuoteBeforeAnUnclosedFenceTest extends TestCase
{
    public function testSuppliedRows(): void
    {
        $base = __DIR__ . '/../_rows612/612-a-quoted-item-comment-ends-the-quote-before-an-unclosed-fence';
        foreach (['', '-2', '-3'] as $suffix) {
            $source = file_get_contents($base . $suffix . '.crv');
            $expected = file_get_contents($base . $suffix . '.html');
            self::assertSame($expected, (new CarveConverter())->convert($source), $suffix);
        }
    }

    public function testOrderedAndNestedItemsAndContinuedItemParagraph(): void
    {
        $cases = [
            ["> 1. para\n> 2. %% c", "<ol>\n      <li>para</li>\n      <li></li>\n    </ol>"],
            ["> - para\n>   - %% c", "<ul>\n      <li>para\n        <ul>\n          <li></li>\n        </ul>\n      </li>\n    </ul>"],
            ["> - para\n>   continued\n> - %% c", "<ul>\n      <li>para\ncontinued</li>\n      <li></li>\n    </ul>"],
        ];

        foreach ($cases as [$quote, $list]) {
            $source = "::: note\n" . $quote . "\n```\ncode\n::::\n:::\nafter *b*\n";
            $expected = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <blockquote>\n    "
                . $list . "\n  </blockquote>\n  <pre><code>code\n::::\n</code></pre>\n</aside>\n"
                . "<p>after <strong>b</strong></p>\n";
            self::assertSame($expected, (new CarveConverter())->convert($source), $quote);
        }
    }
}
