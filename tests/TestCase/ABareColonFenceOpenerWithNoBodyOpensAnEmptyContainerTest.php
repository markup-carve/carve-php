<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare colon-fence opener with no body opens an empty container.
 *
 * grammar.ebnf PART 9 §12: "an opener always opens", and "the body may be
 * EMPTY". A bare colon run whose width does not match the innermost open
 * block "is not a closer at all -- it is an ordinary opener, and opens a
 * block", so a bare `:::` with nothing below it interrupts an open paragraph
 * and opens an empty container rather than folding in as text. The one
 * exception §12 names - a `:::` on a list-item MARKER line whose body arrived
 * by lazy folding - opens nothing, and is a control here.
 *
 * This overturns markup-carve/carve-php#1893 / #1903, which folded the run as
 * paragraph text. That behavior was calibrated to the derived executable
 * checker (scripts/spec/{layout,html}.mjs), which the checker's own header
 * declares "a DERIVED CHECKER, not an authority ... wrong until a clause says
 * otherwise". The engines are the authority here: every expectation below was
 * captured byte-for-byte from carve-rs and cross-checked against carve-js
 * (markup-carve/carve#1970).
 *
 * Code and comment fences are the controls: they close on a run of AT LEAST
 * the opener's width, a different rule, and neither moves.
 */
class ABareColonFenceOpenerWithNoBodyOpensAnEmptyContainerTest extends TestCase
{
    #[DataProvider('openerProvider')]
    public function testABareWrongWidthRunOpensABlock(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * An opener always opens: a bare or wrong-width colon run interrupts the paragraph and opens an (empty) block, at every host.
     *
     * @return array<string, array{string, string}>
     */
    public static function openerProvider(): array
    {
        return [
            'a paragraph then a bare run at top level' => ["text\n:::\n", "<p>text</p>\n<div>\n</div>"],
            'a bare run alone at top level' => [":::\n", "<div>\n</div>"],
            'a paragraph then a bare run in a list item' => ["- a\n  p\n  :::\n", "<ul>\n  <li>a\np\n    <div>\n    </div>\n  </li>\n</ul>"],
            'a bare run alone in a list item' => ["- a\n  :::\n", "<ul>\n  <li>a\n    <div>\n    </div>\n  </li>\n</ul>"],
            'a paragraph then a bare run in a quote' => ["> a\n> p\n> :::\n", "<blockquote>\n  <p>a\np</p>\n  <div>\n  </div>\n</blockquote>"],
            'a wrong-width run inside a div opens a block' => [":::: d\np\n:::\n", "<div class=\"d\">\n  <p>p</p>\n  <div>\n  </div>\n</div>"],
            'a wrong-width run in a note body, list item' => ["- a\n  :::: note\n  :::\n  p\n  ::::\n\nafter\n", "<ul>\n  <li>a\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <div>\n        <p>p</p>\n        <div>\n        </div>\n      </div>\n    </aside>\n  </li>\n</ul>\n<p>after</p>"],
            'a wrong-width run in a note body, quote' => ["> a\n> :::: note\n> :::\n> p\n> ::::\n\nafter\n", "<blockquote>\n  <p>a</p>\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <div>\n      <p>p</p>\n      <div>\n      </div>\n    </div>\n  </aside>\n</blockquote>\n<p>after</p>"],
            'a wrong-width run in a note body, footnote' => ["[^f]: a\n  :::: note\n  :::\n  p\n  ::::\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <div>\n          <p>p</p>\n          <div>\n          </div>\n        </div>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'nested wrong-width runs in a div body' => ["::::: outer\n:::: note\n:::\np\n::::\n:::::\n\nafter\n", "<div class=\"outer\">\n  <aside class=\"admonition note\" aria-label=\"Note\">\n    <div>\n      <p>p</p>\n      <div>\n        <div>\n          <p>after</p>\n        </div>\n      </div>\n    </div>\n  </aside>\n</div>"],
            'the same note document at top level' => [":::: note\n:::\np\n::::\n\nafter\n", "<aside class=\"admonition note\" aria-label=\"Note\">\n  <div>\n    <p>p</p>\n    <div>\n      <p>after</p>\n    </div>\n  </div>\n</aside>"],
        ];
    }

    #[DataProvider('controlProvider')]
    public function testTheNeighbouringRulesDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * The neighbouring rules that do NOT move: a run with a body still interrupts (unchanged), code and comment fences close on >= width, a matching-width run closes its block, and a lazy-folded marker-line run opens nothing (§12).
     *
     * @return array<string, array{string, string}>
     */
    public static function controlProvider(): array
    {
        return [
            'a run with a body below it still interrupts' => ["- a\n  p\n  :::\n  q\n\nafter\n", "<ul>\n  <li>a\np\n    <div>\n      <p>q</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'code fences, list item body' => ["- a\n  ```` x\n  ```\n  p\n  ````\n\nafter\n", "<ul>\n  <li>a\n    <pre><code class=\"language-x\">```\np\n</code></pre>\n  </li>\n</ul>\n<p>after</p>"],
            'comment fences, list item body' => ["- a\n  %%%% c\n  %%%\n  p\n  %%%%\n\nafter\n", "<ul>\n  <li>a</li>\n</ul>\n<p>after</p>"],
            'a matching-width run still closes' => ["- a\n  ::: d\n  p\n  :::\n\nafter\n", "<ul>\n  <li>a\n    <div class=\"d\">\n      <p>p</p>\n    </div>\n  </li>\n</ul>\n<p>after</p>"],
            'a lazy-folded run on a marker line opens nothing' => ["- :::\ntail\n", "<ul>\n  <li>:::\ntail</li>\n</ul>"],
        ];
    }
}
