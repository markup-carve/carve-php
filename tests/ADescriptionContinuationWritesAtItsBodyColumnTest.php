<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ADescriptionContinuationWritesAtItsBodyColumnTest extends TestCase
{
    public function testFoldedOpenersAreEscapedAtTheBodyColumn(): void
    {
        foreach (['> quote' => '\\> quote', '# H' => '\\# H', '| a |' => '\\| a |'] as $sourceLine => $written) {
            $formatted = CarveConverter::toCarve(":: term\n:  definition\n $sourceLine\n");
            self::assertSame(":: term\n: definition\n  $written\n", $formatted);
            self::assertSame($formatted, CarveConverter::toCarve($formatted));
        }
    }

    public function testClosedDescriptionHeadingLeavesTheNextItemLineAtItsContentColumn(): void
    {
        $source = "- intro\n\n  :: term\n  :  definition\n      # H\n  tail\n";
        $expected = "- intro\n  :: term\n  : definition\n\n    # H\n  tail\n";
        self::assertSame($expected, CarveConverter::toCarve($source));
        self::assertSame($expected, CarveConverter::toCarve($expected));
    }
}
