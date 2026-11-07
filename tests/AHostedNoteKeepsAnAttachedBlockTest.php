<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A marker-line `+` gives its next block to the note even when the note is inside an item.
 */
final class AHostedNoteKeepsAnAttachedBlockTest extends TestCase
{
    public function testTheAttachedHeadingAndBodyFloorHeadingShareTheNote(): void
    {
        $source = "- a\n  [^n]: +\n  # h\n    # h\n\nsee[^n]\n";
        $expected = <<<'HTML'
<ul>
  <li>a</li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <h1 id="h">h</h1>
      <h1 id="h-2">h</h1>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
