<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstMerge;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use Throwable;

class AstMergeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function ast(string $source): array
    {
        return (new AstCodec())->encode(CarveConverter::create()->parse($source));
    }

    public function testMergesIndependentEdits(): void
    {
        $result = AstMerge::merge(
            $this->ast("# Old\n\nSee [docs](/a).\n"),
            $this->ast("# New\n\nSee [docs](/a).\n"),
            $this->ast("# Old\n\nSee [docs](/b).\n"),
        );

        $this->assertTrue($result['ok']);
        $json = json_encode($result['ast'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('New', $json);
        $this->assertStringContainsString('/b', $json);
        $this->assertStringNotContainsString('"pos"', $json);
    }

    public function testRejectsAResolvedInvalidTree(): void
    {
        $base = $this->ast("Base.\n");
        $ours = $this->ast("Ours.\n");
        $theirs = $this->ast("Theirs.\n");

        $this->expectException(Throwable::class);
        AstMerge::merge($base, $ours, $theirs, static fn (): array => [
            'value' => ['type' => 'unknown-node'],
        ]);
    }

    public function testDeduplicatesIdenticalInsertionsWithDifferentKeyOrder(): void
    {
        $base = $this->ast("Base.\n");
        $ours = $this->ast("Base.\n\nAdded.\n");
        $theirs = $ours;
        $theirs['children'][1] = array_reverse($theirs['children'][1], true);

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['ast']['children']);
    }

    public function testMergesConcurrentInsertions(): void
    {
        $result = AstMerge::merge(
            $this->ast("one\n"),
            $this->ast("one\n\ntwo\n"),
            $this->ast("one\n\nthree\n"),
        );

        $this->assertTrue($result['ok']);
        $json = json_encode($result['ast'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('two', $json);
        $this->assertStringContainsString('three', $json);
    }

    public function testMergesMoveWithEdit(): void
    {
        $result = AstMerge::merge(
            $this->ast("alpha\n\nbeta\n"),
            $this->ast("beta\n\nalpha\n"),
            $this->ast("alpha\n\nbeta edited\n"),
        );

        $this->assertTrue($result['ok']);
        $json = json_encode($result['ast'], JSON_THROW_ON_ERROR);
        $this->assertLessThan(strpos($json, 'alpha'), strpos($json, 'beta edited'));
    }

    public function testReportsDeleteEditAndDeletionShape(): void
    {
        $result = AstMerge::merge(
            $this->ast("alpha\n\nbeta\n"),
            $this->ast("alpha\n"),
            $this->ast("alpha\n\nbeta edited\n"),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('delete-edit', $result['conflicts'][0]['reason']);
        $this->assertTrue($result['conflicts'][0]['deleted']['ours']);
    }

    public function testResolverCanChooseOneSide(): void
    {
        $result = AstMerge::merge(
            $this->ast("# Base\n"),
            $this->ast("# Ours\n"),
            $this->ast("# Theirs\n"),
            static fn (array $conflict): ?string => str_ends_with($conflict['path'], '/value') ? 'ours' : 'theirs',
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Ours', json_encode($result['ast'], JSON_THROW_ON_ERROR));
    }

    public function testMissingMarkerCannotCollideWithAuthoredText(): void
    {
        $node = static fn (mixed $value): array => ['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]]]];
        $base = $node("\0carve-missing\0");
        $ours = $node('ours');
        $theirs = $node("\0carve-missing\0");

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame('ours', $result['ast']['children'][0]['children'][0]['value']);
    }

    public function testResolvedInvalidNullValueIsRejected(): void
    {
        $node = static fn (mixed $value): array => ['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]]]];
        $base = $node(null);
        $ours = $node('ours');
        $theirs = $node('theirs');

        $this->expectException(Throwable::class);
        AstMerge::merge($base, $ours, $theirs, static fn (): string => 'base');
    }

    public function testResolverCanSupplyACustomValue(): void
    {
        $result = AstMerge::merge(
            $this->ast("base\n"),
            $this->ast("ours\n"),
            $this->ast("theirs\n"),
            static fn (): array => ['value' => 'resolved'],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('resolved', $result['ast']['children'][0]['children'][0]['value']);
    }

    public function testIdentityHintsTrackEditedNodesAcrossAMove(): void
    {
        $node = static fn (string $id, string $value): array => [
            'type' => 'heading',
            'level' => 1,
            'attrs' => ['id' => $id],
            'children' => [['type' => 'text', 'value' => $value]],
        ];
        $base = ['type' => 'document', 'children' => [$node('a', 'A'), $node('b', 'B')]];
        $ours = ['type' => 'document', 'children' => [$node('b', 'B changed'), $node('a', 'A')]];
        $theirs = ['type' => 'document', 'children' => [$node('a', 'A changed'), $node('b', 'B')]];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame('b', $result['ast']['children'][0]['attrs']['id']);
        $this->assertSame('B changed', $result['ast']['children'][0]['children'][0]['value']);
        $this->assertSame('A changed', $result['ast']['children'][1]['children'][0]['value']);
    }

    public function testLcsMatchesSeveralEditedSiblings(): void
    {
        $node = static fn (string $value): array => ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]];
        $base = ['type' => 'document', 'children' => [$node('A'), $node('B'), $node('C')]];
        $ours = ['type' => 'document', 'children' => [$node('A1'), $node('B1'), $node('C1')]];
        $theirs = ['type' => 'document', 'children' => [$node('A'), $node('B'), $node('C'), $node('D')]];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame(['A1', 'B1', 'C1', 'D'], array_map(static fn (array $item): string => $item['children'][0]['value'], $result['ast']['children']));
    }

    public function testVeryWideAmbiguousSequencesUseTheBoundedMatcher(): void
    {
        $baseChildren = [];
        $oursChildren = [];
        for ($index = 0; $index < 1_001; ++$index) {
            $baseChildren[] = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'base-' . $index]]];
            $oursChildren[] = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'ours-' . $index]]];
        }
        $base = ['type' => 'document', 'children' => $baseChildren];
        $ours = ['type' => 'document', 'children' => $oursChildren];
        $theirs = ['type' => 'document', 'children' => [...$baseChildren, ['type' => 'heading', 'level' => 1, 'children' => []]]];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertCount(1_002, $result['ast']['children']);
    }

    public function testIdenticalConcurrentInsertionIsEmittedOnce(): void
    {
        $node = static fn (string $id, string $value): array => [
            'type' => 'heading',
            'level' => 1,
            'attrs' => ['id' => $id],
            'children' => [['type' => 'text', 'value' => $value]],
        ];
        $same = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Same']]];
        $base = ['type' => 'document', 'children' => [$node('a', 'A'), $node('b', 'B')]];
        $ours = ['type' => 'document', 'children' => [$node('a', 'A ours'), $same, $node('b', 'B')]];
        $theirs = ['type' => 'document', 'children' => [$node('a', 'A'), $same, $node('b', 'B theirs')]];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, substr_count(json_encode($result['ast'], JSON_THROW_ON_ERROR), 'Same'));
    }

    public function testAuthorAttributesNamedLikeMetadataSurvive(): void
    {
        $node = static fn (string $value, string $attribute): array => [
            'type' => 'paragraph',
            'children' => [['type' => 'text', 'value' => $value, 'attrs' => ['keyValues' => ['pos' => $attribute, 'srcByteLength' => $attribute]]]],
        ];
        $base = ['type' => 'document', 'children' => [$node('base', 'base')]];
        $ours = ['type' => 'document', 'children' => [$node('base', 'ours')]];
        $theirs = ['type' => 'document', 'children' => [$node('theirs', 'base')]];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame(['pos' => 'ours', 'srcByteLength' => 'ours'], $result['ast']['children'][0]['children'][0]['attrs']['keyValues']);
    }

    public function testInvalidResolverAnswerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AstMerge::merge(
            ['type' => 'document', 'children' => [], 'probe' => 'base'],
            ['type' => 'document', 'children' => [], 'probe' => 'ours'],
            ['type' => 'document', 'children' => [], 'probe' => 'theirs'],
            static fn (): string => 'mine',
        );
    }

    public function testInvalidRootIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AstMerge::merge([], [], []);
    }
}
