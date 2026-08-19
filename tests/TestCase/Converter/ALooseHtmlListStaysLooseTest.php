<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * HTML import fidelity for list looseness, both directions (the
 * markup-carve/carve#1210 ruling, corpus-convert case 23): a LOOSE source
 * list - each item an explicit <p> - keeps its paragraphs through the trip,
 * and a bare-text item stays TIGHT. The importer used to flatten every list
 * tight, so the paragraph-ness of the source was lost; carve-js and carve-rs
 * both preserve it.
 */
class ALooseHtmlListStaysLooseTest extends TestCase
{
    protected function render(string $html): string
    {
        $carve = (new HtmlToCarve())->convert($html);

        return rtrim((new CarveConverter())->convert($carve), "\n");
    }

    public function testALooseListKeepsItsItemParagraphs(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>one</p></li>\n  <li><p>two</p></li>\n</ul>",
            $this->render("<ul>\n<li>\n<p>one</p>\n</li>\n<li>\n<p>two</p>\n</li>\n</ul>"),
        );
    }

    public function testABareTextListStaysTight(): void
    {
        // The inverse ruling: one predicate, read off the source's own markup.
        $this->assertSame(
            "<ul>\n  <li>one</li>\n  <li>two</li>\n</ul>",
            $this->render('<ul><li>one</li><li>two</li></ul>'),
        );
    }

    public function testOneParagraphItemLoosensTheWholeList(): void
    {
        // Per LIST, as CommonMark decides it: Carve spells looseness with
        // blank lines between items, and there is no per-item mix.
        $this->assertSame(
            "<ul>\n  <li><p>one</p></li>\n  <li><p>two</p></li>\n</ul>",
            $this->render('<ul><li><p>one</p></li><li>two</li></ul>'),
        );
    }

    public function testAnOrderedLooseListSurvivesToo(): void
    {
        $this->assertSame(
            "<ol>\n  <li><p>one</p></li>\n  <li><p>two</p></li>\n</ol>",
            $this->render('<ol><li><p>one</p></li><li><p>two</p></li></ol>'),
        );
    }

    public function testAMultiBlockItemDoesNotDoubleItsSeparator(): void
    {
        // An item already ending in a second block leaves the one blank line
        // Carve wants between items - a doubled blank would detach the rest.
        $this->assertSame(
            "<ul>\n  <li><p>one</p>\n    <p>more</p>\n  </li>\n  <li><p>two</p></li>\n</ul>",
            $this->render('<ul><li><p>one</p><p>more</p></li><li><p>two</p></li></ul>'),
        );
    }

    public function testTheFullOrdinaryBlocksDocumentSurvives(): void
    {
        // corpus-convert case 23 verbatim, ahead of the submodule pin that
        // will carry it; drop this duplicate when the corpus case arrives.
        $source = <<<'HTML'
<h1>Title</h1>
<ul>
<li>
<p>one</p>
</li>
<li>
<p>two</p>
</li>
</ul>
<blockquote><p>quoted</p></blockquote>
<pre><code>const x = 1</code></pre>
<p><a href="https://example.com">link</a></p>
HTML;
        $expected = <<<'HTML'
<section id="Title">
  <h1>Title</h1>
  <ul>
    <li><p>one</p></li>
    <li><p>two</p></li>
  </ul>
  <blockquote><p>quoted</p></blockquote>
  <pre><code>const x = 1
</code></pre>
  <p><a href="https://example.com">link</a></p>
</section>
HTML;

        $this->assertSame($expected, $this->render($source));
    }
}
