<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note in an item measures its body floor from its authored marker column (PART 9 §16).
 */
final class AListHostedFootnoteUsesAuthoredBodyColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item quote at note marker column' => [
            "- x\n    [^m]: n\n    > q\n\n[^m]\n",
            "<ul>\n  <li>x\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'task quote at note marker column' => [
            "- [ ] x\n      [^m]: n\n      > q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"x\"> x\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
