<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An authored `header-rows` / `footer-rows` is EXPLICIT table structure, so it
 * belongs on the wire under `rowGroups` - the field
 * `resources/ast-schema.json` already names - and not only in an attribute a
 * foreign reader has to know how to reinterpret.
 *
 * carve-php published the attributes alone. carve-js and carve-rs read
 * `rowGroups`, so fed carve-php's JSON they rendered every row as a body row
 * and the authored head and foot were gone (markup-carve/carve-php#2633). The
 * partition values below were measured against carve-js `cc9bed84d` on the same
 * sources, which is the reader that was losing them.
 *
 * WHY THE ATTRIBUTE-STRIPPING GUARD IS THE ONE THAT MATTERS. A round trip
 * through carve-php's own codec passed the whole time this defect was live: the
 * attributes survive, and carve-php rebuilds its own head and foot from them. So
 * a structural round trip cannot see the loss. `testTheHeadSurvivesWithoutThe
 * Attributes()` removes the attributes from the payload, which leaves
 * `rowGroups` as the only thing that can carry the partition - exactly the
 * position a foreign reader is in.
 */
class AnAuthoredRowPartitionReachesTheWireTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, array{string, array<string, mixed>|null}>
     */
    public static function statedPartitions(): array
    {
        return [
            // Corpus 376-pipe-tables-can-state-head-and-foot-row-counts.
            'a head and a foot' => [
                "{header-rows=2 footer-rows=1}\n| Region | Q1 |\n| Detail | EUR |\n|= North | 11 |\n| All | 33 |\n",
                ['headRows' => 2, 'bodies' => [['headRows' => 0, 'bodyRows' => 1]], 'footRows' => 1],
            ],
            // Corpus 494-an-explicit-table-head-span-keeps-one-row-group. The
            // rowspan crossing the boundary changes the RENDER, not what the
            // source states, so the wire still carries the stated partition.
            'a head crossed by a row span' => [
                "{header-rows=1}\n| H | G |\n| ^ | b |\n",
                ['headRows' => 1, 'bodies' => [['headRows' => 0, 'bodyRows' => 1]], 'footRows' => 0],
            ],
            'a head alone' => [
                "{header-rows=1}\n| a |\n| b |\n",
                ['headRows' => 1, 'bodies' => [['headRows' => 0, 'bodyRows' => 1]], 'footRows' => 0],
            ],
            'a foot alone' => [
                "{footer-rows=1}\n| a |\n| b |\n",
                ['headRows' => 0, 'bodies' => [['headRows' => 0, 'bodyRows' => 1]], 'footRows' => 1],
            ],
            // A valueless attribute states one row.
            'a valueless count' => [
                "{header-rows}\n| a |\n| b |\n",
                ['headRows' => 1, 'bodies' => [['headRows' => 0, 'bodyRows' => 1]], 'footRows' => 0],
            ],
            // Stated zeros are still a stated partition.
            'stated zeros' => [
                "{header-rows=0 footer-rows=0}\n| a |\n| b |\n",
                ['headRows' => 0, 'bodies' => [['headRows' => 0, 'bodyRows' => 2]], 'footRows' => 0],
            ],
            // The head consumes every row, so no body group remains.
            'a head as long as the table' => [
                "{header-rows=2}\n| a |\n| b |\n",
                ['headRows' => 2, 'bodies' => [], 'footRows' => 0],
            ],
            // The refusals. Each one is a partition that could not account for
            // every row, which the schema forbids and the decoder rejects - so
            // publishing it would put an invalid document on the wire.
            'no counts at all' => ["| a | b |\n| c | d |\n", null],
            'a GFM separator states nothing' => ["| a | b |\n|---|---|\n| c | d |\n", null],
            'a count past the row total' => ["{header-rows=5}\n| a |\n| b |\n", null],
            'a head and foot past the row total' => ["{header-rows=2 footer-rows=2}\n| a |\n| b |\n", null],
            'a count that is not a number' => ["{header-rows=x}\n| a |\n| b |\n", null],
        ];
    }

    /**
     * @param string $source
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('statedPartitions')]
    public function testTheStatedPartitionIsPublished(string $source, ?array $expected): void
    {
        $table = $this->tableOf($source);
        $this->assertSame($expected, $table === null ? null : $table['rowGroups'] ?? null);
    }

    /**
     * The guard a same-engine round trip cannot be: with the attributes gone,
     * `rowGroups` is the only thing left that can carry the head and the foot.
     */
    public function testTheHeadSurvivesWithoutTheAttributes(): void
    {
        $source = "{header-rows=2 footer-rows=1}\n| Region | Q1 |\n| Detail | EUR |\n|= North | 11 |\n| All | 33 |\n";
        $fromSource = CarveConverter::create()->convert($source);
        $this->assertStringContainsString('<thead>', $fromSource);
        $this->assertStringContainsString('<tfoot>', $fromSource);

        $wire = $this->wireOf($source);
        unset($wire['children'][0]['attrs']);
        $this->assertArrayNotHasKey('attrs', $wire['children'][0]);

        $fromWire = (new HtmlRenderer())->render($this->codec->decode($wire));
        $this->assertSame($fromSource, $fromWire);
    }

    /**
     * The partition the decoder accepts is the one the encoder wrote, so a
     * published document is readable rather than merely well-formed.
     */
    public function testEveryPublishedPartitionDecodes(): void
    {
        foreach (self::statedPartitions() as $label => [$source, $expected]) {
            $wire = $this->wireOf($source);
            $this->assertSame($wire, $this->codec->encode($this->codec->decode($wire)), $label);
        }
    }

    /**
     * A body group consuming no rows renders no `<tbody>`. Before the partition
     * was stated on the node, `{header-rows=2}` over two rows reached the
     * renderer's derived fallback, which omits the group; stating it sent the
     * identical structure down the `rowGroups` branch, which did not.
     */
    public function testAnEmptyBodyGroupRendersNoSection(): void
    {
        $html = CarveConverter::create()->convert("{header-rows=2}\n| a |\n| b |\n");
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringNotContainsString('<tbody>', $html);
    }

    /**
     * The node-level helper answers for a table built by hand, not only for one
     * the parser filled in.
     */
    public function testAHandBuiltTableWithNoRowsStatesNothing(): void
    {
        $table = new Table();
        $this->assertNull($table->statedRowGroups());
        $table->setAttribute('header-rows', '1');
        $this->assertNull($table->statedRowGroups());
    }

    /**
     * @return array<string, mixed>
     */
    private function wireOf(string $source): array
    {
        return $this->codec->encode(CarveConverter::create()->parse($source));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tableOf(string $source): ?array
    {
        foreach ($this->wireOf($source)['children'] as $child) {
            if (($child['type'] ?? null) === 'table') {
                return $child;
            }
        }

        return null;
    }
}
