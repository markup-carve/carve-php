<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's, which matches each ticket's Expected section.
 */
final class TicketReproductionsRenderAsExpectedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'carve#4392 reproduction 1' => ["[^f]: +\n```\na\n\n  ```\n  c\nsee\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <pre><code>a\n\n</code></pre>\n      <p>c\nsee<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'carve#4384 reproduction 1' => [" > +\n x\n", '<blockquote><p>x</p></blockquote>'];
        yield 'carve#4384 reproduction 2' => [" > +\nx\n", "<blockquote><p>+\nx</p></blockquote>"];
        yield 'carve#4384 reproduction 3' => [" > +\n ```\n c\n ```\n", "<blockquote>\n  <pre><code>c\n</code></pre>\n</blockquote>"];
        yield 'carve#4367 reproduction 1' => ["- [ ] - x\n      +\n      > q\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>x\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'carve#4367 reproduction 2' => ["- - x\n  +\n  > q\n", "<ul>\n  <li>\n    <ul>\n      <li>x\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'carve#4305 reproduction 1' => ["- a\n\n  :: T\n  : # h\nafter\n", "<ul>\n  <li>a\n    <dl>\n      <dt>T</dt>\n      <dd>\n        <h1 id=\"h\">h</h1>\n      </dd>\n    </dl>\n  </li>\n</ul>\n<p>after</p>"];
        yield 'carve#4305 reproduction 2' => ["- a\n\n  :: T\n  : +\n q\n", "<ul>\n  <li>a\n    <dl>\n      <dt>T</dt>\n      <dd>+\nq</dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'carve#4232 reproduction 1' => [":: t\n: d\n: > a\n  +\n  para\n", "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n  <dd>\n    <blockquote>\n      <p>a</p>\n      <p>para</p>\n    </blockquote>\n  </dd>\n</dl>"];
        yield 'carve#4209 reproduction 1' => [">\n+\n. e\n+\n", "<blockquote>\n  <ol>\n    <li>e\n+</li>\n  </ol>\n</blockquote>"];
        yield '#4310 reproduction 1' => ["- > [^b]: +\n# H\n\nr[^b]\n", "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n  <p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n</section>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>+<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'carve#4608 fewer entries than columns' => ["{widths=25,50}\n| a | b | c |\n", "<table>\n  <colgroup>\n    <col style=\"width: 25%;\">\n    <col style=\"width: 50%;\">\n  </colgroup>\n  <tbody>\n    <tr><td>a</td><td>b</td><td>c</td></tr>\n  </tbody>\n</table>"];
        yield 'carve#4608 an empty entry is an entry' => ["{widths=,50}\n| a | b | c |\n", "<table>\n  <colgroup>\n    <col>\n    <col style=\"width: 50%;\">\n  </colgroup>\n  <tbody>\n    <tr><td>a</td><td>b</td><td>c</td></tr>\n  </tbody>\n</table>"];
        yield 'carve#4608 a width above 100' => ["{widths=101}\n| a |\n", "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>"];
        yield 'carve#4608 one trailing empty entry' => ["{widths=25,}\n| a | b | c |\n", "<table>\n  <colgroup>\n    <col style=\"width: 25%;\">\n    <col>\n  </colgroup>\n  <tbody>\n    <tr><td>a</td><td>b</td><td>c</td></tr>\n  </tbody>\n</table>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
