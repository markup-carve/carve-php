<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\TestCase\ABracedCommentOnlyParagraphReadsAsACommentLineTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommentOnlyParagraphWireTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return ABracedCommentOnlyParagraphReadsAsACommentLineTest::provider() + [
            'two comments with trailing line comments' => ["{% c %} %% t\n{% d %} %% u\n\nz\n", '<p>z</p>'],
            'comment-only line after nested list' => [
                "- p\n\n  - a\n\n  {% c %}\n  * b\n",
                "<ul>\n  <li><p>p</p>\n    <ul>\n      <li>a</li>\n    </ul>\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testWireRoundTripKeepsTheRenderedResult(string $source, string $expected): void
    {
        $codec = new AstCodec();
        $document = $codec->decode($codec->encode((new BlockParser())->parse($source)));
        $this->assertSame($expected, trim((new HtmlRenderer())->render($document)));
    }
}
