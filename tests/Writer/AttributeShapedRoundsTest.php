<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\ReadbackRound;
use MarkupCarve\Carve\Writer\RepairPlanner;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5774: paragraphs of attribute-shaped text behind inline nodes.
 */
final class AttributeShapedRoundsTest extends TestCase
{
    /**
     * @var string
     */
    private const UNIT = "Some prose with *strong* text. *b*\\{k=\"`\"}x`\n\n"
        . "Some prose with *strong* text. [a](u)\\{.c}`y`\n\n";

    public function testManyParagraphsKeepTheirSpelling(): void
    {
        $input = rtrim(str_repeat(self::UNIT, 200)) . "\n";

        self::assertSame($input, Carve::format($input));
    }

    public function testAPlannerStartedFromTheFirstRoundRepairsAsAFreshOne(): void
    {
        $parsed = Carve::parseWithLayout(str_repeat(self::UNIT, 40));
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $fresh = new RepairPlanner($tree, $parsed->layout);
        $started = new RepairPlanner($tree, $parsed->layout);
        $bare = Writer::bareCandidate($tree, $parsed->layout);
        $started->startFrom($bare, ReadbackRound::inspect($bare, $tree));

        $source = $fresh->repair();

        self::assertNotNull($source);
        self::assertSame($source, $started->repair());
        self::assertSame($fresh->charactersRead(), $started->charactersRead());
        self::assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($source))));
    }
}
