<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\RepairPlanner;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5628: text brackets in the content of links past the inline depth cap (C2-I-118).
 */
final class BracketPartnerRoundsTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function windows(): array
    {
        return ['n = 365' => [365], 'n = 503' => [503], 'n = 2,400' => [2_400]];
    }

    /**
     * C2-W-088, C2-W-125 case five: a kind round that escapes a text `[` in link content escapes the `]` that closes it
     * too, so the rounds settle in a bounded number of reads instead of giving up after reading the group again and
     * again (C2-W-010, C2-W-133).
     */
    #[DataProvider('windows')]
    public function testTheKindRoundsSettleInBoundedReads(int $count): void
    {
        $input = self::shape($count);
        $parsed = Carve::parseWithLayout($input);
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $planner = new RepairPlanner($tree, $parsed->layout);

        $source = $planner->repair();

        self::assertNotNull($source);
        self::assertLessThanOrEqual(8 * strlen($input), $planner->charactersRead());
        self::assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($source))));
        self::assertSame($source, Writer::format($source));
    }

    public function testAnEscapedTextBracketInALinkTakesItsPartner(): void
    {
        $source = Writer::format(self::shape(2_400));

        self::assertStringContainsString("\\[a {*[a](u)*}\\[x\\]: /u\n", $source);
    }

    private static function shape(int $count): string
    {
        $parts = ['[a ', '*[a](u)*', "[x]: /u\n"];
        $input = '';
        for ($at = 0; $at < $count; $at++) {
            $input .= $parts[$at % 3];
        }

        return $input;
    }
}
