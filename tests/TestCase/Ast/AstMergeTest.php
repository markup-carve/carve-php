<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstMerge;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

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
        $base = ['type' => 'document', 'children' => [], 'probe' => "\0carve-missing\0"];
        $ours = ['type' => 'document', 'children' => [], 'probe' => 'ours'];
        $theirs = ['type' => 'document', 'children' => [], 'probe' => "\0carve-missing\0"];

        $result = AstMerge::merge($base, $ours, $theirs);

        $this->assertTrue($result['ok']);
        $this->assertSame('ours', $result['ast']['probe']);
    }

    public function testResolverCanChooseANullBaseValue(): void
    {
        $base = ['type' => 'document', 'children' => [], 'probe' => null];
        $ours = ['type' => 'document', 'children' => [], 'probe' => 'ours'];
        $theirs = ['type' => 'document', 'children' => [], 'probe' => 'theirs'];

        $result = AstMerge::merge($base, $ours, $theirs, static fn (): string => 'base');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('probe', $result['ast']);
        $this->assertNull($result['ast']['probe']);
    }
}
