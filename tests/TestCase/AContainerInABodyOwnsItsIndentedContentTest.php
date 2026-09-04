<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1898.
 *
 * A quote or a div owns its own indented content in a DESCRIPTION BODY and a
 * FOOTNOTE BODY, exactly as carve-php#1892 gave it to a list item. Those two
 * hosts were left out then because they want the OTHER answer for a
 * DEFINITION - the spec consumes one written in a container there, which is
 * what the reverted carve-php#1890 got wrong - and a whole-arm bound was the
 * only discriminator available.
 *
 * The bound now sits where the difference actually is: the container's extent
 * stops before a definition line in a body host, and covers it in an item. So
 * the bodies get the container reading for a heading and a quote, and the
 * definition still reaches the rebase and is still consumed.
 *
 * Row names are grid coordinates - host / inner container / payload / column.
 * Every expectation is the executable spec's answer, read from
 * `scripts/spec/layout.mjs` into `scripts/spec/html.mjs`; the pinned spec
 * 95fc3a04 and spec main 063656e7 agree on all of them.
 */
class AContainerInABodyOwnsItsIndentedContentTest extends TestCase
{
    #[DataProvider('movingProvider')]
    public function testTheBodyHostGivesTheContainerItsIndentedContent(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movingProvider(): array
    {
        return [
            'the ticket document' => [":: t\n: body\n\n  > q\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q\n# h</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div3/head/c3' => [":: t\n: body\n\n  ::: d\n  p\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n# h</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div3/quote/c3' => [":: t\n: body\n\n  ::: d\n  p\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n&gt; z</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div4/head/c3' => [":: t\n: body\n\n  :::: d\n  p\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n# h</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div4/quote/c3' => [":: t\n: body\n\n  :::: d\n  p\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n&gt; z</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/divnest/head/c3' => [":: t\n: body\n\n  ::: d\n  ::: e\n  p\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n# h</p>\n      </div>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/divnest/quote/c3' => [":: t\n: body\n\n  ::: d\n  ::: e\n  p\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n&gt; z</p>\n      </div>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote/head/c3' => [":: t\n: body\n\n  > q\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q\n# h</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote/quote/c3' => [":: t\n: body\n\n  > q\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q\n&gt; z</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'fnbody/div3/head/c3' => ["[^f]: body\n\n  ::: d\n  p\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n# h</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div3/quote/c3' => ["[^f]: body\n\n  ::: d\n  p\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n&gt; z</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div4/head/c3' => ["[^f]: body\n\n  :::: d\n  p\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n# h</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div4/quote/c3' => ["[^f]: body\n\n  :::: d\n  p\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n&gt; z</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/divnest/head/c3' => ["[^f]: body\n\n  ::: d\n  ::: e\n  p\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <div class=\"e\">\n          <p>p\n# h</p>\n        </div>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/divnest/quote/c3' => ["[^f]: body\n\n  ::: d\n  ::: e\n  p\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <div class=\"e\">\n          <p>p\n&gt; z</p>\n        </div>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote/head/c3' => ["[^f]: body\n\n  > q\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote><p>q\n# h</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote/quote/c3' => ["[^f]: body\n\n  > q\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote><p>q\n&gt; z</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
        ];
    }

    #[DataProvider('holdingProvider')]
    public function testTheNeighbouringShapesDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function holdingProvider(): array
    {
        return [
            'ddbody/div3/item/c3' => [":: t\n: body\n\n  ::: d\n  p\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n- z</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div3/prose/c3' => [":: t\n: body\n\n  ::: d\n  p\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\nh</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div4/item/c3' => [":: t\n: body\n\n  :::: d\n  p\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\n- z</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/div4/prose/c3' => [":: t\n: body\n\n  :::: d\n  p\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <p>p\nh</p>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/divnest/item/c3' => [":: t\n: body\n\n  ::: d\n  ::: e\n  p\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n- z</p>\n      </div>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/divnest/prose/c3' => [":: t\n: body\n\n  ::: d\n  ::: e\n  p\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\nh</p>\n      </div>\n    </div>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/nnote/def/c3' => [":: t\n: body\n\n  [^g]: n\n   [r]: /url\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>after</p>"],
            'ddbody/nnote/head/c3' => [":: t\n: body\n\n  [^g]: n\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/nnote/item/c3' => [":: t\n: body\n\n  [^g]: n\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <ul>\n      <li>z</li>\n    </ul>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/nnote/prose/c3' => [":: t\n: body\n\n  [^g]: n\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <p>h</p>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/nnote/quote/c3' => [":: t\n: body\n\n  [^g]: n\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>z</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote-h/def/c3' => [":: t\n: body\n\n  > # q\n   [r]: /url\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote-h/head/c3' => [":: t\n: body\n\n  > # q\n   # h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote-h/item/c3' => [":: t\n: body\n\n  > # q\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <ul>\n      <li>z</li>\n    </ul>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote-h/prose/c3' => [":: t\n: body\n\n  > # q\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <p>h</p>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote-h/quote/c3' => [":: t\n: body\n\n  > # q\n   > z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n      <p>z</p>\n    </blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote/item/c3' => [":: t\n: body\n\n  > q\n   - z\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q\n- z</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'ddbody/quote/prose/c3' => [":: t\n: body\n\n  > q\n   h\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q\nh</p></blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'fnbody/div3/item/c3' => ["[^f]: body\n\n  ::: d\n  p\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n- z</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div3/prose/c3' => ["[^f]: body\n\n  ::: d\n  p\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\nh</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div4/item/c3' => ["[^f]: body\n\n  :::: d\n  p\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\n- z</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div4/prose/c3' => ["[^f]: body\n\n  :::: d\n  p\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <p>p\nh</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/divnest/item/c3' => ["[^f]: body\n\n  ::: d\n  ::: e\n  p\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <div class=\"e\">\n          <p>p\n- z</p>\n        </div>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/divnest/prose/c3' => ["[^f]: body\n\n  ::: d\n  ::: e\n  p\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <div class=\"d\">\n        <div class=\"e\">\n          <p>p\nh</p>\n        </div>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/nnote/def/c3' => ["[^f]: body\n\n  [^g]: n\n   [r]: /url\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/nnote/head/c3' => ["[^f]: body\n\n  [^g]: n\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <h1 id=\"h\">h</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/nnote/item/c3' => ["[^f]: body\n\n  [^g]: n\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <ul>\n        <li>z</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/nnote/prose/c3' => ["[^f]: body\n\n  [^g]: n\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <p>h<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/nnote/quote/c3' => ["[^f]: body\n\n  [^g]: n\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote><p>z</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote-h/def/c3' => ["[^f]: body\n\n  > # q\n   [r]: /url\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote>\n        <h1 id=\"q\">q</h1>\n      </blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote-h/head/c3' => ["[^f]: body\n\n  > # q\n   # h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote>\n        <h1 id=\"q\">q</h1>\n      </blockquote>\n      <h1 id=\"h\">h</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote-h/item/c3' => ["[^f]: body\n\n  > # q\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote>\n        <h1 id=\"q\">q</h1>\n      </blockquote>\n      <ul>\n        <li>z</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote-h/prose/c3' => ["[^f]: body\n\n  > # q\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote>\n        <h1 id=\"q\">q</h1>\n      </blockquote>\n      <p>h<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote-h/quote/c3' => ["[^f]: body\n\n  > # q\n   > z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote>\n        <h1 id=\"q\">q</h1>\n        <p>z</p>\n      </blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote/item/c3' => ["[^f]: body\n\n  > q\n   - z\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote><p>q\n- z</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/quote/prose/c3' => ["[^f]: body\n\n  > q\n   h\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>body</p>\n      <blockquote><p>q\nh</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'item/div3/def/c3' => ["- body\n\n  ::: d\n  p\n   [r]: /url\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n[r]: /url</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div3/head/c3' => ["- body\n\n  ::: d\n  p\n   # h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n# h</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div3/item/c3' => ["- body\n\n  ::: d\n  p\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n- z</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div3/prose/c3' => ["- body\n\n  ::: d\n  p\n   h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\nh</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div3/quote/c3' => ["- body\n\n  ::: d\n  p\n   > z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n&gt; z</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div4/def/c3' => ["- body\n\n  :::: d\n  p\n   [r]: /url\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n[r]: /url</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div4/head/c3' => ["- body\n\n  :::: d\n  p\n   # h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n# h</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div4/item/c3' => ["- body\n\n  :::: d\n  p\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n- z</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div4/prose/c3' => ["- body\n\n  :::: d\n  p\n   h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\nh</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/div4/quote/c3' => ["- body\n\n  :::: d\n  p\n   > z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <p>p\n&gt; z</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/divnest/def/c3' => ["- body\n\n  ::: d\n  ::: e\n  p\n   [r]: /url\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n[r]: /url</p>\n      </div>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/divnest/head/c3' => ["- body\n\n  ::: d\n  ::: e\n  p\n   # h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n# h</p>\n      </div>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/divnest/item/c3' => ["- body\n\n  ::: d\n  ::: e\n  p\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n- z</p>\n      </div>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/divnest/prose/c3' => ["- body\n\n  ::: d\n  ::: e\n  p\n   h\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\nh</p>\n      </div>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/divnest/quote/c3' => ["- body\n\n  ::: d\n  ::: e\n  p\n   > z\n\nafter\n", "<ul>\n  <li>body\n    <div class=\"d\">\n      <div class=\"e\">\n        <p>p\n&gt; z</p>\n      </div>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'item/nnote/item/c3' => ["- body\n\n  [^g]: n\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <ul>\n      <li>z</li>\n    </ul>\n  </li>\n</ul>\n<p>after</p>"],
            'item/nnote/prose/c3' => ["- body\n\n  [^g]: n\n   h\n\nafter\n", "<ul>\n  <li><p>body</p>\n    <p>h</p>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote-h/def/c3' => ["- body\n\n  > # q\n   [r]: /url\n\nafter\n", "<ul>\n  <li>body\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote-h/head/c3' => ["- body\n\n  > # q\n   # h\n\nafter\n", "<ul>\n  <li>body\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote-h/item/c3' => ["- body\n\n  > # q\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    <ul>\n      <li>z</li>\n    </ul>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote-h/prose/c3' => ["- body\n\n  > # q\n   h\n\nafter\n", "<ul>\n  <li>body\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n    </blockquote>\n    h\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote-h/quote/c3' => ["- body\n\n  > # q\n   > z\n\nafter\n", "<ul>\n  <li>body\n    <blockquote>\n      <h1 id=\"q\">q</h1>\n      <p>z</p>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote/head/c3' => ["- body\n\n  > q\n   # h\n\nafter\n", "<ul>\n  <li>body\n    <blockquote><p>q\n# h</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote/item/c3' => ["- body\n\n  > q\n   - z\n\nafter\n", "<ul>\n  <li>body\n    <blockquote><p>q\n- z</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote/prose/c3' => ["- body\n\n  > q\n   h\n\nafter\n", "<ul>\n  <li>body\n    <blockquote><p>q\nh</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'item/quote/quote/c3' => ["- body\n\n  > q\n   > z\n\nafter\n", "<ul>\n  <li>body\n    <blockquote><p>q\n&gt; z</p></blockquote>\n  </li>\n</ul>\n<p>after</p>"],
        ];
    }

    /**
     * THE CONTROL THE REVERTED carve-php#1890 EXISTS FOR, one host wider.
     *
     * A definition written inside a container in a body host is CONSUMED and
     * the reference resolves. If a row here ever flips to text, #1890 is back.
     * The heading row is the other half: it must NOT become an `h1`.
     */
    #[DataProvider('definitionControlProvider')]
    public function testABodyStillConsumesADefinitionInAContainer(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function definitionControlProvider(): array
    {
        return [
            'canary' => ["[^f]: b\n\n  ::: note\n   [r]: /url\n  :::\n\nSee [r][] and [^f].", "<p>See <a href=\"/url\">r</a> and <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'canary-quote' => ["[^f]: b\n\n  > q\n   [r]: /url\n\nSee [r][] and [^f].", "<p>See <a href=\"/url\">r</a> and <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'canary-head' => ["[^f]: b\n\n  ::: note\n   # h\n  :::\n\nSee [^f].", "<p>See <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p># h</p>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div/fenced-def-then-head' => ["[^f]: b\n\n  ::: note\n  ```\n   [r]: /url\n  ```\n   # h\n  :::\n\nSee [r][] and [^f].\n", "<p>See [r][] and <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <pre><code> [r]: /url\n</code></pre>\n        <p># h</p>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'fnbody/div/fenced-def' => ["[^f]: b\n\n  ::: note\n  ```\n   [r]: /url\n  ```\n  :::\n\nSee [r][] and [^f].\n", "<p>See [r][] and <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <pre><code> [r]: /url\n</code></pre>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'ddbody/div/fenced-def-then-head' => [":: t\n: b\n\n  ::: note\n  ```\n   [r]: /url\n  ```\n   # h\n  :::\n\nSee [r][].\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>b</p>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <pre><code> [r]: /url\n</code></pre>\n      <p># h</p>\n    </aside>\n  </dd>\n</dl>\n<p>See [r][].</p>"],
        ];
    }
}
