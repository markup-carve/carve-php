<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A block-shaped line below BOTH the sub-list's content column and the outer
 * item's opens nothing (strict content-column rule), so while a paragraph is
 * open it is a lazy line like any other text (PART 0 S4). This collector ended
 * the item on it instead: both lists closed and the marker re-opened as a NEW
 * top-level list (carve-php#706). The marker-line collector already folded the
 * same shape (#693) - the engine disagreed with itself about one line.
 *
 * The folded line keeps its OWN indentation, so the nested parse decides from
 * the column, exactly as #693 does one level up.
 */
class DedentedOpenerFoldsAfterSubListTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADedentedMarkerFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n - b");
        $this->assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>a\n- b</li>\n    </ul>\n  </li>\n</ul>\n",
            $html,
        );
    }

    public function testSeveralDedentedMarkersAllFold(): void
    {
        $html = $this->converter->convert("- x\n  - a\n - b\n - c");
        $this->assertStringContainsString("<li>a\n- b\n- c</li>", $html);
    }

    public function testADedentedHeadingFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n # H");
        $this->assertStringContainsString("<li>a\n# H</li>", $html);
        $this->assertStringNotContainsString('<h1', $html);
    }

    public function testADedentedQuoteFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n > q");
        $this->assertStringContainsString("<li>a\n&gt; q</li>", $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
    }

    public function testAMarkerAtTheContentColumnStillOpensASibling(): void
    {
        // At the sub-list's own marker column it IS an opener, unchanged.
        $html = $this->converter->convert("- x\n  - a\n  - b");
        $this->assertStringContainsString('<li>a</li>', $html);
        $this->assertStringContainsString('<li>b</li>', $html);
    }

    public function testAMarkerAtTheBaseColumnStillOpensASibling(): void
    {
        $html = $this->converter->convert("- x\n  - a\n- b");
        $this->assertStringContainsString('<li>b</li>', $html);
        $this->assertStringNotContainsString("a\n- b", $html);
    }

    public function testABlankLineLeavesNothingToFoldInto(): void
    {
        // No open paragraph after the blank, so the dedented marker ends the
        // item and starts its own list, as S4 requires.
        $html = $this->converter->convert("- x\n  - a\n\n - b");
        $this->assertStringNotContainsString("a\n- b", $html);
        $this->assertStringContainsString('<li>b</li>', $html);
    }

    public function testPlainDedentedTextStillFolds(): void
    {
        $html = $this->converter->convert("- x\n  - a\n b");
        $this->assertStringContainsString("<li>a\nb</li>", $html);
    }
}
