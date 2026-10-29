<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P0-014: a fence in a description body interrupts its paragraph when a
 * closer follows at the owner's column, also past a list marker written at
 * column 0 (carve-php#4248). Expected HTML is the spec model's.
 */
class ADescriptionFenceCloserPastAMarkerOpensTheFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/description_fence_closer_past_marker.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('rows')]
    public function testTheFenceOpens(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
