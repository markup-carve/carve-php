<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AClosedNestedAttachedQuoteReleasesTheHostColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'list host with a closed code fence' => [
            "- +\n> - +\n> ```\n> ```\n  > z\n",
            "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>\n          <pre><code></code></pre>\n        </li>\n      </ul>\n    </blockquote>\n    <blockquote><p>z</p></blockquote>\n  </li>\n</ul>",
        ];
        yield 'note host with a closed code fence' => [
            "[^a]: +\n> - +\n> ```\n> ```\n  > z\n\n[^a]\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <blockquote>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ul>\n      </blockquote>\n      <blockquote><p>z</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
        yield 'list host with a closed colon body' => [
            "- +\n> - +\n> ::: x\n> :::\n  > z\n",
            "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>\n          <div class=\"x\">\n\n          </div>\n        </li>\n      </ul>\n    </blockquote>\n    <blockquote><p>z</p></blockquote>\n  </li>\n</ul>",
        ];
        yield 'an open paragraph still continues its quote' => [
            "- +\n> q\n  > z\n",
            "<ul>\n  <li>\n    <blockquote><p>q\nz</p></blockquote>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('cases')]
    public function testQuoteBoundary(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
