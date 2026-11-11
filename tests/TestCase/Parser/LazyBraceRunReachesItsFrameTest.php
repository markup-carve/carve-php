<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5830 rule B: a lazy brace opener is refused only when a continuation misses
 * the paragraph's frame (corpus 1070).
 */
final class LazyBraceRunReachesItsFrameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            '1070-a-lazy-comment-opener-is-measured-where-its-brace-is-written-2' => [
                "- a\n  - b\n {% x\n    %}\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n</li>\n    </ul>\n  </li>\n</ul>",
            ],
            '1070-a-lazy-comment-opener-is-measured-where-its-brace-is-written-3' => [
                "[^n]: p\n {% x\n  %}\n\nx[^n]\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p\n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            '1070-a-lazy-comment-opener-is-measured-where-its-brace-is-written' => [
                "- b\n {% x\n  %}\n",
                "<ul>\n  <li>b\n</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testLazyRunIsHeldWhenEveryContinuationReachesItsFrame(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
