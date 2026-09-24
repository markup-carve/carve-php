<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandaloneCitationIngestTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function inlineContainers(): array
    {
        return ['paragraph' => ['paragraph'], 'heading' => ['heading']];
    }

    #[DataProvider('inlineContainers')]
    public function testSchemaValidStandaloneCitationIsRefused(string $container): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => $container,
                    ...($container === 'heading' ? ['level' => 1] : []),
                    'children' => [
                        ['type' => 'citation', 'key' => 'x', 'suppressAuthor' => false],
                    ],
                ],
            ],
        ];

        foreach ([$payload, (string)json_encode($payload, JSON_THROW_ON_ERROR)] as $input) {
            try {
                $codec = new AstCodec();
                is_string($input) ? $codec->decodeJson($input) : $codec->decode($input);
                self::fail('The standalone citation was accepted');
            } catch (AstDecodeException $exception) {
                self::assertStringContainsString('Standalone citation nodes are not supported', $exception->getMessage());
            }
        }
    }

    public function testGroupOwnedCitationStillRoundTrips(): void
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
                            'raw' => '[@x]',
                            'items' => [['type' => 'citation', 'key' => 'x', 'suppressAuthor' => false]],
                        ],
                    ],
                ],
            ],
        ];
        $codec = new AstCodec();

        self::assertEqualsCanonicalizing($payload, $codec->encode($codec->decode($payload)));
    }
}
