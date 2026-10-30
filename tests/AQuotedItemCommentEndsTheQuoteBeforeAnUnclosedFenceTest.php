<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuotedItemCommentEndsTheQuoteBeforeAnUnclosedFenceTest extends TestCase
{
    public function testCommentAndAttributeItemsEndTheQuote(): void
    {
        $list = "<ul>\n      <li>para</li>\n      <li></li>\n    </ul>";
        foreach (["> - para\n> - %% c", "> - para\n> - {#x}"] as $quote) {
            $source = "::: note\n" . $quote . "\n```\ncode\n::::\n:::\nafter *b*\n";
            $expected = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <blockquote>\n    "
                . $list . "\n  </blockquote>\n  <pre><code>code\n::::\n:::\nafter *b*\n</code></pre>\n</aside>\n";
            self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source), $quote);
        }
    }

    public function testAPlainQuotedParagraphKeepsTheItemLineAsText(): void
    {
        $source = "::: note\n> para\n> - %% c\n```\ncode\n::::\n:::\nafter *b*\n";
        $expected = "<aside class=\"admonition note\" aria-label=\"Note\">\n"
            . "  <blockquote><p>para\n-</p></blockquote>\n"
            . "  <pre><code>code\n::::\n:::\nafter *b*\n</code></pre>\n</aside>\n";
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
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
                . $list . "\n  </blockquote>\n  <pre><code>code\n::::\n:::\nafter *b*\n</code></pre>\n</aside>\n";
            self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source), $quote);
        }
    }
}
