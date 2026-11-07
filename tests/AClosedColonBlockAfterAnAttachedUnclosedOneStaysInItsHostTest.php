<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closer that a later colon block of the same run takes is not the attached colon block's closer,
 * so the attached block still ends at its first blank (PART 9 §17 L3, carve#5413).
 */
final class AClosedColonBlockAfterAnAttachedUnclosedOneStaysInItsHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item host' => [
            "- +\n::: x\n\n  ::: y\n  b\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"x\">\n\n    </div>\n    <div class=\"y\">\n      <p>b</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'description host' => [
            ":: t\n: +\n::: x\n\n  ::: y\n  b\n  :::\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"x\">\n\n    </div>\n    <div class=\"y\">\n      <p>b</p>\n    </div>\n  </dd>\n</dl>",
        ];

        yield 'footnote host' => [
            "[^1]: +\n::: x\n\n  ::: y\n  b\n  :::\n\n[^1]\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"x\">\n\n      </div>\n      <div class=\"y\">\n        <p>b</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a nested block before the blank still leaves the attached one unclosed' => [
            "- +\n::: x\n  ::: y\n  b\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"x\">\n      <div class=\"y\">\n        <p>b</p>\n      </div>\n    </div>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
