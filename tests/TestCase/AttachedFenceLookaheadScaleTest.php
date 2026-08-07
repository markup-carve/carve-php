<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A `+`-attached fence that can NEVER close must not cost the document twice.
 *
 * The container collectors look AHEAD for the closer of the block a `+`
 * attaches (markup-carve/carve-php#1049). An opener with no closer ahead reads
 * the whole remaining document, so a document full of such openers re-reads the
 * same suffix once per opener - quadratic. `fenceCloserIndex()` is what refutes
 * that in one pass: a width -> last-index map built once, then asked instead of
 * scanned.
 *
 * THE OPENERS MUST NOT BE CLOSER-SHAPED, which is the trap this file exists to
 * avoid. A repeated BARE opener closes on its own successor - a comment and a
 * colon closer match on exact length, a code closer at length or longer - so
 * every bare-fragment spelling of this shape measures linear no matter how bad
 * the lookahead is, and pins nothing. A TYPED opener (`::: note`, a fence with
 * an info string) is not closer-shaped, so a run of them never closes and the
 * width can stay constant, which is what makes a repeated fragment express the
 * shape at all.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other
 * guards; ScalingGuardTrait records the calibration and why it is per input
 * byte rather than per total.
 */
#[Group('scaling')]
class AttachedFenceLookaheadScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * A block-level shape is five lines per repeat, so the inline default of
     * 12500/50000 would build a quarter-million-line document to say something
     * 1250 lines already say. 250/1000 keeps the same 4x multiple.
     *
     * @var int
     */
    private const REPEATS = 250;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unclosableShapes(): array
    {
        return [
            // A TYPED colon opener: `:::` alone would close the one above it.
            'typed colon fence' => ["- x\n+\n::: note\na\n\n"],
            // A code opener with an info string is likewise not closer-shaped.
            'code fence with an info string' => ["- x\n+\n```js\na\n\n"],
            // The block quote reaches the same lookahead through its own
            // collector, so a refutation that only reached the list path would
            // leave this one quadratic.
            'quote-attached typed colon fence' => ["> q\n+\n::: note\na\n\n"],
        ];
    }

    #[DataProvider('unclosableShapes')]
    public function testAnUnclosableAttachedFenceScalesLinearly(string $unit): void
    {
        $this->assertScanScalesLinearly($this->converter, $unit, '', 'unclosable ' . trim($unit), self::REPEATS);
    }
}
