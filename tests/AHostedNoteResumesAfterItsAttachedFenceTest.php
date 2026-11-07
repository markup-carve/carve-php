<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list marker at the hosted note's body column remains in the note after
 * its marker-line attachment closes (carve#5456, PART 0 S2).
 */
final class AHostedNoteResumesAfterItsAttachedFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'one item' => [
            "- a\n  [^n]: +\n  ```\n  c\n  ```\n    - x\n\nsee[^n]\n",
            "<ul>\n  <li>a</li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <pre><code>c\n</code></pre>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'nested item' => [
            "- z\n  - a\n    [^n]: +\n    ```\n    q\n    ```\n      - x\n\nsee[^n]\n",
            "<ul>\n  <li>z\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <pre><code>q\n</code></pre>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('documents')]
    public function testNoteOwnsTheFollowingList(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
