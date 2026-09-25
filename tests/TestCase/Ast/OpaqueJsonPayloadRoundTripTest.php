<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\PayloadDepth;
use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;
use stdClass;

class OpaqueJsonPayloadRoundTripTest extends TestCase
{
    public function testAstJsonKeepsNestedEmptyObjectsAndArraysDistinct(): void
    {
        $json = json_encode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'block_extension',
                    'name' => 'org.example.diagram',
                    'fallback' => ['type' => 'paragraph', 'children' => []],
                    'payload' => [

                        'format' => 'json',
                        'value' => (object)[
                            'emptyObject' => (object)[],
                            'emptyArray' => [],
                            'nodeLike' => (object)['type' => 'table_row'],
                            'arrayLike' => ['type' => 'caption'],
                            'attrs' => (object)['foo' => 1],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $codec = new AstCodec();
        $document = $codec->decodeJson($json);
        $extension = $document->getChildren()[0];
        $this->assertInstanceOf(BlockExtension::class, $extension);
        $value = $extension->getPayload()['value'];
        $this->assertInstanceOf(stdClass::class, $value);
        $this->assertInstanceOf(stdClass::class, $value->emptyObject);
        $this->assertSame([], $value->emptyArray);

        $encoded = json_decode($codec->encodeJson($document), false, 512, JSON_THROW_ON_ERROR);
        $encodedValue = $encoded->children[0]->payload->value;
        $this->assertInstanceOf(stdClass::class, $encodedValue->emptyObject);
        $this->assertSame([], $encodedValue->emptyArray);
        $this->assertSame('table_row', $encodedValue->nodeLike->type);
        $this->assertSame('caption', $encodedValue->arrayLike->type);
        $this->assertSame(1, $encodedValue->attrs->foo);

        $arrayDecoded = $codec->decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(BlockExtension::class, $arrayDecoded->getChildren()[0]);
    }

    public function testProseMirrorJsonKeepsOpaqueObjectIdentity(): void
    {
        $json = json_encode([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveBlockExtension',
                    'attrs' => [
                        'name' => 'org.example.diagram',
                        'payload' => [

                            'format' => 'json',
                            'value' => (object)[
                                'emptyObject' => (object)[],
                                'emptyArray' => [],
                            ],
                        ],
                    ],
                    'content' => [['type' => 'paragraph']],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $document = (new ProseMirrorToCarve())->convertJson($json);
        $extension = $document->getChildren()[0];
        $this->assertInstanceOf(BlockExtension::class, $extension);
        $this->assertInstanceOf(stdClass::class, $extension->getPayload()['value']->emptyObject);

        $pm = (new ProseMirrorRenderer())->render($document);
        $encoded = json_decode(json_encode($pm, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $value = $encoded->content[0]->attrs->payload->value;
        $this->assertInstanceOf(stdClass::class, $value->emptyObject);
        $this->assertSame([], $value->emptyArray);
    }

    public function testOpaqueObjectsCountTowardTheDecodeDepthLimit(): void
    {
        $nested = (object)[];
        for ($i = 0; $i < 10; $i++) {
            $nested = (object)['child' => $nested];
        }

        $this->assertFalse(PayloadDepth::within(['payload' => $nested], 10));
    }

    public function testOpaqueObjectBytesCountTowardExpansionBudget(): void
    {
        $data = [
            'type' => 'document',
            'srcByteLength' => 5000,
            'children' => [
                [
                    'type' => 'block_extension',
                    'name' => 'org.example.diagram',
                    'fallback' => ['type' => 'paragraph', 'children' => []],
                    'payload' => ['format' => 'json', 'value' => (object)['large' => str_repeat('x', 10000)]],
                ],
            ],
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $codec = new AstCodec();

        $fromJson = $codec->decodeJson($json);
        $fromArray = $codec->decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(5000, $fromJson->getExpansionBudgetLength());
        $this->assertSame($fromArray->getExpansionBudgetLength(), $fromJson->getExpansionBudgetLength());
    }
}
