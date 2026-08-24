<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A bullet's content column is MEASURED, not assumed (carve-php#580).
 *
 * `- item` puts its content at column 4, so a block indented to 4 belongs to
 * the item and a block at 2 or 3 does not. This parser used to pin every bullet
 * at 2 regardless of the marker's real width, so content indented to the real
 * column fell outside the item and its marker survived into the output as text.
 *
 * Task items are the exception and stay at 2: the checkbox is content, not
 * marker, and extra spaces before it do not move the column either. Both rules
 * are what carve-js and carve-rs do - every expectation below was measured
 * against those two engines before being pinned here.
 *
 * An abutting attribute block is item metadata and moves no column
 * (markup-carve/carve#1701). Its spelling and Unicode encoding cannot turn a
 * presentational edit into a structural one (markup-carve/carve#1698).
 */
class BulletMarkerContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function assertHtml(string $expected, string $carve): void
    {
        $this->assertSame(trim($expected), trim($this->converter->convert($carve)));
    }

    public function testWideBulletPutsContentColumnAtTheMarkerWidth(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>item\n    <h1 id=\"Wide\">Wide</h1>\n  </li>\n</ul>",
            "-   item\n    # Wide\n",
        );
    }

    public function testNormalBulletKeepsContentColumnAtTwo(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>item\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>",
            "- item\n  # H\n",
        );
    }

    public function testWiderBulletMarkerMovesTheColumnFurther(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>item\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>",
            "*    item\n     # H\n",
        );
    }

    /**
     * Below the measured column the line is lazy paragraph text, so the `#`
     * survives literally. This is the half a "just measure it" fix gets wrong
     * if it moves the column but not the gate.
     */
    public function testBelowTheMeasuredColumnStaysLiteral(): void
    {
        $expected = "<ul>\n  <li>item\n# H</li>\n</ul>";
        $this->assertHtml($expected, "-   item\n  # H\n");
        $this->assertHtml($expected, "-   item\n   # H\n");
    }

    public function testTaskItemKeepsContentColumnAtTwo(): void
    {
        $this->assertHtml(
            "<ul>\n  <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>",
            "- [ ] item\n  # H\n",
        );
    }

    /**
     * The checkbox is content, so the column does not move to clear it - and
     * extra spaces before the checkbox do not move it either. Both engines
     * agree; a naive measurement would put these at 6 and 8.
     */
    public function testTaskItemColumnIgnoresCheckboxAndExtraSpaces(): void
    {
        $expected = "<ul>\n  <li><input type=\"checkbox\" disabled aria-label=\"item # H\"> item\n# H</li>\n</ul>";
        $this->assertHtml($expected, "- [ ] item\n      # H\n");
        $this->assertHtml($expected, "-   [ ] item\n    # H\n");
        $this->assertHtml($expected, "-   [ ] item\n        # H\n");
    }

    /**
     * An abutting attribute block is item metadata, so the bare bullet keeps
     * the content at column 2.
     */
    public function testAttributeBlockDoesNotMoveATaskItemsContentColumn(): void
    {
        $this->assertHtml(
            "<ul>\n  <li id=\"k\"><input type=\"checkbox\" checked disabled aria-label=\"a\"> a\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>",
            "-{#k} [x] a\n  # h\n",
        );
    }

    /**
     * The former full-prefix spelling is now past the content column. The line
     * is lazy paragraph text, so the `#` survives literally.
     */
    public function testTheFormerFullPrefixColumnStaysLiteral(): void
    {
        $this->assertHtml(
            "<ul>\n  <li id=\"k\"><input type=\"checkbox\" checked disabled aria-label=\"a # h\"> a\n# h</li>\n</ul>",
            "-{#k} [x] a\n      # h\n",
        );
    }

    /**
     * Plain items use the same metadata-transparent rule.
     */
    public function testAttributeBlockDoesNotMoveAPlainItemsContentColumn(): void
    {
        $this->assertHtml(
            "<ul>\n  <li id=\"k\">a\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>",
            "-{#k} a\n  # h\n",
        );
    }

    public function testUnicodeAttributeValueMovesNoColumn(): void
    {
        $this->assertHtml(
            "<ul>\n  <li title=\"😀\">a\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>",
            "-{title=\"😀\"} a\n  # h\n",
        );
    }

    public function testWideBulletAlsoGovernsANestedList(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>item\n    <ul>\n      <li>sub</li>\n    </ul>\n  </li>\n</ul>",
            "-   item\n    - sub\n",
        );
    }
}
