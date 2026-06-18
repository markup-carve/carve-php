<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Parser;

use Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List lazy continuation (one-rule §10, fully Djot-aligned): a non-indented line
 * with no blank line before it FOLDS into the item's open lead paragraph -- plain
 * text AND a visible block (heading, thematic break, block quote, table row, fenced
 * code, `:::` div) alike. Only a blank line ends the item's paragraph (or a caption,
 * a §4 attachment). A dedented MARKER still ends the item (sibling list), and an
 * indented marker still opens a nested sublist (§24).
 */
class ListLazyContinuationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testPlainLineFoldsIntoItem(): void
    {
        $djot = "- item\nlazy";
        $expected = "<ul>\n  <li>item\nlazy</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    #[DataProvider('underIndentedNestedLazyContinuationProvider')]
    public function testUnderIndentedLineFoldsIntoDeepestOpenParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function underIndentedNestedLazyContinuationProvider(): array
    {
        return [
            'nested bullet one-space lazy' => [
                "- a\n  - b\n c",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\nc</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'deep nested bullet one-space lazy' => [
                "- a\n  - b\n    - c\n d",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\nd</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'deep nested bullet intermediate lazy' => [
                "- a\n  - b\n    - c\n   d",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c\nd</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'ordered parent one-space under content lazy' => [
                "1. a\n   - b\n  c",
                "<ol>\n  <li>a\n    <ul>\n      <li>b\nc</li>\n    </ul>\n  </li>\n</ol>\n",
            ],
        ];
    }

    public function testLazyLineFoldsIntoLastItem(): void
    {
        $djot = "- a\n- b\nlazy";
        $expected = "<ul>\n  <li>a</li>\n  <li>b\nlazy</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyFoldsInOrderedList(): void
    {
        $djot = "1. a\nlazy";
        $expected = "<ol>\n  <li>a\nlazy</li>\n</ol>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBlankLineEndsList(): void
    {
        $djot = "- a\n\nlazy";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<p>lazy</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testHeadingLineFoldsIntoItem(): void
    {
        // One-rule §10: a heading after the item's prose with no blank line is
        // NOT an interruption -- it folds into the item's open paragraph as
        // lazy continuation, exactly as it does at the top level ("text\n# H"
        // is one paragraph). A blank line is required to start the heading.
        $djot = "- a\n# H";
        $expected = "<ul>\n  <li>a\n# H</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBlockQuoteLineFoldsIntoItem(): void
    {
        // A `>` block quote after the item's prose folds in (the marker is
        // literal text), mirroring the top-level "text\n> q" paragraph.
        $djot = "- a\n> q";
        $expected = "<ul>\n  <li>a\n&gt; q</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testFencedCodeLineFoldsIntoItem(): void
    {
        // An unterminated fence opener folds as an inline verbatim run (the
        // code_span maximal-run rule), exactly as at the top level
        // ("a\n```\nx" -> "<p>a\n<code>\nx</code></p>").
        $djot = "- a\n```\nx";
        $expected = "<ul>\n  <li>a\n<code>\nx</code></li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testIndentedMarkerStillNestsSublist(): void
    {
        // §24 nesting is a SEPARATE mechanism, not a §10 interruption: an
        // indented marker inside the open item opens a sublist (no fold).
        $djot = "- a\n  - b";
        $expected = "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testDedentedMarkerEndsItem(): void
    {
        // A marker dedented below the list's base column ends the item and
        // starts a sibling list at the dedented column (Family D / Rule B).
        $djot = "  - a\n- b";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCaptionStillEndsItemParagraph(): void
    {
        // A caption (`^ `, a §4 attachment) is the one construct that ends an
        // open paragraph; after a plain item paragraph it has nothing to
        // attach to and renders as a separate paragraph.
        $djot = "- a\n^ cap";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<p>^ cap</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
