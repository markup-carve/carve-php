<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class CitationGroupFallbackTest extends TestCase
{
    public function testIngestedGroupRendersItsEscapedRawSourceWithoutALoss(): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'citation_group',
                            'raw' => '[@x & <y>]',
                            'items' => [['type' => 'citation', 'key' => 'x', 'suppressAuthor' => false]],
                        ],
                    ],
                ],
            ],
        ];
        $document = (new AstCodec())->decode($payload);
        $result = (new CarveConverter())->renderWithReport($document, true);

        self::assertSame("<p>[@x &amp; &lt;y&gt;]</p>\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }
}
