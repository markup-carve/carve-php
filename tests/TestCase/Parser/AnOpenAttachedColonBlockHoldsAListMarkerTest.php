<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnOpenAttachedColonBlockHoldsAListMarkerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'note plus line block keeps a marker as verse' => [
            "r[^a]\n\n[^a]: +\n::: |\n  a\n- sib\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;a<br>\n- sib</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'note plus line block, ordered marker' => [
            "r[^a]\n\n[^a]: +\n::: |\n  a\n1. sib\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;a<br>\n1. sib</p>\n      </div>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'note plus admonition keeps a marker in its paragraph' => [
            "[^a]: +\n::: note\n  a\n- sib\n\nr[^a]\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>a\n- sib</p>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'note plus admonition opens its own list' => [
            "[^a]: +\n::: note\n- sib\n\nr[^a]\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <ul>\n          <li>sib</li>\n        </ul>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'description plus line block' => [
            ":: t\n: +\n::: |\n  a\n- sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;a<br>\n- sib</p>\n    </div>\n  </dd>\n</dl>",
        ];

        yield 'description plus admonition' => [
            ":: t\n: +\n::: note\na\n- sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>a\n- sib</p>\n    </aside>\n  </dd>\n</dl>",
        ];

        yield 'a closed colon block lets the marker end the note' => [
            "[^a]: +\n::: note\na\n:::\n- sib\n\nr[^a]\n",
            "<ul>\n  <li>sib</li>\n</ul>\n<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>a</p>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerStaysInTheColonBlock(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
