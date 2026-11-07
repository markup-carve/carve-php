<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An inner marker-line attachment does not replace the outer host's offer (carve#5434).
 */
final class ANestedAttachedHostResumesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'closed fence' => [
            "- +\n> - +\n> ```\n> ```\n\n  # outer\n",
            "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>\n          <pre><code></code></pre>\n        </li>\n      </ul>\n    </blockquote>\n    <h1 id=\"outer\">outer</h1>\n  </li>\n</ul>",
        ];

        yield 'sibling after nested fence' => [
            "- +\n> - +\n> ```\n> ```\n\n  - y\n",
            "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>\n          <pre><code></code></pre>\n        </li>\n      </ul>\n    </blockquote>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'footnote after nested fence' => [
            "[^a]: +\n> - +\n> ```\n> ```\n\n  # outer\n\n[^a]\n",
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . "      <blockquote>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ul>\n      </blockquote>\n"
            . "      <h1 id=\"outer\">outer</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('documents')]
    public function testOuterHostResumes(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
