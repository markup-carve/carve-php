<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BracketPairIndexTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function runs(): array
    {
        return [
            'balanced' => ['[a [b] c]'],
            'unbalanced outer' => ['[[[x]'],
            'escaped closer' => ['[a\\] [b]]'],
            'escaped opener' => ['[a\\[ b]'],
            'opaque code' => ['[a `] [` [b]]'],
            'longer code closer' => ['[a ``] [` ` `` [b]]'],
            'unclosed code' => ['[a [b] ` [c]]'],
            'editorial comment' => ['[a {# ] [ #} [b]]'],
            'author comment' => ['[a {% ] [ %} [b]]'],
            'unclosed comment' => ['[a {# [b]]'],
            'newlines and unicode' => ["[é\n[ß] end]"],
            'escaped terminal byte' => ['[a [b]\\'],
        ];
    }

    #[DataProvider('runs')]
    public function testIndexedPairsMatchIndependentScans(string $text): void
    {
        $ends = BracketScanner::balancedBracketEnds($text, 0);
        $this->assertArrayHasKey(0, $ends);
        foreach ($ends as $start => $end) {
            $this->assertSame(BracketScanner::balancedBracketEnd($text, $start), $end, 'opener ' . $start);
        }
    }

    public function testFailedOuterRetainsSuccessfulInnerPair(): void
    {
        $this->assertSame([2 => 4, 0 => null, 1 => null], BracketScanner::balancedBracketEnds('[[[x]', 0));
    }

    public function testNestingCapAppliesToEachOpener(): void
    {
        $depth = BracketScanner::MAX_BRACKET_NESTING + 1;
        $source = str_repeat('[', $depth) . 'x' . str_repeat(']', $depth);
        $ends = BracketScanner::balancedBracketEnds($source, 0);
        $this->assertNull($ends[0]);
        $this->assertSame(strlen($source) - 2, $ends[1]);
        $this->assertSame($depth + 1, $ends[$depth - 1]);
        $this->assertSame(BracketScanner::balancedBracketEnd($source, 1), $ends[1]);
    }
}
