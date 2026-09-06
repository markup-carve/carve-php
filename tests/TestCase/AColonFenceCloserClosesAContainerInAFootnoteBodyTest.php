<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1914, ruled in markup-carve/carve#1948.
 *
 * A colon-fence closer at its opener's column CLOSES the container inside a
 * FOOTNOTE BODY, exactly as it already does inside a description body: a
 * definition written in the div is consumed, the closer leaves the div empty,
 * and a later reference resolves. A footnote body consumes a container-nested
 * definition like any other body host (carve-php#1898); the reach that hoists
 * it had refused a definition sitting inside a div, which is why this host was
 * the one still leaving it as the div's text.
 *
 * A VERBATIM fence still keeps the line: a definition in a code fence is
 * payload, not a definition. Expectations are the executable spec's answer,
 * read from scripts/spec/layout.mjs into scripts/spec/html.mjs.
 */
class AColonFenceCloserClosesAContainerInAFootnoteBodyTest extends TestCase
{
    #[DataProvider('movingProvider')]
    public function testTheDefinitionIsConsumedAndTheContainerCloses(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movingProvider(): array
    {
        return [
            'the ticket document' => ["see[^f]\n\n[^f]: a\n    ::: note\n     [r]: /url\n    :::\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p><a href=\"/url\">r</a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'a div holding content then a definition' => ["see[^f]\n\n[^f]: a\n    ::: note\n    p\n     [r]: /url\n    :::\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p><a href=\"/url\">r</a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>p</p>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'a definition at the div content column' => ["see[^f]\n\n[^f]: a\n    ::: note\n    [r]: /url\n    :::\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p><a href=\"/url\">r</a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'a nested div holding the definition' => ["see[^f]\n\n[^f]: a\n    ::: note\n    ::: inner\n     [r]: /url\n    :::\n    :::\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p><a href=\"/url\">r</a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <div class=\"inner\">\n\n        </div>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
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
            'a definition in a code fence in a div stays payload' => ["see[^f]\n\n[^f]: a\n    ::: note\n    ```\n    [r]: /url\n    ```\n    :::\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>[r][]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <pre><code>[r]: /url\n</code></pre>\n      </aside>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'a definition in a quote in a footnote body still reaches the note' => ["see[^f]\n\n[^f]: a\n    > q\n     [r]: /url\n\n[r][]\n", "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p><a href=\"/url\">r</a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>a</p>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
        ];
    }
}
