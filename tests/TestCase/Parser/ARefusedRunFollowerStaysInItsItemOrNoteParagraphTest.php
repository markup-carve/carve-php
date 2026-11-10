<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The follower of a refused brace run continues the run's paragraph in list items and note bodies (CARVE-P2-035,
 * carve#5745, carve#5746). Expected output is the spec model's at carve main.
 */
final class ARefusedRunFollowerStaysInItsItemOrNoteParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function followers(): iterable
    {
        yield 'a refused comment on an item marker line keeps a list marker follower as text' => [
            "- {% a\n  b %}\n  - q\n",
            "<ul>\n  <li>{% a\nb %}\n- q</li>\n</ul>",
        ];

        yield 'an ordered marker follower stays text' => [
            "- {% a\n  b %}\n  1. q\n",
            "<ul>\n  <li>{% a\nb %}\n1. q</li>\n</ul>",
        ];

        yield 'text after the closer keeps the follower as text' => [
            "- {% a\n  b %} c\n  - q\n",
            "<ul>\n  <li>{% a\nb %} c\n- q</li>\n</ul>",
        ];

        yield 'a closing brace line keeps the follower as text' => [
            "- {% a\n  {%x%}\n  - q\n",
            "<ul>\n  <li>{% a\n{%x%}\n- q</li>\n</ul>",
        ];

        yield 'a lazy closer keeps a list marker follower as text' => [
            "- {% a\n %}\n  - q\nafter\n",
            "<ul>\n  <li>{% a\n%}\n- q\nafter</li>\n</ul>",
        ];

        yield 'a lazy closer keeps a heading follower and its lazy line' => [
            "- {% a\n %}\n  # q\nafter\n",
            "<ul>\n  <li>{% a\n%}\n# q\nafter</li>\n</ul>",
        ];

        yield 'a lazy note definition after the closer stays text' => [
            "- {% a\n  %}\n [^m]: q\nafter\n",
            "<ul>\n  <li>{% a\n%}\n[^m]: q\nafter</li>\n</ul>",
        ];

        yield 'a note body attribute run keeps its follower in the note' => [
            "[^n]: {.x\n }\n z\n\nx[^n]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n}\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a note body comment run keeps a quote follower and its lazy line' => [
            "[^n]: p\n  {% a\n  %}\n  > q\nafter\n\nx[^n]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p\n{% a\n%}\n&gt; q\nafter<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a note marker line comment measures from the note body column' => [
            "[^n]: {% a\n   %}\n  z\n\nx[^n]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a note marker line comment at the body column is refused' => [
            "[^n]: {% a\n  %}\n  z\n\nx[^n]\n",
            "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{% a\n%}\nz<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a fence follower in a description body leaves a column zero line outside' => [
            ":: t\n: a\n  {% x\n  %}\n  ```\nafter\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n{% x\n%}\n<code></code></dd>\n</dl>\n<p>after</p>",
        ];

        yield 'a lazy closer in a description body keeps the run before a fence' => [
            ":: t\n: a\n  {% x\n%}\n  ```\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n{% x\n%}\n<code></code></dd>\n</dl>",
        ];

        yield 'a fence follower in a description body ends before a column zero line after its payload' => [
            ":: t\n: a\n  {% x\n  %}\n  ```\n  b\nafter\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n{% x\n%}\n<code>\nb</code></dd>\n</dl>\n<p>after</p>",
        ];

        yield 'a comment run does not reach a sibling item' => [
            "- a\n  - {% x\n  - y %}\n  - c\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>{% x</li>\n      <li>y %}</li>\n      <li>c</li>\n    </ul>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('followers')]
    public function testTheFollowerReadsAsTheModelReadsIt(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }
}
