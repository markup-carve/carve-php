<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
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

    public function testMarkersDoNotReplaceAnOrdinaryClaim(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/nested-continuation-claim-boundaries.json'), true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            $this->assertSame(trim($case['html']), trim($converter->convert($case['source'])), $case['source']);
        }
    }

    public function testCommentLookaheadSharesBoundedWorkAcrossIndentationsAndDepths(): void
    {
        $parser = new class extends BlockParser {
            /**
             * @param array<string> $lines
             * @param int $index
             *
             * @return bool
             */
            public function closes(array $lines, int $index): bool
            {
                return $this->hasClosingCommentFenceAheadInBlockQuote($lines, $index, 3);
            }
        };
        $lines = array_fill(0, 10000, 'text');
        $queries = [];
        for ($indent = 1; $indent <= 128; $indent++) {
            $line = str_repeat(' ', $indent) . str_repeat('> ', 1 + $indent % 3) . '%%%';
            $queries[] = count($lines);
            $lines[] = $line;
            $lines[] = $line;
        }
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            foreach ($queries as $index) {
                $this->assertTrue($parser->closes($lines, $index));
                $this->assertFalse($parser->closes($lines, $index + 1));
            }
        } finally {
            LayoutWork::$on = false;
        }
        $work = LayoutWork::total();
        $this->assertGreaterThan(0, $work);
        $this->assertLessThanOrEqual(10 * strlen(implode("\n", $lines)), $work);
    }
}
