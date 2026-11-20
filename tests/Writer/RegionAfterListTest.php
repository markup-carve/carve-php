<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class RegionAfterListTest extends TestCase
{
    public function testRegionMarkerAfterListWritesAsSibling(): void
    {
        $input = "%% #region r\n- a\n%% #endregion r\n\nText.\n";
        $expected = "%% #region r\n\n- a\n\n%% #endregion r\n\nText.\n";

        self::assertSame($expected, Carve::format($input));
        self::assertSame($expected, Carve::write(Carve::parse($input)));
    }

    public function testPlainCommentStillFollowsTheContainer(): void
    {
        self::assertSame("- a\n  %% plain\n", Carve::format("- a\n%% plain\n"));
    }
}
