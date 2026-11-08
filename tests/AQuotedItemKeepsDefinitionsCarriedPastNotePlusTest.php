<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A skipped definition stays at the quoted item's content column (carve-php#4951).
 */
final class AQuotedItemKeepsDefinitionsCarriedPastNotePlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'two empty notes before a quoted blank' => [
            "> - [^a]: +\n>   [^b]: +\n>\n>   para\n\nsee[^a][^b]\n",
            "<blockquote>\n  <ul>\n    <li>para</li>\n  </ul>\n</blockquote>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a><a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'the same definitions in a nested quote' => [
            "> > - [^a]: +\n> >   [^b]: +\n> >\n> >   para\n\nsee[^a][^b]\n",
            "<blockquote>\n  <blockquote>\n    <ul>\n      <li>para</li>\n    </ul>\n  </blockquote>\n</blockquote>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a><a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('cases')]
    public function testQuotedItemKeepsDefinitions(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
