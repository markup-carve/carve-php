<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence at the content column of a description opened in a marker-line note
 * is that description's block, as it is for a list the note opened (CARVE-P0-004).
 */
class AFenceInAMarkerNoteDescriptionStaysInTheDescriptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'description at the note body column' => [
            "- [^n]: text\n    :: term\n    : desc\n      ```\n      c\n      ```\n\n[^n]\n",
            "<ul>\n  <li></li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>desc</p>\n          <pre><code>c\n</code></pre>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'indented description' => [
            "- [^n]: text\n    :: term\n      : desc\n        ```\n        code\n        ```\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>after <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>desc</p>\n          <pre><code>code\n</code></pre>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'after a blank' => [
            "- [^n]: text\n    :: term\n      : desc\n\n        ```\n        code\n        ```\n\n[^n]\n",
            "<ul>\n  <li></li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>desc</p>\n          <pre><code>code\n</code></pre>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'tilde fence in an ordered item' => [
            "1. [^n]: text\n     :: term\n     : desc\n       ~~~\n       c\n       ~~~\n\n[^n]\n",
            "<ol>\n  <li></li>\n</ol>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>desc</p>\n          <pre><code>c\n</code></pre>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'below the description stays in the note' => [
            "- [^n]: text\n    :: term\n      : desc\n      ```\n      code\n      ```\nafter [^n]\n",
            "<ul>\n  <li></li>\n</ul>\n<p>after <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <dl>\n        <dt>term</dt>\n        <dd>desc</dd>\n      </dl>\n      <pre><code>code\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('shapes')]
    public function testRendersLikeTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
