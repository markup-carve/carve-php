<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class GithubFootnotesImportTest extends TestCase
{
    public function testGithubMarkersRecoverFootnotesAndDropNavigation(): void
    {
        $html = '<p>x<sup><a href="#fn-1" id="fnref-1" data-footnote-ref>1</a></sup></p>'
            . '<section data-footnotes class="footnotes"><h2 class="sr-only">Footnotes</h2>'
            . '<ol><li id="fn-1"><p>note <a href="#fnref-1" data-footnote-backref class="data-footnote-backref">↩</a></p></li></ol></section>';
        self::assertSame("x[^1]\n\n[^1]: note\n", (new HtmlToCarve())->convert($html));
    }

    public function testRepeatedReferencesWithOtherFragments(): void
    {
        $html = '<p>A<sup><a href="#note-x" id="fnref-1" data-footnote-ref>X</a></sup> '
            . 'B<sup><a href="#note-x" id="fnref-1-2" data-footnote-ref>X</a></sup></p>'
            . '<section data-footnotes><h3 class="sr-only">Notes</h3><ol><li id="note-x"><p>body '
            . '<a href="#fnref-1" data-footnote-backref>back</a></p></li></ol></section>';
        self::assertSame("A[^1] B[^1]\n\n[^1]: body\n", (new HtmlToCarve())->convert($html));
    }
}
