<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

/**
 * Rule A: a dedented opener below the sub-list now ends it; the history below predates that.
 *
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
    use RuleATodo;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADedentedMarkerFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n - b");
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>x\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n  <li>b</li>\n</ul>\n"),
            $html,
        );
    }

    public function testSeveralDedentedMarkersAllFold(): void
    {
        $html = $this->converter->convert("- x\n  - a\n - b\n - c");
        $this->assertStringContainsString("<li>b</li>\n  <li>c</li>", $html);
    }

    public function testADedentedHeadingFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n # H");
        $this->assertStringContainsString("</ul>\n<section id=\"H\">\n  <h1>H</h1>", $html);
        $this->assertStringNotContainsString("a\n# H", $html);
    }

    public function testADedentedQuoteFoldsAsText(): void
    {
        $html = $this->converter->convert("- x\n  - a\n > q");
        $this->assertStringContainsString("</ul>\n<blockquote><p>q</p></blockquote>", $html);
        $this->assertStringNotContainsString('&gt; q', $html);
    }

    public function testTwoColumnsInFoldsAsWell(): void
    {
        // Below the sub-list's content column the marker opens a sibling of `x`, at any depth.
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>x\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n  <li>b</li>\n</ul>\n"),
            $this->converter->convert("-   x\n    - a\n  - b"),
        );
    }

    public function testTheWholeListIndentedFoldsTheSameWay(): void
    {
        // The threshold is the content column, not a distance from column 0.
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>x\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n  <li>b</li>\n</ul>\n"),
            $this->converter->convert("  - x\n    - a\n   - b"),
        );
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
        // sub-list and opens a loose sibling of `x`.
        $html = $this->converter->convert("- x\n  - a\n\n - b");
        $this->assertStringNotContainsString("a\n- b", $html);
        $this->assertStringContainsString('<li><p>b</p></li>', $html);
    }

    public function testPlainDedentedTextStillFolds(): void
    {
        $html = $this->converter->convert("- x\n  - a\n b");
        $this->assertStringContainsString("<li>a\nb</li>", $html);
    }
}
