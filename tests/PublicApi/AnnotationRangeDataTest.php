<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeError;
use PHPUnit\Framework\TestCase;

/**
 * C2-A-214, C2-A-029: a range may carry data with qualified, declared keys (carve#6885).
 */
final class AnnotationRangeDataTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private const TREE = [
        'type' => 'document',
        'astVersion' => '2.0',
        'extensions' => [['name' => 'x.note', 'version' => '1', 'required' => false, 'slots' => []]],
        'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'hello']]]],
    ];

    /**
     * @var array<string, mixed>
     */
    private const RANGE = [
        'id' => 'r1',
        'start' => ['path' => '/children/0', 'offset' => 0],
        'end' => ['path' => '/children/0', 'offset' => 5],
        'data' => ['x.note' => ['by' => 'a']],
    ];

    public function testDeclaredDataIsAcceptedAndKept(): void
    {
        $sidecar = ['ranges' => [self::RANGE]];
        $before = json_encode($sidecar, JSON_THROW_ON_ERROR);
        Carve::validateTree(self::TREE, ['annotations' => $sidecar]);
        $tree = Carve::decodeTree(json_encode(self::TREE, JSON_THROW_ON_ERROR), ['annotations' => $sidecar]);
        self::assertSame('document', $tree->type);
        self::assertSame($before, json_encode($sidecar, JSON_THROW_ON_ERROR));
    }

    public function testSidecarRoundTripsThroughJson(): void
    {
        $json = json_encode(['ranges' => [self::RANGE]], JSON_THROW_ON_ERROR);
        $read = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        Carve::validateTree(self::TREE, ['annotations' => $read]);
        self::assertSame($json, json_encode($read, JSON_THROW_ON_ERROR));
    }

    public function testDeclarationOnEnvelope(): void
    {
        $bare = self::TREE;
        unset($bare['extensions']);
        $envelope = ['astVersion' => '2.0', 'extensions' => self::TREE['extensions'], 'document' => $bare];
        $tree = Carve::decodeTree(json_encode($envelope, JSON_THROW_ON_ERROR), ['annotations' => ['ranges' => [self::RANGE]]]);
        self::assertSame('document', $tree->type);
    }

    public function testUndeclaredKeyRefused(): void
    {
        $bare = ['extensions' => []] + self::TREE;
        $this->assertRefused($bare, ['ranges' => [self::RANGE]], '/ranges/0/data/x.note', 'data-undeclared');
    }

    public function testUnqualifiedKeyRefused(): void
    {
        $range = ['data' => ['note' => 1]] + self::RANGE;
        $this->assertRefused(self::TREE, ['ranges' => [$range]], '/ranges/0/data/note', 'annotation-range-schema');
    }

    public function testNonObjectRefused(): void
    {
        $range = ['data' => 1] + self::RANGE;
        $this->assertRefused(self::TREE, ['ranges' => [$range]], '/ranges/0/data', 'annotation-range-schema');
    }

    public function testNodeInsideDataRefused(): void
    {
        $range = ['data' => ['x.note' => ['type' => 'text', 'value' => 'a']]] + self::RANGE;
        $this->assertRefused(self::TREE, ['ranges' => [$range]], '/ranges/0/data/x.note', 'data-holds-node');
        $deep = ['data' => ['x.note' => ['list' => [['type' => 'text', 'value' => 'a']]]]] + self::RANGE;
        $this->assertRefused(self::TREE, ['ranges' => [$deep]], '/ranges/0/data/x.note/list/0', 'data-holds-node');
    }

    public function testRangeOtherwiseClosed(): void
    {
        $range = ['kind' => 'comment'] + self::RANGE;
        $this->assertRefused(self::TREE, ['ranges' => [$range]], '/ranges/0/kind', 'annotation-range-schema');
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $sidecar
     * @param string $pointer
     * @param string $reason
     */
    private function assertRefused(array $tree, array $sidecar, string $pointer, string $reason): void
    {
        $calls = [
            static function () use ($tree, $sidecar): void {
                Carve::validateTree($tree, ['annotations' => $sidecar]);
            },
            static function () use ($tree, $sidecar): void {
                Carve::decodeTree(json_encode($tree, JSON_THROW_ON_ERROR), ['annotations' => $sidecar]);
            },
        ];
        foreach ($calls as $call) {
            $this->assertRefusedBy($call, $pointer, $reason);
        }
    }

    private function assertRefusedBy(Closure $call, string $pointer, string $reason): void
    {
        try {
            $call();
            self::fail('range data accepted at ' . $pointer);
        } catch (TreeError $error) {
            self::assertSame($pointer, $error->pointer);
            self::assertSame($reason, $error->reason);
        }
    }
}
