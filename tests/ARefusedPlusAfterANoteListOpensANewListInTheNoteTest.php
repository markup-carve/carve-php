<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After a refused `+` ends a note's attached list, a line at the note's body column belongs to the note and opens a new block there (carve#5890 ruling B).
 */
final class ARefusedPlusAfterANoteListOpensANewListInTheNoteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'a stacked note opens a second list after a fence' => [
            "- [^a]: +\n  - li\n    ```\n  +\n    - x\n\nsee[^a]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <pre><code></code></pre>\n        </li>\n      </ul>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a paragraph line stays out of the ended item' => [
            "[^a]: +\n- li\n+\n  p\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li</li>\n      </ul>\n      <p>p<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a second tight list past a blank run' => [
            "- [^a]: +\n  - li\n  +\n\n    - x\n\nsee[^a]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li</li>\n      </ul>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a second list past a blank after a nested item' => [
            "[^a]: +\n- li\n  - n\n+\n\n  - x\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <ul>\n            <li>n</li>\n          </ul>\n        </li>\n      </ul>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a quoted note ends its list' => [
            "> [^a]: +\n> - li\n>   - n\n> +\n>   - x\n\nsee[^a]\n",
            "<blockquote>\n\n</blockquote>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>li\n          <ul>\n            <li>n</li>\n          </ul>\n        </li>\n      </ul>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a refused plus after an attached quote' => [
            "- [^a]: +\n  > q\n  +\n    > q\n\nsee[^a]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <blockquote><p>q</p></blockquote>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheFollowerOpensANewBlockInTheNote(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
