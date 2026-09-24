<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\LineBlock;
use PHPUnit\Framework\TestCase;

final class LineBlockRangesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'line_block',
                    'children' => [
                        [
                            'type' => 'paragraph',
                            'children' => [
                                [
                                    'type' => 'strong',
                                    'children' => [
                                        ['type' => 'text', 'value' => 'Roses'],
                                        ['type' => 'hard_break'],
                                        ['type' => 'text', 'value' => 'Violets'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'lines' => [['/children/0/children/1', '/children/-']],
                ],
            ],
        ];
    }

    public function testRangesSurviveInterchange(): void
    {
        $codec = new AstCodec();
        $payload = self::payload();
        $document = $codec->decode($payload);
        $node = $document->getChildren()[0];

        self::assertInstanceOf(LineBlock::class, $node);
        self::assertSame($payload['children'][0]['lines'], $node->getLines());
        self::assertEquals($payload, $codec->encode($document));
    }

    public function testTheSchemaRejectsTheFormerInlineArrayShape(): void
    {
        $payload = self::payload();
        $payload['children'][0]['lines'] = [[['type' => 'text', 'value' => 'Roses']]];

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testRangesMustMatchStanzasAndEndAtTheStanzaBoundary(): void
    {
        $codec = new AstCodec();
        $mismatched = self::payload();
        $mismatched['children'][0]['lines'] = [];
        try {
            $codec->decode($mismatched);
            self::fail('Expected a stanza count error');
        } catch (AstDecodeException $exception) {
            self::assertStringContainsString('one entry per child stanza', $exception->getMessage());
        }

        $misordered = self::payload();
        $misordered['children'][0]['lines'] = [['/children/-', '/children/0/children/1']];
        $this->expectException(AstDecodeException::class);
        $codec->decode($misordered);
    }

    public function testRangesRejectMissingAndRepeatedBreakPointers(): void
    {
        $codec = new AstCodec();
        $missing = self::payload();
        $missing['children'][0]['lines'] = [['/children/99', '/children/-']];
        try {
            $codec->decode($missing);
            self::fail('Expected a missing boundary error');
        } catch (AstDecodeException $exception) {
            self::assertStringContainsString('hard_break nodes', $exception->getMessage());
        }

        $repeated = self::payload();
        $repeated['children'][0]['lines'] = [['/children/0/children/1', '/children/0/children/1', '/children/-']];
        $this->expectException(AstDecodeException::class);
        $codec->decode($repeated);
    }

    public function testTheSchemaRequiresExactlyOneStanzaEndPointer(): void
    {
        $missing = self::payload();
        $missing['children'][0]['lines'] = [['/children/0/children/1']];
        self::assertNotNull(AstSchema::firstViolation($missing));

        $repeated = self::payload();
        $repeated['children'][0]['lines'] = [['/children/-', '/children/-']];
        self::assertNotNull(AstSchema::firstViolation($repeated));
    }
}
