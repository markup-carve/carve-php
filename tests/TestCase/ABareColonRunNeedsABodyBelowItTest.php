<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1893.
 *
 * A colon run written with NO info string is closer-shaped, and PART 9 §12
 * lets one open a block only when a non-blank line follows it IN THE STREAM IT
 * WAS READ FROM. That is why the same three lines answer two ways at two hosts:
 * at document level a line follows the run, and in a container body the run is
 * the last line the container holds, so it is paragraph text.
 *
 * Asking only whether the paragraph already carried an unclaimed opener - the
 * engine's previous rule - left every container host opening an empty div and
 * ending a paragraph the run belongs to.
 *
 * Every expectation is the executable spec's answer, read from
 * `scripts/spec/layout.mjs` into `scripts/spec/html.mjs`; the pinned spec
 * 95fc3a04 and spec main 063656e7 agree on all of them.
 *
 * Code and comment fences are the controls: they close on a run of AT LEAST
 * the opener's width, a different rule, and neither host moved for them.
 */
class ABareColonRunNeedsABodyBelowItTest extends TestCase
{
    #[DataProvider('movedProvider')]
    public function testABareRunWithNothingBelowItIsParagraphText(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function movedProvider(): array
    {
        return [
            'a list item body' => ["- a\n  :::: note\n  :::\n  p\n  ::::\n\nafter\n", "<ul>\n  <li>a\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <div>\n        <p>p\n::::</p>\n      </div>\n    </aside>\n  </li>\n</ul>\n<p>after</p>"],
            'a footnote body' => ["[^f]: a\n  :::: note\n  :::\n  p\n  ::::\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <div>\n          <p>p\n::::</p>\n        </div>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'a description body' => [":: t\n: a\n  :::: note\n  :::\n  p\n  ::::\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <div>\n        <p>p\n::::</p>\n      </div>\n    </aside>\n  </dd>\n</dl>\n<p>after</p>"],
            'a quote body' => ["> a\n> :::: note\n> :::\n> p\n> ::::\n\nafter\n", "<blockquote>\n  <p>a</p>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <div>\n      <p>p\n::::</p>\n    </div>\n  </aside>\n</blockquote>\n<p>after</p>"],
            'a div body' => ["::::: outer\n:::: note\n:::\np\n::::\n:::::\n\nafter\n", "<div class=\"outer\">\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <div>\n      <p>p</p>\n      <div>\n        <div>\n          <p>after</p>\n        </div>\n      </div>\n    </div>\n  </aside>\n</div>"],
            'a run with a body below it still interrupts' => ["- a\n  p\n  :::\n  q\n\nafter\n", "<ul>\n  <li>a\np\n    <div>\n      <p>q</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
        ];
    }

    #[DataProvider('heldProvider')]
    public function testTheNeighbouringRulesDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function heldProvider(): array
    {
        return [
            'the same document at top level' => [":::: note\n:::\np\n::::\n\nafter\n", "<aside class=\"admonition note\" aria-label=\"Note\">\n  <div>\n    <p>p</p>\n    <div>\n      <p>after</p>\n    </div>\n  </div>\n</aside>"],
            'code fences, list item body' => ["- a\n  ```` x\n  ```\n  p\n  ````\n\nafter\n", "<ul>\n  <li>a\n    <pre><code class=\"language-x\">```\np\n</code></pre>\n  </li>\n</ul>\n<p>after</p>"],
            'code fences, footnote body' => ["[^f]: a\n  ```` x\n  ```\n  p\n  ````\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <pre><code class=\"language-x\">```\np\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'code fences, description body' => [":: t\n: a\n  ```` x\n  ```\n  p\n  ````\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <pre><code class=\"language-x\">```\np\n</code></pre>\n  </dd>\n</dl>\n<p>after</p>"],
            'comment fences, list item body' => ["- a\n  %%%% c\n  %%%\n  p\n  %%%%\n\nafter\n", "<ul>\n  <li>a</li>\n</ul>\n<p>after</p>"],
            'comment fences, footnote body' => ["[^f]: a\n  %%%% c\n  %%%\n  p\n  %%%%\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'comment fences, description body' => [":: t\n: a\n  %%%% c\n  %%%\n  p\n  %%%%\n\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>after</p>"],
            'a blank between the run and the body' => ["- a\n  p\n  :::\n  \n  q\n\nafter\n", "<ul>\n  <li>a\np\n    <div>\n      <p>q</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'a run below no paragraph at all' => ["- a\n  :::\n  q\n\nafter\n", "<ul>\n  <li>a\n    <div>\n      <p>q</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'a matching-width run still closes' => ["- a\n  ::: d\n  p\n  :::\n\nafter\n", "<ul>\n  <li>a\n    <div class=\"d\">\n      <p>p</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
        ];
    }
}
