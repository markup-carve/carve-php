<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AHostedNoteMeasuresTabsAfterTheItemFrameTest extends TestCase
{
    public function testTabStraddlingTheTaskItemColumnLeavesTheNote(): void
    {
        $source = "- [ ] [^n]: t\n\t\t> x\n\nx[^n]\n";
        $html = trim((new CarveConverter())->convert($source));

        $expected = <<<'HTML'
<ul class="task-list">
  <li><input type="checkbox" disabled>%s
    <blockquote><p>x</p></blockquote>
  </li>
</ul>
<p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>t<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;
        self::assertSame(sprintf($expected, ' '), $html);
    }

    public function testSpacesStillReachTheNoteBody(): void
    {
        $source = "- [ ] [^n]: t\n        > x\n\nx[^n]\n";
        $html = trim((new CarveConverter())->convert($source));

        self::assertStringContainsString('<blockquote><p>x</p></blockquote>', $html);
        self::assertStringContainsString('<li><input type="checkbox" disabled> </li>', $html);
    }
}
