<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment-only paragraph is an invisible line for list separation (CARVE-P9-013).
 */
class ABracedCommentParagraphKeepsListBlankTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'one-line run' => [
                "- p\n\n  {% a z %}\n- q\n",
                "<ul>\n  <li><p>p</p></li>\n  <li><p>q</p></li>\n</ul>",
            ],
            'multi-line run with a block-shaped middle' => [
                "- p\n\n  {% a\n   - m\n   z %}\n- q\n",
                "<ul>\n  <li><p>p</p></li>\n  <li><p>q</p></li>\n</ul>",
            ],
            'nested item' => [
                "- a\n  - x\n\n    {% a z %}\n- s\n",
                "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n  <li><p>s</p></li>\n</ul>",
            ],
            'visible text after the run keeps the blank inside the item' => [
                "- p\n\n  {% a z %}\n  text\n- q\n",
                "<ul>\n  <li><p>p</p>\n    <p>\ntext</p>\n  </li>\n  <li><p>q</p></li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testCommentParagraphPreservesListSeparation(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }
}
