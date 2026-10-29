<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block attached by a first-block `+` is read at its own column: a heading or a closed fence attached to a list-hosted note leaves no paragraph for a flush line (carve-php#4261, CARVE-P0-012), and a stacked quote's fence follower that carries the host prefix keeps its payload together (carve-php#4278). Expected HTML is the spec model's.
 */
final class ABlockAttachedAtItsOwnColumnEndsWhereItEndsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'heading attached to a list-hosted note (#4261)' => ["- see[^n]\n\n  [^n]: +\n  # h\nend\n", "<ul>\n  <li>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></li>\n</ul>\n<p>end</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <h1 id=\"h\">h</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'thematic break attached to a list-hosted note' => ["- see[^n]\n\n  [^n]: +\n  ---\nend\n", "<ul>\n  <li>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></li>\n</ul>\n<p>end</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <hr>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
        yield 'fence follower of a stacked quote opener (#4278)' => ["> > +\n> ```\n> c\n> ```\n", "<blockquote>\n  <blockquote>\n    <pre><code>c\n</code></pre>\n  </blockquote>\n</blockquote>"];
        yield 'three stacked quotes' => ["> > > +\n> ```\n> c\n> ```\n", "<blockquote>\n  <blockquote>\n    <blockquote>\n      <pre><code>c\n</code></pre>\n    </blockquote>\n  </blockquote>\n</blockquote>"];
        yield 'fence with an info string' => ["> > +\n> ~~~ x\n> c\n> ~~~\n", "<blockquote>\n  <blockquote>\n    <pre><code class=\"language-x\">c\n</code></pre>\n  </blockquote>\n</blockquote>"];
        yield 'fence follower carrying every prefix' => ["> > +\n> > ```\n> > c\n> > ```\n", "<blockquote>\n  <blockquote>\n    <p>+</p>\n    <pre><code>c\n</code></pre>\n  </blockquote>\n</blockquote>"];
        yield 'quote follower' => ["> > +\n> > q\n> r\n", "<blockquote>\n  <blockquote><p>+\nq\nr</p></blockquote>\n</blockquote>"];
        yield 'table follower' => ["> > +\n> | a |\n> | b |\n", "<blockquote>\n  <blockquote>\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n        <tr><td>b</td></tr>\n      </tbody>\n    </table>\n  </blockquote>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testTheAttachedBlockEndsAtItsBoundary(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    /**
     * CARVE-P0-013: a closed fence holds no paragraph, so the flush line closes
     * the note and the item. The spec model folds it into the item instead
     * (markup-carve/carve#3991); carve-js reads it this way.
     */
    public function testAFlushLineAfterAClosedFenceInANoteEndsTheList(): void
    {
        $html = (new CarveConverter())->convert("- see[^n]\n\n  [^n]: +\n  ```\n  c\n  ```\nend\n");

        self::assertStringContainsString("</ul>\n<p>end</p>\n<section", $html);
        self::assertStringContainsString("<pre><code>c\n</code></pre>\n      <p><a href=\"#fnref1\"", $html);
    }
}
