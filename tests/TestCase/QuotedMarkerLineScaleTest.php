<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A line alternating a quote marker with a bullet must not cost the line per level.
 *
 * `MarkerLineScaleTest` beside this one covers the LIST half. This is the other
 * one: `> - ` repeated never reaches the marker walk at all, because position
 * zero of the line holds a quote marker. The trailing-block tracker peeled the
 * prefix by handing each step a fresh COPY of the rest of the line, so a line
 * of N prefix elements cost N times the line, and 8 KB took about 2.8 s with
 * the ratio per doubling still climbing (carve-php#1437). PART 9 section 25 is
 * normative about refusing rather than degrading, which makes that a defect and
 * not a slow path.
 *
 * ONE LINE, so the input grows by the prefix count rather than by the line
 * count - the axis the defect is on.
 *
 * WHY THE SAMPLES ARE THIS BIG, and it is measured rather than cautious. The
 * healthy cost of this shape carries a large LINEAR constant: the tracker is
 * re-entered once per nesting level, and the nesting cap is about 400, so the
 * per-byte cost is still rising as the cap saturates until roughly 2000 prefix
 * elements. Below that the two sides are not separable - at 1000 against 8000
 * the DEFECT measures 1.56 and at 1500 against 12000 it measures 1.95, both
 * under the 2.0 threshold, so a guard placed there would pass on the bug. That
 * is the dead-check shape markup-carve/carve#755 catalogs, so the small sample
 * was moved past the cap instead of the threshold being lowered.
 *
 * Measured on the pair below: the defect reads 3.51 and the fix reads 1.19,
 * which leaves about 1.7x of margin on each side of the threshold. The largest
 * healthy sample is about 17 s where the defect's is about 62 s, so the
 * catastrophic backstop is raised to 45 s for this shape - between the two, and
 * therefore a second, independent kill rather than a weakened one.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other
 * guards. The offset walk's CORRECTNESS is pinned load-independently by
 * `OffsetHeadsAgreeWithTheirParsersTest`; this asserts only its cost.
 */
#[Group('scaling')]
class QuotedMarkerLineScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const PAIRS = 2000;

    /**
     * @var int
     */
    private const MULTIPLE = 8;

    /**
     * @var float
     */
    private const MAX_SECONDS = 45.0;

    public function testALineAlternatingAQuoteMarkerWithABulletScalesLinearly(): void
    {
        $converter = new CarveConverter();

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->parse($input);
            },
            str_repeat('> - ', self::PAIRS) . "x\n",
            str_repeat('> - ', self::PAIRS * self::MULTIPLE) . "x\n",
            'a line alternating a quote marker with a bullet',
            self::PAIRS,
            self::PAIRS * self::MULTIPLE,
            self::MAX_SECONDS,
        );
    }

    public function testAnAlternatingPrefixPastTheNativeStackLimitDoesNotCrash(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ';'
            . '(new MarkupCarve\\Carve\\Parser\\BlockParser())->parse(str_repeat("> - ", 18000) . "x\\n");';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $error ?: 'the parser process crashed');
    }
}
