<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * THE MARKDOWN TARGET HAD NO SCALING ROW AT ALL (markup-carve/carve#1331).
 *
 * Every guard in this group measures a PARSER scan; not one of them renders
 * Markdown. So when PART 11 §8b gave the writer a per-candidate line scan, a
 * 33x regression shipped with nothing to catch it - and a target with no
 * scaling row is a target where the next one is also invisible.
 *
 * The rule asks where on the emitted line an authored hash stands, and the
 * answer was found by searching BACKWARD for the line's newline. In this engine
 * that search is spelled `strrpos(substr($line, 0, $offset), "\n")`, so it does
 * not merely read the line - it ALLOCATES AND COPIES the whole prefix, once per
 * candidate. A line of adjacent authored hashes is all candidates, and 400,000
 * of them copied 240GB.
 *
 * THE MEASUREMENT IS THE RENDER, NOT THE CONVERSION. Parsing is linear here and
 * costs about as much as the render, so timing both together halves the signal:
 * the same shape reads 1.67x through `convert()` and 2.55x through the renderer
 * alone. The documents are parsed once, outside the timing loop, and the timed
 * closure renders them - which is also the honest scope for a row that guards
 * the Markdown target.
 *
 * FIXED-WIDTH FRAGMENTS ON PURPOSE. The guard reads per-byte cost, so its
 * signal is only honest when the byte multiple and the unit multiple agree. A
 * builder whose bytes grow with the square of the unit count makes quadratic
 * work read as constant cost PER BYTE, which is how a guard elsewhere passed
 * the regression it existed for. Every fragment here is two or three bytes wide
 * whatever the repeat count.
 *
 * THE SAMPLES ARE LARGE BECAUSE THE SEPARATION DEPENDS ON THE BYTE COUNT AND
 * NOTHING ELSE. The per-candidate copy competes with a fixed per-candidate cost
 * - a PCRE callback and a few array reads - so the quadratic only outweighs it
 * once the line is long. Measured before the fix, per-byte cost at 4x the
 * input: 1.26x at 240KB, 2.10x at 800KB, 2.81x at 1.2MB. Density is what buys
 * that and not fragment length: a shape with the same bytes and one candidate
 * every twelve of them reads 1.12x, inside the threshold, because the work that
 * scales with the DOCUMENT then outweighs the work that scales with the line.
 */
#[Group('scaling')]
class MarkdownAuthoredEscapeScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Candidates in the smaller sample, and the larger one is the trait's usual
     * 4x. Well above the inline default, for the byte-count reason above.
     *
     * @var int
     */
    private const SMALL_REPEATS = 100000;

    /**
     * The shapes, and which of the two scans each one reaches.
     *
     * They are not redundant, and which one a change breaks says which scan it
     * broke:
     *
     * - ADJACENT hashes make the RUN long, so the run count is reached as well
     *   as the line search.
     * - SPACED hashes hold every run at one character, so a bound on the run
     *   cannot help them and only the line search is under test.
     *
     * @return array<string, array{string, int}>
     */
    public static function shapes(): array
    {
        return [
            'adjacent authored hashes' => ['\\#', self::SMALL_REPEATS],
            'spaced authored hashes' => ['\\# ', self::SMALL_REPEATS],
            // The other family. §8a M1b decides on the neighbouring delimiter
            // rather than on the line, and it was linear throughout; the row is
            // here so the target keeps one for both families rather than only
            // for the one that regressed.
            //
            // THE UNDERSCORE IS NOT INTERCHANGEABLE WITH AN ASTERISK HERE. Only
            // `_`, `#` and `[` take a sentinel, so a row built on `\*` would
            // never reach the resolve pass at all - it would measure the
            // renderer at large while reading as a guard on M1b. It needs no
            // large sample: nothing on this path scans the line.
            'adjacent authored underscores' => ['\\_', 12500],
        ];
    }

    #[DataProvider('shapes')]
    public function testAuthoredEscapesScaleLinearly(string $fragment, int $smallRepeats): void
    {
        $largeRepeats = $smallRepeats * 4;
        $small = str_repeat($fragment, $smallRepeats) . "\n";
        $large = str_repeat($fragment, $largeRepeats) . "\n";

        $converter = new CarveConverter();
        // KEYED BY LENGTH, not by the string itself: an array subscript on a
        // 1.2MB key hashes the whole key, which would put a linear term inside
        // the timed closure for no reason.
        $documents = [
            strlen($small) => $converter->parse($small),
            strlen($large) => $converter->parse($large),
        ];

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($documents): void {
                (new MarkdownRenderer())->render($documents[strlen($input)]);
            },
            $small,
            $large,
            $fragment,
            $smallRepeats,
            $largeRepeats,
        );
    }
}
