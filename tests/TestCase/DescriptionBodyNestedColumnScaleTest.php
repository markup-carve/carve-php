<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A description body full of past-the-column definitions must not cost the body
 * once per definition.
 *
 * Answering a definition against the innermost container the line REACHES needs
 * the shallowest column open inside the body (markup-carve/carve-php#1872), and
 * that column is a fold over the entries collected so far. Recomputing the fold
 * per definition is quadratic: measured on this shape it took 174 seconds at
 * 8000 lines against 0.5 before the change, and 47 against 0.3 at 4000. The
 * fold is carried forward instead, which is what this guard pins.
 *
 * The LIST IS THE POINT. Without a container open inside the body there is no
 * nested column to look for and the fold is never asked for, so a bare body
 * measures linear however the column is computed and pins nothing.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other
 * guards; ScalingGuardTrait records the calibration and why it is per input
 * byte rather than per total.
 */
#[Group('scaling')]
class DescriptionBodyNestedColumnScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * One line per repeat, but each one runs the fold, so the quadratic term
     * separates well below the inline default. 1000/4000 is a healthy fraction
     * of a second here and 47 seconds when the fold restarts.
     *
     * @var int
     */
    private const SMALL_REPEATS = 1000;

    /**
     * @var int
     */
    private const LARGE_REPEATS = 4000;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADefinitionRunInANestedListBodyScalesLinearly(): void
    {
        $prefix = ":: t\n:  - a\n";
        $unit = "    [r]: /url\n";

        $this->assertConversionScalesLinearly(
            function (string $input): void {
                $this->converter->convert($input);
            },
            $prefix . str_repeat($unit, self::SMALL_REPEATS) . 'tail',
            $prefix . str_repeat($unit, self::LARGE_REPEATS) . 'tail',
            'past-the-column definitions in a nested-list description body',
            self::SMALL_REPEATS,
            self::LARGE_REPEATS,
        );
    }
}
