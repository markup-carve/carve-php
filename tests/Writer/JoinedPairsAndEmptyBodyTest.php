<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class JoinedPairsAndEmptyBodyTest extends TestCase
{
    public function testJoinedCandidatesCannotOpenOrCloseLegacyPairs(): void
    {
        $rules = ['~' => 'deprecated-bare-strike', '_' => 'deprecated-bare-underline', '=' => 'deprecated-bare-highlight'];
        foreach ($rules as $mark => $code) {
            foreach (["\u{0301}", "\u{FE0F}", "\u{200D}"] as $join) {
                $opening = Carve::lint("x {$mark}{$join}a{$mark} y\n", ['upgrade' => true]);
                self::assertNotContains($code, array_map(static fn ($entry): string => $entry->code, $opening));
                $closing = Carve::lint("{$mark}a{$mark}{$join}b{$mark}\n", ['upgrade' => true]);
                self::assertContains($code, array_map(static fn ($entry): string => $entry->code, $closing));
            }
        }
    }

    public function testEmptyTableBodyKeepsItsBoundary(): void
    {
        $result = Carve::importDocument('<table><thead><tr><th>H</th></tr></thead><tbody></tbody></table>', ['from' => 'html']);
        self::assertSame("{body-rows=0}\n|= H |\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testEscapedMarkDoesNotConsumeALaterPair(): void
    {
        $onlyStrike = static fn ($entry): bool => $entry->code === 'deprecated-bare-strike';
        $entries = array_values(array_filter(Carve::lint('\\~foo ~bar~', ['upgrade' => true]), $onlyStrike));
        self::assertCount(1, $entries);
        self::assertSame(6, $entries[0]->startOffset);
        self::assertSame(11, $entries[0]->endOffset);
    }

    public function testLegacyPairCannotOpenInsideALongerRun(): void
    {
        $codes = array_map(static fn ($entry): string => $entry->code, Carve::lint('~~a~', ['upgrade' => true]));
        self::assertNotContains('deprecated-bare-strike', $codes);
    }
}
