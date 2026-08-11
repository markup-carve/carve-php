<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List lazy continuation (matches canonical djot.js and carve-js): a non-indented
 * line with no blank line before it folds into the item's lead paragraph when it is
 * plain paragraph text; a blank line, or a line that starts a block, ends the list.
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

    public function testHeadingLineContinuesOpenItemParagraph(): void
    {
        $djot = "- a\n# H";
        $expected = "<ul>\n  <li>a\n# H</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testClosedFenceLineContinuesOpenItemParagraph(): void
    {
        $djot = "- a\n```\nx\n```";
        $expected = "<ul>\n  <li>a\n<code>\nx\n</code></li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testUnterminatedFenceDoesNotEndList(): void
    {
        // PART 9 §10 I4: a fence opener with no closer ahead is not a block
        // opener, so it cannot interrupt. It stays paragraph text and the
        // stray ``` opens an inline verbatim run that runs to the end.
        //
        // This case used to assert the opposite - it was written from what the
        // engine did rather than from the rule, and pinned a divergence from
        // carve-rs in place (carve-php#642).
        $djot = "- a\n```\nx";
        $expected = "<ul>\n  <li>a\n<code>\nx</code></li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
