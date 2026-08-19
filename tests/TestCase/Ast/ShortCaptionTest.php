<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class ShortCaptionTest extends TestCase
{
    public function testStructuralShortCaptionsRoundTripAndStayOutOfHtml(): void
    {
        $short = [['type' => 'text', 'value' => 'Navigation label']];
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'figure',
                    'target' => ['type' => 'image', 'src' => '/i.png', 'alt' => 'alt'],
                    'caption' => [['type' => 'text', 'value' => 'Full caption']],
                    'shortCaption' => $short,
                ], [
                    'type' => 'table',
                    'rows' => [],
                    'shortCaption' => $short,
                ],
            ],
        ];

        $codec = new AstCodec();
        $document = $codec->decode($payload);

        [$figure, $table] = $document->getChildren();
        self::assertCount(1, $figure->getShortCaption());
        self::assertSame('Navigation label', $figure->getShortCaption()[0]->getContent());
        self::assertCount(1, $table->getShortCaption());
        self::assertSame('Navigation label', $table->getShortCaption()[0]->getContent());

        self::assertEquals($payload, $codec->encode($document));
        $html = (new HtmlRenderer())->render($document);
        self::assertStringContainsString('<figcaption>Full caption</figcaption>', $html);
        self::assertStringNotContainsString('Navigation label', $html);

        $figure->setShortCaption(null);
        $table->setShortCaption(null);
        self::assertNull($figure->getShortCaption());
        self::assertNull($table->getShortCaption());
    }
}
