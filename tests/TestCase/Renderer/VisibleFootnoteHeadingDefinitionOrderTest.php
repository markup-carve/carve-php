<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class VisibleFootnoteHeadingDefinitionOrderTest extends TestCase
{
    public function testHeadingIdsFollowDefinitionOrderWhenReferencesAreReversed(): void
    {
        $source = "[^b]: # H\n\n[^a]: # H\n\n[^a] and [^b]\n";
        $expected = <<<'HTML'
<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> and <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <h1 id="H-2">H</h1>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
    <li id="fn2">
      <h1 id="H">H</h1>
      <p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
