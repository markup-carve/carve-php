<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5740 shape 1: a brace line inside an inline comment the paragraph closes later is comment text.
 */
final class ABraceLineInsideAClosedInlineCommentStaysTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'root' => ["p {% a\n{lang=de}\nz %} y\n", '<p>p  y</p>'];
        yield 'closer on the brace line' => ["p {% a\n{lang=de} %} y\n", '<p>p  y</p>'];
        yield 'wrapped attribute spelling' => ["p {% a\n{.x\n foo}\nz %} y\n", '<p>p  y</p>'];
        yield 'second comment on the line' => ["p {% a %} {% b\n{lang=de}\nz %} y\n", '<p>p   y</p>'];
        yield 'list item' => ["- p {% a\n  {lang=de}\n  z %} y\n", "<ul>\n  <li>p  y</li>\n</ul>"];
        yield 'quote' => ["> p {% a\n> {lang=de}\n> z %} y\n", '<blockquote><p>p  y</p></blockquote>'];
        yield 'description' => [":: t\n:  p {% a\n   {lang=de}\n   z %} y\n", "<dl>\n  <dt>t</dt>\n  <dd>p  y</dd>\n</dl>"];
        yield 'note' => [
            "[^1]: p {% a\n  {lang=de}\n  z %} y\n\nx[^1]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p  y<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'closer behind a heading' => ["p {% a\n{lang=de}\n# h %}\n", "<p>p {% a\n{lang=de}</p>\n<section id=\"h\">\n  <h1>h %}</h1>\n</section>"];
        yield 'code span shows the opener' => ["p `{%` a\n{lang=de}\nz %} y\n", "<p>p <code>{%</code> a</p>\n<p lang=\"de\">z %} y</p>"];
    }

    #[DataProvider('documents')]
    public function testTheCommentHoldsTheBraceLine(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
