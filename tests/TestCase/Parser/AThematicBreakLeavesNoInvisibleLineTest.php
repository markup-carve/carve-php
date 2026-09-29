<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A thematic break is a VISIBLE block, so it leaves no invisible line behind it
 * and a band follower behind one is the document's.
 *
 * carve-php#2724 gated the band arm on the collected stream's trailing state: a
 * follower joins the item when a paragraph is still open, or when the line above
 * it closed no block. The trailing tracker recorded a rule in the second set, so
 * the follower was retained where the oracle writes it at document level
 * (carve-php#2735). Every row the rule reaches now answers as a heading does.
 *
 * Expectations measured by running `scripts/spec/layout.mjs` and
 * `scripts/spec/html.mjs` at carve `9b938e8a`, this repo's corpus pin.
 *
 * Each shape is asserted on a fresh converter and on a warm one:
 * `Performance\BorrowedHtmlLayout` writes HTML without building a tree and its
 * plan only applies while the converter is still cold (carve-php#2721).
 */
class AThematicBreakLeavesNoInvisibleLineTest extends TestCase
{
    /**
     * The rule closed the paragraph and left nothing open, so the follower is the
     * document's. Every row here read the other way before carve-php#2735.
     *
     * @return array<string, array{string, string}>
     */
    public static function theFollowerLeavesTheItem(): array
    {
        return [
            'a hyphen rule' => [
                "- t\n\n  ---\n z\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'an asterisk rule' => [
                "- t\n\n  ***\n z\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'an underscore rule' => [
                "- t\n\n  ___\n z\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a deeper band under a wide marker' => [
                "10. t\n\n    ---\n  z\n",
                "<ol start=\"10\">\n  <li>t\n    <hr>\n  </li>\n</ol>\n<p>z</p>\n",
            ],
            // A marker reaches the item through the OTHER arm of the same gate,
            // which reads the invisible term too, so it moved with the rest.
            'a marker in the band' => [
                "- t\n\n  ---\n - m\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<ul>\n  <li>m</li>\n</ul>\n",
            ],
            // The band of a NESTED item is still inside the outer one, so the
            // follower leaves the inner item and stops there.
            "a nested item's rule, where the outer item holds the band" => [
                "- a\n  - t\n\n    ---\n   z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>t\n        <hr>\n      </li>\n    </ul>\n    z\n  </li>\n</ul>\n",
            ],
        ];
    }

    /**
     * Rows that read the same before and after, which is what says the change
     * reaches only the band column behind a rule.
     *
     * @return array<string, array{string, string}>
     */
    public static function theFollowerStays(): array
    {
        return [
            'the content column, where the line is the item\'s own' => [
                "- t\n\n  ---\n  z\n",
                "<ul>\n  <li>t\n    <hr>\n    z\n  </li>\n</ul>\n",
            ],
            'a marker at the content column' => [
                "- t\n\n  ---\n  - m\n",
                "<ul>\n  <li>t\n    <hr>\n    <ul>\n      <li>m</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'no blank line after the marker' => [
                "- t\n  ---\n z\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a second blank, which closes the collected stream' => [
                "- t\n\n  ---\n\n z\n",
                "<ul>\n  <li>t\n    <hr>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
        ];
    }

    /**
     * The rest of the invisible set, untouched. A comment is the kind corpus
     * category 517 pins in five documents; the attribute line and the two
     * definition spellings sit beside it in the same term, and moving any of them
     * needs its tightness half moved with it, which is a ruling and not a patch
     * (carve-php#2724).
     *
     * @return array<string, array{string, string}>
     */
    public static function theRestOfTheInvisibleSet(): array
    {
        return [
            'a comment line' => [
                "- t\n\n  %% c\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>\n",
            ],
            'an attribute line' => [
                "- t\n\n  {#a}\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p id=\"a\">z</p>\n  </li>\n</ul>\n",
            ],
            'a link reference definition' => [
                "- t\n\n  [r]: /u\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>\n",
            ],
            'a footnote definition' => [
                "- t\n\n  [^f]: n\n z\n",
                "<ul>\n  <li><p>t</p>\n    <p>z</p>\n  </li>\n</ul>\n",
            ],
            'a marker behind an attribute line' => [
                "- t\n\n  {#a}\n - m\n",
                "<ul>\n  <li>t\n    <ul id=\"a\">\n      <li>m</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('theFollowerLeavesTheItem')]
    #[DataProvider('theFollowerStays')]
    #[DataProvider('theRestOfTheInvisibleSet')]
    public function testAFreshConverterPlacesTheFollower(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('theFollowerLeavesTheItem')]
    #[DataProvider('theFollowerStays')]
    #[DataProvider('theRestOfTheInvisibleSet')]
    public function testAWarmConverterPlacesTheFollower(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $converter->convert("warm\n");

        $this->assertSame($expected, $converter->convert($source));
    }
}
