<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ClosedLineBlockInFootnoteEndsItemTest extends TestCase
{
    public function testDocumentLineAfterClosedNoteBodyEndsTheList(): void
    {
        $source = "- r[^n]\n\n  [^n]: ::: |\n    a\n    :::\nc\n";
        $expected = <<<'HTML'
<ul>
  <li>r<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></li>
</ul>
<p>c</p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <div class="line-block">
        <p>a</p>
      </div>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
