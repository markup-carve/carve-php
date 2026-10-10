<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class NestedContinuationParagraphClaimTest extends TestCase
{
    public function testAnOrdinaryLineReplacesTheBareContinuationLead(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/nested-continuation-paragraph-claim.json'), true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            $source = $case['source'];
            $this->assertSame(trim($case['html']), trim($converter->convert($source)), $source);
            $formatted = CarveConverter::toCarve($source);
            $this->assertSame(trim($case['html']), trim($converter->convert($formatted)), 'formatted ' . $source);
            $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'idempotence ' . $source);
        }
    }
}
