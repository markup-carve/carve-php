<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 1 S4: after a block that leaves no paragraph open in a quote and item
 * stack, an unmarked line closes every container (carve-php#4241). Expected
 * HTML is the spec model's.
 */
class AnUnmarkedLineAfterAClosedBlockClosesItsContainersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/unmarked_line_after_closed_block.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('rows')]
    public function testTheLineLeavesEveryContainer(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
