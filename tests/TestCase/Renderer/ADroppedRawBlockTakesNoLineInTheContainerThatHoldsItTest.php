<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A raw block whose format the target does not match reaches no output, so the
 * container holding it renders as though it were not there (PART 9 §20).
 *
 * A HOST DECIDES ITS SHAPE BY WHAT ITS CHILDREN RENDER, not by how many it holds
 * (markup-carve/carve#2570). For a footnote body that means the backlink goes in the
 * last paragraph the drop leaves standing: PART 9 §16 puts it in the last paragraph,
 * and a paragraph is only synthesized around a last block that cannot carry a link and
 * is actually in the output.
 *
 * The eight documents are markup-carve/carve corpus category 520, which the spec pin
 * does not carry yet.
 */
class ADroppedRawBlockTakesNoLineInTheContainerThatHoldsItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function corpusDocuments(): array
    {
        return [
            'list item' => [
                "- a\n\n  ```=latex\n  \\x\n  ```\n",
                "<ul>\n  <li>a</li>\n</ul>\n",
            ],
            'the item holds nothing else' => [
                "- ```=latex\n  \\x\n  ```\n",
                "<ul>\n  <li></li>\n</ul>\n",
            ],
            'nested item' => [
                "- a\n\n  - b\n\n    ```=latex\n    \\x\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'item inside a block quote' => [
                "> - a\n>\n>   ```=latex\n>   \\x\n>   ```\n",
                "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n",
            ],
            'between two paragraphs of an item' => [
                "- a\n\n  ```=latex\n  \\x\n  ```\n\n  c\n",
                "<ul>\n  <li><p>a</p>\n    <p>c</p>\n  </li>\n</ul>\n",
            ],
            'div' => [
                ":::\na\n\n```=latex\n\\x\n```\n:::\n",
                "<div>\n  <p>a</p>\n</div>\n",
            ],
            'footnote body' => [
                "x[^1]\n\n[^1]: a\n\n    ```=latex\n    \\x\n    ```\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
                . "  <hr>\n"
                . "  <ol>\n"
                . "    <li id=\"fn1\">\n"
                . '      <p>a<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">'
                . "\u{21a9}</a></p>\n"
                . "    </li>\n"
                . "  </ol>\n"
                . "</section>\n",
            ],
            // The control: a format the target DOES match takes its line.
            'a matching format still takes its line' => [
                "- a\n\n  ```=html\n  <x>\n  ```\n",
                "<ul>\n  <li>a\n    <x>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('corpusDocuments')]
    public function testCorpusDocumentRendersByteIdentically(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }
}
