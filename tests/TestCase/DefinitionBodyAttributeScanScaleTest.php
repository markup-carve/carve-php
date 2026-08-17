<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A definition body must not re-read itself once per line it collects.
 *
 * The body's lazy-continuation gate asks whether the body's last construct is a
 * pending ATTRIBUTE BLOCK, which the per-line trailing tracker cannot answer for
 * the WRAPPED form: `{.k` is a block-attribute line only once a later line
 * closes it, so the question is about the line SET. Asked by rescanning the
 * accumulated body per collected line, it is quadratic - a description of N
 * flush-left continuation lines does N/2 line reads per line. Raised by codex
 * review on markup-carve/carve-php#1358 with 1,000/2,000/4,000 lines measured at
 * roughly 0.5s/1.8s/7.2s.
 *
 * The answer is carried on the SAME cursor the trailing tracker already walks,
 * so each collected line is classified once.
 *
 * THE FRAGMENT IS A PLAIN LAZY LINE, not an attribute. The rescan cost is paid
 * per collected line whatever the body holds, and a body full of attribute
 * blocks would also grow the number of blocks - two things changing at once.
 * Plain lazy text keeps one body and one description, so the only thing growing
 * is the line count the gate re-reads.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other guards;
 * ScalingGuardTrait records the calibration and why it is per input byte.
 */
#[Group('scaling')]
class DefinitionBodyAttributeScanScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * One line per repeat, so the inline default would build a 50,000-line
     * description. 1000/4000 keeps the same 4x multiple at a tenth the size.
     *
     * @var int
     */
    private const REPEATS = 1000;

    public function testALazyDefinitionBodyScalesLinearly(): void
    {
        $converter = new CarveConverter();

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            ":: t\n:  d\n" . str_repeat("lazy\n", self::REPEATS),
            ":: t\n:  d\n" . str_repeat("lazy\n", self::REPEATS * 4),
            'lazy definition body',
            self::REPEATS,
            self::REPEATS * 4,
        );
    }
}
