<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class HostedNoteColonCloserTest extends TestCase
{
    public function testAttachedColonCloserDoesNotReleaseFollowingBodyHeading(): void
    {
        $source = "- z\n  - a\n    [^n]: +\n    ::: note\n    q\n    :::\n      # t\n\nsee[^n]\n";
        $expected = <<<'HTML'
<ul>
  <li>z
    <ul>
      <li>a</li>
    </ul>
  </li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <aside class="admonition note" aria-label="Note">
        <p>q</p>
      </aside>
      <h1 id="t">t</h1>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
