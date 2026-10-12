<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADescriptionKeepsACodeBlockWhateverLeadsTheItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id: string, source: string, html: string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/description_keeps_code_whatever_leads_the_item.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('rows')]
    public function testOwnership(string $source, string $expected): void
    {
        $html = (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));

        $this->assertSame($expected, trim((string)preg_replace('/ ?(<[^>]+>) ?/', '$1', $html)));
    }
}
