<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineBlockRequiresOpenStanzaTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function containers(): array
    {
        return [
            'quote' => ["> ::: |\na\n", "<blockquote>\n  <div class=\"line-block\">\n\n  </div>\n</blockquote>\n<p>a</p>"],
            'list item' => ["- ::: |\na\n", "<ul>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ul>\n<p>a</p>"],
        ];
    }

    #[DataProvider('containers')]
    public function testUnownedLineNeedsAnOpenStanza(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }

    public function testStanzaWithOneLineStillTakesLazyContinuation(): void
    {
        $this->assertSame(
            "<blockquote>\n  <div class=\"line-block\">\n    <p>x<br>\na</p>\n  </div>\n</blockquote>",
            Carve::toHtml("> ::: |\n> x\na\n"),
        );
    }

    /**
     * carve#6439: a bare `%` run closes a comment block in a line block on any line, before ownership, and closes no
     * container (C2-B-109, C2-B-137); the open stanza then takes the lazy line.
     */
    public function testAColumnZeroCloserEndsTheCommentOnly(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>a<br>\nz</p>\n"
            . "        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            Carve::toHtml("- - ::: |\n    a\n    %%%\n%%%%\nz\n"),
        );
        $this->assertSame(
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a<br>\nb</p>\n    </div>\n  </li>\n</ul>",
            Carve::toHtml("- ::: |\n  a\n  %%%\n%%%\n  b\n"),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function commentRows(): array
    {
        $item = "<ul>\n  <li>%s\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n"
            . "      </li>\n    </ul>\n  </li>\n</ul>\n<p>%s</p>";

        return [
            'A: unowned line closes the item with the comment' => ["- a\n  - ::: |\n    %%%\nb\n", sprintf($item, 'a', 'b')],
            'B: no stanza takes the line lazily' => ["- a\n  - ::: |\n    %%%\n    x\n    %%%\nb\n", sprintf($item, 'a', 'b')],
            'C: a line without quote marks is no closer' => [
                "> > ::: |\n> > %%%\ny\n",
                "<blockquote>\n  <blockquote>\n    <div class=\"line-block\">\n\n    </div>\n  </blockquote>\n</blockquote>\n<p>y</p>",
            ],
            'E: below-column closer' => ["- - ::: |\n    %%%\n   %%%%\n    %%%\n    b\nz\n", sprintf($item, '', 'z')],
            'F: closer at column 2' => ["- - ::: |\n    %%%\n  %%%%\n    %%%\n    b\nz\n", sprintf($item, '', 'z')],
            'H: comment opened at the root hides the rest' => ["- - ::: |\n    %%%\nx\n    %%%\nz\n", sprintf($item, '', 'x')],
        ];
    }

    /**
     * carve#6439 rows A, B, C, E, F and H (C2-B-024, C2-B-109, C2-B-137).
     */
    #[DataProvider('commentRows')]
    public function testCommentBlockInALineBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spaceOnlyLines(): array
    {
        $quote = "<blockquote>\n  <div class=\"line-block\">\n    <p>a</p>\n  </div>\n</blockquote>\n<p>c</p>";
        $item = "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a</p>\n    </div>\n  </li>\n</ul>\n<p>c</p>";

        return [
            'quote mark and two spaces' => ["> ::: |\n> a\n>  \nc\n", $quote],
            'quote mark and four spaces' => ["> ::: |\n> a\n>    \nc\n", $quote],
            'list item line of spaces' => ["- ::: |\n  a\n     \nc\n", $item],
        ];
    }

    /**
     * carve#6672: a quote line that holds only spaces or tabs after its mark is blank (C2-B-032) and closes the stanza
     * (C2-B-113), so the unowned line below is no lazy line (C2-B-024).
     */
    #[DataProvider('spaceOnlyLines')]
    public function testALineOfSpacesClosesTheStanza(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }
}
