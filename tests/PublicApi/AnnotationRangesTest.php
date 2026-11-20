<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeError;
use PHPUnit\Framework\TestCase;

final class AnnotationRangesTest extends TestCase
{
    /**
     * @var array
     */
    private const TREE = [
        'type' => 'document',
        'astVersion' => '2.0',
        'extensions' => [['name' => 'carve.annotation', 'version' => '1', 'required' => false, 'slots' => []]],
        'children' => [
            [

                'type' => 'paragraph',
                'children' => [['type' => 'text', 'value' => 'a']],
                'data' => ['carve.annotation' => ['ids' => ['note']]],
            ],
        ],
    ];

    /**
     * @var array
     */
    private const RANGE = [

        'id' => 'note',
        'start' => [
            'path' => '/children/0',
            'offset' => 0,
        ],
        'end' => ['path' => '/children/0', 'offset' => 1],
    ];

    public function testBothCallsAcceptValidSidecarAndOmission(): void
    {
        $json = json_encode(self::TREE, JSON_THROW_ON_ERROR);
        Carve::validateTree(self::TREE, ['annotations' => ['ranges' => [self::RANGE]]]);
        self::assertSame('document', Carve::decodeTree($json, ['annotations' => ['ranges' => [self::RANGE]]])->type);
        Carve::validateTree(self::TREE);
        self::assertSame('document', Carve::decodeTree($json)->type);
    }

    public function testDuplicateRangeRefused(): void
    {
        $sidecar = ['ranges' => [self::RANGE, self::RANGE]];
        foreach (
            [
                static fn (): mixed => Carve::decodeTree(json_encode(self::TREE, JSON_THROW_ON_ERROR), ['annotations' => $sidecar]),
                static fn (): mixed => Carve::validateTree(self::TREE, ['annotations' => $sidecar]),
            ] as $call
        ) {
            try {
                $call();
                self::fail('duplicate range accepted');
            } catch (TreeError $error) {
                self::assertSame('/ranges/1/id', $error->pointer);
            }
        }
    }
}
