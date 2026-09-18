<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The encoder writes two adjacent text nodes as one. The loss check walks the
 * payload and the re-encode in step, so the merge slid the lists apart and the
 * next node of another kind was read as a lost text node.
 */
class TwoTextNodesBeforeAnotherKindAreNotALossTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function payloads(): array
    {
        $document = static fn (array $children): array => [
            'type' => 'document',
            'srcByteLength' => 8,
            'children' => [['type' => 'paragraph', 'children' => $children]],
        ];
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'a break after two texts' => [
                $document([$text('a'), $text('b'), ['type' => 'hard_break'], $text('c')]),
                "ab\\\nc\n",
            ],
            'an emphasis after two texts' => [
                $document([$text('a'), $text('b'), ['type' => 'emphasis', 'children' => [$text('c')]]]),
                "ab{/c/}\n",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $carve
     */
    #[DataProvider('payloads')]
    public function testThePayloadDecodes(array $payload, string $carve): void
    {
        $this->assertSame($carve, (new CarveRenderer())->render((new AstCodec())->decode($payload)));
    }

    public function testARealLossIsStillReported(): void
    {
        // LIVENESS. `order` without `keyValues` has nothing to re-encode, so
        // the re-encode drops the whole `attrs` object. The merge must not
        // swallow the node carrying it.
        $payload = [
            'type' => 'document',
            'srcByteLength' => 8,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        ['type' => 'emphasis', 'attrs' => ['order' => ['z']], 'children' => [['type' => 'text', 'value' => 'c']]],
                    ],
                ],
            ],
        ];

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testOnlyTextIsJoined(): void
    {
        // `code` carries a `value` too, and the encoder does NOT join two of
        // them, so joining them here would hide the loss on the second one.
        $payload = [
            'type' => 'document',
            'srcByteLength' => 8,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'code', 'value' => 'a'],
                        ['type' => 'code', 'value' => 'b', 'attrs' => ['order' => ['z']]],
                    ],
                ],
            ],
        ];

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }

    public function testAQuoteWithACiteAndABreakImports(): void
    {
        $this->assertSame(
            "[“a\\\nb”]{cite=c}\n",
            (new HtmlToCarve())->convert('<p><q cite="c">a<br>b</q></p>'),
        );
    }
}
