<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class VerbatimMarkerRoundtripTest extends TestCase
{
    public function testListMarkersInsideCodeKeepTheirValue(): void
    {
        foreach (['. b', '-{.k} b', '+ b', '1. b'] as $marker) {
            $codec = new AstCodec();
            $document = $codec->decode([

                'type' => 'document',
                'srcByteLength' => 0,
                'children' => [
                    [

                        'type' => 'list',
                        'ordered' => false,
                        'tight' => true,
                        'items' => [
                            [

                                'type' => 'list_item',
                                'children' => [
                                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x '], ['type' => 'code', 'value' => "a\n" . $marker]]],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
            $source = (new CarveRenderer())->render($document);
            $tree = $codec->encode((new CarveConverter())->parse($source));
            self::assertSame("a\n" . $marker, $tree['children'][0]['items'][0]['children'][0]['children'][1]['value']);
        }
    }

    public function testAGluedLineBlockFenceSpanIsRefused(): void
    {
        $document = (new AstCodec())->decode([

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'line_block',
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'code', 'value' => "a\n:::\nb"]]],
                    ],
                ],
            ],
        ]);
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testATextPrefixCannotOpenAFenceSpan(): void
    {
        $document = (new AstCodec())->decode([

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'line_block',
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x '], ['type' => 'code', 'value' => "\n:::\n"]]],
                    ],
                ],
            ],
        ]);
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }
}
