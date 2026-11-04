<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line comment written at a tight item's content column sits at the marker
 * column of a sub-list standing above it, so a re-parse reads it into that
 * sub-list's last item and the next pass spells it deeper (carve-php#1948).
 *
 * The comparison has to be on the written SOURCE of two passes: a comment
 * renders nothing, so the HTML agrees on every pass and PART 11 section 2a's
 * necessary-not-sufficient property never sees this.
 */
final class ACommentBelowASubListClosesItTest extends TestCase
{
    public static function driftingShapes(): iterable
    {
        yield 'bullet sub-list' => ["- - d\n\n  %% q\n\na\n"];
        yield 'ordered sub-list' => ["- 1. d\n\n  %% q\n\na\n"];
        yield 'sub-list ending in a quote' => ["- - d\n\n    > e\n\n  %% q\n\na\n"];
        yield 'sub-list ending in a definition' => ["- - d\n\n    e\n    : f\n\n  %% q\n\na\n"];
        yield 'sub-list ending in an image' => ["- - d\n\n    ![e](u)\n\n  %% q\n\na\n"];
        yield 'sub-list with an emptied item' => ["- - d\n  -\n\n  %% q\n\na\n"];
        yield 'sub-list ending in a sub-list' => ["- - - d\n\n  %% q\n\na\n"];
        yield 'ordered host' => ["1. - d\n\n   %% q\n\na\n"];
        yield 'host in a quote' => ["> - - d\n>\n>   %% q\n\na\n"];
        yield 'host in a div' => [":::\n- - d\n\n  %% q\n:::\n\na\n"];
    }

    #[DataProvider('driftingShapes')]
    public function testTheWrittenSourceReachesAFixpointInOnePass(string $source): void
    {
        $first = CarveConverter::toCarve($source);
        self::assertSame($first, CarveConverter::toCarve($first));
    }

    #[DataProvider('driftingShapes')]
    public function testTheSeparatorDoesNotLoosenTheItem(string $source): void
    {
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert(CarveConverter::toCarve($source)));
    }

    /**
     * Controls: a comment that no list can re-own keeps its joined spelling.
     * They fail loudly if the separator is ever widened past a sub-list above.
     */
    public static function controls(): iterable
    {
        yield 'top level' => ["d\n\n%% q\n\na\n", "d\n\n%% q\n\na\n"];
        yield 'single-level item' => ["- d\n\n  %% q\n\na\n", "- d\n  %% q\n\na\n"];
        yield 'below a quote' => ["- > d\n\n  %% q\n\na\n", "- > d\n  %% q\n\na\n"];
        yield 'below a definition list' => ["- d\n  : e\n\n  %% q\n\na\n", "- d\n  : e\n  %% q\n\na\n"];
        yield 'below another comment' => ["- %% d\n\n  %% q\n\na\n", "- %% d\n  %% q\n\na\n"];
        yield 'comment fence below a sub-list' => [
            "- - d\n\n  %%%\n  q\n  %%%\n\na\n",
            "- - d\n  %%%\n  q\n  %%%\n\na\n",
        ];
    }

    #[DataProvider('controls')]
    public function testAControlGainsNoSeparator(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
    }

    public function testTheTicketsShapeNoLongerDeepensOnTheSecondPass(): void
    {
        $first = CarveConverter::toCarve("- - d\n\n  %% q\na\n");
        self::assertSame("- - d\n\n  %% q\n\na\n", $first);
        self::assertSame($first, CarveConverter::toCarve($first));
    }
}
