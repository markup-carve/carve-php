<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attached list keeps an item's body column past a closed block, and a refused `+` between items renders nothing (PART 0 S3, carve#5013).
 */
final class AnAttachedListKeepsItsItemPastAClosedBlockAndARefusedPlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        // carve#5890 ruling B replaces the carve#5597 reading: the refused `+` ends the note's list.
        yield 'carve#5597: a sibling after a refused plus and a closed fence leaves the note' => [
            "[^a]: +\n- li\n  ```\n  x\n  ```\n+\n - sib\n\nsee[^a]\n",
            "<ul>\n  <li>sib</li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <pre><code>x\n</code></pre>\n        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'carve#5598: an ordered sibling two columns in after a refused plus' => [
            ":: t\n: +\n1. li\n+\n  1. sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>li</li>\n      <li>sib</li>\n    </ol>\n  </dd>\n</dl>",
        ];

        yield 'a line at the item column after a closed fence stays in the item' => [
            "[^a]: +\n- li\n  ```\n  x\n  ```\n  p\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <pre><code>x\n</code></pre>\n          p\n        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a comment after a closed fence keeps the next item line in the item' => [
            "[^a]: +\n- li\n  ```\n  x\n  ```\n%% c\n  p\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <pre><code>x\n</code></pre>\n          p\n        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'an ordered sibling after a refused plus and a closed fence' => [
            ":: t\n: +\n1. li\n   ```\n   x\n   ```\n+\n 1. sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>li\n        <pre><code>x\n</code></pre>\n      </li>\n      <li>sib</li>\n    </ol>\n  </dd>\n</dl>",
        ];

        yield 'a marker of another kind after a refused plus is no sibling' => [
            ":: t\n: +\n1. li\n+\n  - sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>li</li>\n    </ol>\n    <ul>\n      <li>sib</li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'an attributed marker keeps its body column for a nested item' => [
            ":: t\n: +\n-{.a} li\n+\n  - sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li class=\"a\">li\n        <ul>\n          <li>sib</li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheAttachedListReadsAsWithoutTheMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
