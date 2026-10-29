<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (PART 9 §17 L1b).
 */
final class ABlankBeforeInvisibleContentStillSeparatesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'closed note child keeps the blank a separator (#4195)' => ["- see[^n]\n\n  [^n]: para\n    # Heading\n  tail\n", "<ul>\n  <li><p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n    <p>tail</p>\n  </li>\n</ul>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>para</p>\n      <h1 id=\"Heading\">Heading</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'note with a closed fence child' => ["- see[^n]\n\n  [^n]: para\n    ```\n    c\n    ```\n  tail\n", "<ul>\n  <li><p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n    <p>tail</p>\n  </li>\n</ul>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>para</p>\n      <pre><code>c\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'blank before a comment span loosens before a sibling (#4195)' => ["- - a\n\n  %%%\n  note\n  %%%\n- sib\n", "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n  <li><p>sib</p></li>\n</ul>"];
        yield 'blank before a comment span after a sub-list loosens before a sibling' => ["- a\n  - b\n\n  %%%\n  note\n  %%%\n- sib\n", "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n  <li><p>sib</p></li>\n</ul>"];
        yield 'blank before a comment span after a sub-list loosens before a paragraph' => ["- a\n  - b\n\n  %%%\n  note\n  %%%\n  tail\n", "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>b</li>\n    </ul>\n    <p>tail</p>\n  </li>\n</ul>"];
        yield 'a comment after a sub-list without a blank stays tight' => ["- - x\n  %%%\n  y\n", "<ul>\n  <li>\n    <ul>\n      <li>x</li>\n    </ul>\n    y\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testLooseness(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTheFormatterKeepsACommentAfterASubListTight(): void
    {
        $source = "- - x\n  %%%\n  y\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
