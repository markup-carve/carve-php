<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Ast\TextRunCoalescer;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1995. PART 12 §1a: a run an inline include assembled from more
 * than one file spans its host pieces, first start to last end, where a run
 * from one file publishes none unless its pieces are contiguous.
 */
class AMergedIncludeRunKeepsTheHostSpanTest extends TestCase
{
    /**
     * @param string $source
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function expanded(string $source, array $files): array
    {
        $converter = CarveConverter::create();
        $converter->getParser()->enablePositionTracking();
        $resolver = new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                return isset($this->files[$path]) ? new ResolvedInclude($this->files[$path], $path) : null;
            }
        };
        $expander = new IncludeExpander(resolver: $resolver, currentPath: 'entry.crv');

        return (new AstCodec())->encode($expander->transform($converter->parse($source)));
    }

    /**
     * @param array<string, mixed> $ast
     * @param int $index
     *
     * @return array<string, mixed>
     */
    private function onlyChild(array $ast, int $index): array
    {
        $block = $ast['children'][$index];
        $this->assertCount(1, $block['children']);

        return $block['children'][0];
    }

    public function testTheRunCarriesTheSpanOfTheHostTextItWasSplicedInto(): void
    {
        $ast = $this->expanded("Root {{ sub/child.crv }} tail.\n", ['sub/child.crv' => "inlined text\n"]);

        $this->assertSame([
            'type' => 'text',
            'value' => 'Root inlined text tail.',
            'pos' => [
                'startLine' => 1,
                'endLine' => 1,
                'startColumn' => 1,
                'endColumn' => 31,
                'startOffset' => 0,
                'endOffset' => 30,
            ],
        ], $this->onlyChild($ast, 0));
    }

    public function testItRunsFromTheFirstHostPieceToTheLastWhenTheyCameFromTwoNodes(): void
    {
        // `@shift` parses as a mention, so the host halves are two text nodes.
        $ast = $this->expanded("Root {{ c.crv @shift:1 }} tail.\n", ['c.crv' => "inlined\n"]);
        $text = $this->onlyChild($ast, 0);

        $this->assertSame('Root inlined tail.', $text['value']);
        $this->assertSame(0, $text['pos']['startOffset']);
        $this->assertSame(31, $text['pos']['endOffset']);
    }

    public function testItCarriesTheHostSpanWhenTheIncludeLeadsTheRun(): void
    {
        $ast = $this->expanded("{{ c.crv }} tail.\n", ['c.crv' => "lead\n"]);
        $text = $this->onlyChild($ast, 0);

        $this->assertSame('lead tail.', $text['value']);
        $this->assertSame(0, $text['pos']['startOffset']);
        $this->assertSame(17, $text['pos']['endOffset']);
        $this->assertArrayNotHasKey('file', $text['pos']);
    }

    public function testItNamesTheChildFileWhenTheRunSitsInAChild(): void
    {
        $ast = $this->expanded("Root\n\n{{ a.crv }}\n", [
            'a.crv' => "x\n\nMid {{ b.crv }} end\n",
            'b.crv' => "deep\n",
        ]);

        $this->assertSame([
            'type' => 'text',
            'value' => 'Mid deep end',
            'pos' => [
                'startLine' => 3,
                'endLine' => 3,
                'startColumn' => 1,
                'endColumn' => 20,
                'startOffset' => 3,
                'endOffset' => 22,
                'file' => 'a.crv',
            ],
        ], $this->onlyChild($ast, 2));
    }

    public function testAHostPieceThatDoesNotMergeKeepsItsOwnExtent(): void
    {
        // The child is a strong, so neither half merges with anything.
        $ast = $this->expanded("Root {{ c.crv }} tail.\n", ['c.crv' => "*b*\n"]);
        $children = $ast['children'][0]['children'];

        $this->assertSame('Root ', $children[0]['value']);
        $this->assertSame([0, 5], [$children[0]['pos']['startOffset'], $children[0]['pos']['endOffset']]);
        $this->assertSame(' tail.', $children[2]['value']);
        $this->assertSame([16, 22], [$children[2]['pos']['startOffset'], $children[2]['pos']['endOffset']]);
    }

    public function testARefusedDirectiveLeavesTheHostTextAndItsSpanAlone(): void
    {
        $ast = $this->expanded("Root {{ missing.crv }} tail.\n", []);
        $text = $this->onlyChild($ast, 0);

        $this->assertSame('Root {{ missing.crv }} tail.', $text['value']);
        $this->assertSame([0, 28], [$text['pos']['startOffset'], $text['pos']['endOffset']]);
    }

    public function testARunFromOneFileStillPublishesNoSpanWhereItsPiecesAreNotContiguous(): void
    {
        $at = static fn (int $start, int $end): SourceSpan => new SourceSpan(1, 1, $start + 1, $end + 1, $start, $end);

        $this->assertNull(TextRunCoalescer::mergedPos($at(0, 1), $at(2, 3)));
        $this->assertSame(3, TextRunCoalescer::mergedPos($at(0, 1), $at(1, 3))?->endOffset);
    }
}
