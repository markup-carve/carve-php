<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AListItemNoteKeepsItsOwnFencePayloadTest extends TestCase
{
    public function testADefinitionShapedLineInsideTheNoteFenceIsNotRebasedIntoTheItem(): void
    {
        $source = "- [^a]: text\n      ~~~\n    [^b]: inner\n      one\n      ~~~\n\n[^a] [^b]\n";
        $expected = <<<'HTML'
<ul>
  <li></li>
</ul>
<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> [^b]</p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>text</p>
      <pre><code>[^b]: inner
one
</code></pre>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
