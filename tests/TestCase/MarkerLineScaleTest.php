<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A line of N list markers must not cost N per marker.
 *
 * The trailing-block tracker walks the markers on a line to find the innermost
 * content. Asked marker by marker, every pattern it matched CAPTURED the rest
 * of the line - and the tail was read to end-of-line whether or not anything
 * was copied - so the walk cost markers TIMES line length, once per nesting
 * level. 8 KB of markers took about three seconds and the ratio per doubling
 * was still climbing, which PART 9 section 25 makes a defect rather than a slow
 * path: it is normative about refusing rather than degrading (carve-php#1426).
 *
 * ONE LINE, so the input grows by the marker count rather than by the line
 * count - which is the axis the defect was on. `ListParser::markerHeads()` is
 * what makes the fix safe to state: the offset walk and the parser are rendered
 * from one spelling of the grammar, and
 * `MarkerContentOffsetAgreesWithTheParserTest` is the proof they agree.
 *
 * AN 8x MULTIPLE, NOT THE TRAIT'S 4x. Measured at 4x the defect reads 1.83
 * against a 2.0 threshold - a guard that passes on the bug is the defect class
 * this org keeps finding, so the multiple was raised until it discriminates. At
 * 8x the defect reads 3.09 and the fix reads 1.37-1.40, which leaves margin on
 * both sides.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other guards.
 */
#[Group('scaling')]
class MarkerLineScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const MARKERS = 1000;

    /**
     * @var int
     */
    private const MULTIPLE = 8;

    public function testALineOfListMarkersScalesLinearly(): void
    {
        $converter = new CarveConverter();

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->parse($input);
            },
            str_repeat('- ', self::MARKERS) . "x\n",
            str_repeat('- ', self::MARKERS * self::MULTIPLE) . "x\n",
            'a line of list markers',
            self::MARKERS,
            self::MARKERS * self::MULTIPLE,
        );
    }
}
