<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuoteFenceEndsItsLazyClaimTest extends TestCase
{
    public function testFenceOwnership(): void
    {
        /** @var list<array{name:string, source:string, html:string}> $rows*/
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/quote-fence-ownership.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(23, $rows);
        $converter = new CarveConverter();
        $failures = [];
        foreach ($rows as $row) {
            $actual = trim($converter->convert($row['source']));
            if ($actual !== $row['html']) {
                $failures[] = $row['name'] . "\n" . $actual;
            }
        }
        $this->assertSame([], $failures);
    }
}
