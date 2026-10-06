<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use JsonException;
use MarkupCarve\Carve\Ast\AstEnvelope;
use MarkupCarve\Carve\Ast\AstMerge;
use MarkupCarve\Carve\Ast\AstPatch;
use MarkupCarve\Carve\Ast\AstStructuralIndex;
use MarkupCarve\Carve\Ast\NodeIdentitySession;
use MarkupCarve\Carve\Ast\Provenance;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class AstStructuralScalingTest extends TestCase
{
    use ScalingGuardTrait;

    private static function tree(int $depth, string $first = 'a', string $second = 'b'): array
    {
        $node = [

            'type' => 'block_quote',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $first]]],
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $second]]],
            ],
        ];
        for ($i = 0; $i < $depth; $i++) {
            $node = ['type' => 'block_quote', 'children' => [$node]];
        }

        return ['type' => 'document', 'srcByteLength' => 0, 'children' => [$node]];
    }

    public function testDeepPatchAndIndependentMergeLeaves(): void
    {
        $base = self::tree(40);
        $ours = self::tree(40, 'ours');
        $patch = AstPatch::create($base, $ours);
        self::assertCount(1, $patch);
        self::assertSame(str_repeat('/children/0', 42) . '/children/0/value', $patch[0]['path']);
        self::assertSame(AstPatch::apply($ours, []), AstPatch::apply($base, $patch));
        self::assertSame(self::tree(40), $base);
        $merged = AstMerge::merge($base, $ours, self::tree(40, 'a', 'theirs'));
        self::assertTrue($merged['ok']);
        self::assertSame(AstPatch::apply(self::tree(40, 'ours', 'theirs'), []), $merged['ast']);
    }

    public function testExactIndexRetainsAttributeMetadataAndJsonShape(): void
    {
        $index = new AstStructuralIndex();
        self::assertSame($index->build(['type' => 'text', 'value' => 'a', 'pos' => 1])->id, $index->build(['value' => 'a', 'type' => 'text'])->id);
        self::assertNotSame($index->build([])->id, $index->build((object)[])->id);
        self::assertNotSame($index->build(['keyValues' => ['pos' => 'a']])->id, $index->build(['keyValues' => ['pos' => 'b']])->id);
    }

    public function testForwardParentsAndSharedAncestry(): void
    {
        Provenance::read(self::tree(0), [
            'version' => 1, 'sources' => [
                ['id' => 'a', 'parent' => 'root'], ['id' => 'b', 'parent' => 'root'], ['id' => 'root'],
            ], 'nodes' => [],
        ]);
        self::assertTrue(true);
        $this->expectException(InvalidArgumentException::class);
        Provenance::read(self::tree(0), [
            'version' => 1, 'sources' => [
                ['id' => 'a', 'parent' => 'b'], ['id' => 'b', 'parent' => 'a'],
            ], 'nodes' => [],
        ]);
    }

    public function testIdentityRetainsUnchangedLeavesAcrossDeepEdits(): void
    {
        $session = new NodeIdentitySession();
        $before = $session->emit(self::tree(40));
        $same = $session->emit(self::tree(40));
        self::assertSame($before, $same);
        $after = $session->emit(self::tree(40, 'edited'));
        $ids = array_column($before['nodes'], 'id', 'path');
        $next = array_column($after['nodes'], 'id', 'path');
        $parent = str_repeat('/children/0', 41);
        self::assertNotSame($ids[$parent . '/children/0/children/0'], $next[$parent . '/children/0/children/0']);
        self::assertSame($ids[$parent . '/children/1/children/0'], $next[$parent . '/children/1/children/0']);
        self::assertNotSame($ids[''], $next['']);
    }

    public function testIndexMatchesSortedNumericArrayJson(): void
    {
        $index = new AstStructuralIndex();
        self::assertSame($index->build(['p', 'q'])->id, $index->build([1 => 'q', 0 => 'p'])->id);
        self::assertSame([], AstPatch::create(['type' => 'document', 'children' => [], 'data' => ['p', 'q']], ['type' => 'document', 'children' => [], 'data' => [1 => 'q', 0 => 'p']]));
    }

    public function testIdentityIgnoresDataOutsideTypedNodes(): void
    {
        $session = new NodeIdentitySession();
        $result = $session->emit(['children' => [['type' => 'text', 'value' => 'a']], 'junk' => "\xff"]);
        self::assertCount(1, $result['nodes']);
        self::assertSame('/children/0', $result['nodes'][0]['path']);
    }

    public function testPatchKeepsJsonDepthLimit(): void
    {
        $this->expectException(JsonException::class);
        AstPatch::create(self::tree(260), self::tree(260, 'changed'));
    }

    public function testIdentityDetectsMutatedObjects(): void
    {
        $object = (object)['a' => 'before'];
        $ast = ['type' => 'text', 'value' => 'a', 'attrs' => ['keyValues' => $object]];
        $session = new NodeIdentitySession();
        $before = $session->emit($ast);
        $object->a = 'after';
        $after = $session->emit($ast);
        self::assertNotSame($before['nodes'][0]['id'], $after['nodes'][0]['id']);
        self::assertSame($after, $session->emit($ast));
    }

    public function testIdentityIndexRetainsOnlyTheLatestSnapshot(): void
    {
        $session = new NodeIdentitySession();
        for ($i = 0; $i < 100; $i++) {
            $session->emit(self::tree(0, 'revision' . $i));
        }
        $index = (new ReflectionProperty(NodeIdentitySession::class, 'index'))->getValue($session);
        $keys = (new ReflectionProperty(AstStructuralIndex::class, 'keys'))->getValue($index);
        self::assertLessThan(30, count($keys));
    }

    public function testPatchKeepsSignedZeroChanges(): void
    {
        $before = ['type' => 'document', 'children' => [], 'data' => 0.0];
        $after = ['type' => 'document', 'children' => [], 'data' => -0.0];
        $patch = AstPatch::createReversible($before, $after);
        self::assertSame([['op' => 'replace', 'path' => '/data', 'value' => -0.0]], $patch['forward']);
        self::assertNotSame($patch['beforeFingerprint'], $patch['afterFingerprint']);
        self::assertCount(1, $patch['inverse']);
    }

    public function testMergeDoesNotInspectBaseWhenRevisionsAreSemanticallyEqual(): void
    {
        $ours = self::tree(0);
        $theirs = $ours;
        $theirs['pos'] = ['startLine' => 2];
        $base = ['type' => 'document', 'children' => [], 'data' => NAN];
        $result = AstMerge::merge($base, $ours, $theirs);
        self::assertTrue($result['ok']);
        self::assertSame(AstPatch::apply($ours, []), $result['ast']);
    }

    #[Group('scaling')]
    public function testDeepMergeAndPatchScale(): void
    {
        foreach (['merge', 'patch'] as $operation) {
            $this->assertConversionScalesLinearly(static function (string $input) use ($operation): void {
                $depth = strlen($input);
                $base = self::tree($depth);
                $ours = self::tree($depth, 'ours');
                if ($operation === 'patch') {
                    AstPatch::create($base, $ours);
                } else {
                    AstMerge::merge($base, $ours, self::tree($depth, 'a', 'theirs'));
                }
            }, str_repeat('x', 60), str_repeat('x', 240), 'deep ' . $operation, 60, 240);
        }
    }

    #[Group('scaling')]
    public function testProvenanceAncestryScales(): void
    {
        foreach ([false, true] as $reverse) {
            $build = static function (int $n) use ($reverse): string {
                $sources = [['id' => 's0']];
                for ($i = 1; $i < $n; $i++) {
                    $sources[] = ['id' => 's' . $i, 'parent' => 's' . ($i - 1)];
                }

                return json_encode(['version' => 1, 'sources' => $reverse ? array_reverse($sources) : $sources, 'nodes' => []], JSON_THROW_ON_ERROR);
            };
            $this->assertConversionScalesLinearly(static function (string $input): void {
                Provenance::read(self::tree(0), json_decode($input, true, 512, JSON_THROW_ON_ERROR));
            }, $build(2000), $build(8000), 'provenance ancestry', 2000, 8000);
        }
    }

    #[Group('scaling')]
    public function testWidePatchReplayScales(): void
    {
        $this->assertConversionScalesLinearly(static function (string $input): void {
            $classes = [];
            $operations = [];
            for ($i = 0, $n = strlen($input); $i < $n; $i++) {
                $classes[] = 'before' . $i;
                $operations[] = ['op' => 'replace', 'path' => '/children/0/attrs/classes/' . $i, 'value' => 'after' . $i];
            }
            $ast = [

                'type' => 'document',
                'srcByteLength' => 0,
                'children' => [
                    ['type' => 'paragraph', 'attrs' => ['classes' => $classes], 'children' => [['type' => 'text', 'value' => 'a']]],
                ],
            ];
            $result = AstPatch::apply($ast, $operations);
            self::assertSame('after' . ($n - 1), $result['children'][0]['attrs']['classes'][$n - 1]);
        }, str_repeat('x', 8000), str_repeat('x', 32000), 'wide patch replay', 8000, 32000);
    }

    #[Group('scaling')]
    public function testEnvelopeExtensionMembershipScales(): void
    {
        $build = static function (int $n): string {
            $extensions = [];
            for ($i = 0; $i < $n; $i++) {
                $extensions[] = ['id' => 'extension' . $i];
            }

            return json_encode($extensions, JSON_THROW_ON_ERROR);
        };
        $this->assertConversionScalesLinearly(static function (string $input): void {
            $extensions = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            (new AstEnvelope())->decode(['astVersion' => '1.0', 'document' => self::tree(0), 'extensions' => $extensions], array_column($extensions, 'id'));
        }, $build(2000), $build(8000), 'envelope extensions', 2000, 8000);
    }
}
