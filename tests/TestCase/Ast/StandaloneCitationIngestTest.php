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

    /**
     * carve#2228 and carve#2229 took `citation` out of `inlineNode`, so the
     * shape this test sends is no longer schema-valid and §12(d) refuses it
     * before `decodeNode()` is reached. The refusal is what this test is for,
     * and it now comes from the schema.
     *
     * `decodeNode()`'s own standalone-citation message is therefore UNREACHABLE:
     * every call to it is downstream of `verifySchema()`, and a group's items
     * are decoded as maps rather than through it. It stays only as the answer
     * if a later ruling puts `citation` back in the inline dispatch, and
     * nothing here asserts it, because an assertion on a branch that cannot run
     * is the check this repository keeps finding.
     */
    #[DataProvider('inlineContainers')]
    public function testStandaloneCitationIsRefused(string $container): void
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
                self::assertStringContainsString(
                    '.type is the string "citation", which the schema does not list',
                    $exception->getMessage(),
                );
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
