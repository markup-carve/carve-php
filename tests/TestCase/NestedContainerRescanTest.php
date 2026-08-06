<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * COUNTED guard on the container-layout work (markup-carve/carve#752).
 *
 * Parsing a nested container hands its body to a nested parse, so a line at
 * depth `d` is handled `d` times. That is the container model and it is not
 * what this guards. What it guards is the CHARACTER work at each of those
 * handlings: every level re-measured the whole indentation run of every body
 * line, so an O(bytes) document cost O(bytes^1.5) of work.
 *
 * COUNTED, not timed, deliberately. Every other scaling guard in this suite has
 * to measure wall-clock cost per byte and carry a generous threshold, and
 * ScalingGuardTrait records at length why: observed CI failures "of 3.32x and
 * 4.39x were ordinary CPU contention, not regressions". carve-js reaches the
 * same conclusion in its own perf tests - a ratio bound tight enough to catch a
 * partial regression "would also fail on the healthy build", and it "flaked on
 * nearly every run". A call count is a property of the algorithm rather than of
 * the machine: every figure below reproduces exactly, run to run and under any
 * load.
 *
 * WHAT THIS DOES NOT COVER, said plainly so the guard is not read as more than
 * it is. It counts the indentation gate and the column strip. The gate is now
 * bounded and its share is linear in the document; the STRIP still copies the
 * rest of each line at each level, because PHP has no string view and removing
 * that copy needs the per-line offset model markup-carve/carve#752 describes.
 * So the gate is what carries the bounds here, and the strip is counted for
 * liveness and as the yardstick the gate must stay under.
 */
#[Group('scaling')]
class NestedContainerRescanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * A ladder of $d items, each indented two columns further than the last.
     *
     * @param int $d
     *
     * @return string
     */
    private static function ladder(int $d): string
    {
        $out = [];
        for ($i = 0; $i < $d; $i++) {
            $out[] = str_repeat(' ', 2 * $i) . '- x';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Same line count and same per-line widths as the ladder, ONE level deep.
     *
     * Every line still reaches the dedent, because each item carries a
     * continuation line - so this is a control on the DEPTH rather than on
     * whether the helpers run at all. A parser made uniformly slower moves this
     * with the ladder and so cannot satisfy the ratio below.
     *
     * @param int $d
     *
     * @return string
     */
    private static function shallow(int $d): string
    {
        $out = [];
        for ($i = 0; $i < $d; $i++) {
            $out[] = ($i % 2 === 0 ? '- ' : '  ') . str_repeat('x', 2 * $i + 1);
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Counted layout work over one parse.
     *
     * @param string $src
     *
     * @return array{gate: int, strip: int, total: int, bytes: int}
     */
    private function countWork(string $src): array
    {
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            $this->converter->parse($src);
        } finally {
            LayoutWork::$on = false;
        }

        return [
            'gate' => LayoutWork::$gate,
            'strip' => LayoutWork::$strip,
            'total' => LayoutWork::total(),
            'bytes' => strlen($src),
        ];
    }

    /**
     * LIVENESS. Every other assertion here is an upper bound, so a counter that
     * stopped counting would satisfy all of them. This is the floor that makes
     * a dead counter fail instead.
     *
     * @return void
     */
    public function testTheCounterCounts(): void
    {
        $shallow = $this->countWork(self::shallow(200));
        // One pass of strip over a document whose every second line is dedented
        // once. Not a bound - a statement that the counter is on the path the
        // parse actually takes.
        $this->assertGreaterThanOrEqual($shallow['bytes'], $shallow['total']);
        $ladder = $this->countWork(self::ladder(200));
        $this->assertGreaterThan(0, $ladder['gate']);
        $this->assertGreaterThan(0, $ladder['strip']);
    }

    /**
     * THE GATE, which is what this fixes. Bounding the walk at the column the
     * caller compares against makes it linear in the document: 100,097
     * characters on a depth-200 ladder against 2,707,196 before, and 4.00x per
     * depth doubling where the document's own bytes grow 3.94x.
     *
     * @return void
     */
    public function testTheIndentGateIsLinearInTheDocument(): void
    {
        $ladder = $this->countWork(self::ladder(200));
        $this->assertLessThanOrEqual(4 * $ladder['bytes'], $ladder['gate']);

        $small = $this->countWork(self::ladder(100));
        // A floor before the ratio, so a counter that stopped counting fails
        // here as an assertion rather than as a division by zero.
        $this->assertGreaterThan(0, $small['gate']);
        // Doubling the depth quadruples the bytes (3.94x), so the gate must not
        // do worse. This is the statement about the SHAPE of the curve, and it
        // fires even with the absolute bound above raised past usefulness: it
        // was 7.88 before the bound, against 4.00 now.
        $this->assertLessThanOrEqual(4.4, $ladder['gate'] / $small['gate']);
    }

    /**
     * THE CONTROL. A ladder against a size-matched document one level deep, so
     * a parser that got uniformly slower moves both and cannot pass. The gate
     * is what this compares: the strip's per-line copy is still charged the
     * whole line at every level and is not what this change removed.
     *
     * @return void
     */
    public function testADeepLadderCostsNoMoreGateThanAShallowOne(): void
    {
        $ladder = $this->countWork(self::ladder(200));
        $shallow = $this->countWork(self::shallow(200));
        $this->assertSame($ladder['bytes'], $shallow['bytes']);
        $this->assertLessThanOrEqual(4 * $shallow['total'], $ladder['gate']);
    }

    /**
     * The gate never outwalks the strip it gates.
     *
     * Stated honestly: this one PASSES on the unfixed parser too - 2,707,196
     * against 2,767,692, which is how close the unbounded gate came to the
     * copy it was gating. It is here because it pins a property this fix's own
     * shape could break, not because it discriminates the defect. Bounding the
     * gate at a caller's column is only correct while that column is one the
     * strip is about to consume; a cap taken from somewhere else could let the
     * gate walk past the strip again, and this fires when it does.
     *
     * @return void
     */
    public function testTheGateIsBoundedByTheStrip(): void
    {
        $ladder = $this->countWork(self::ladder(200));
        $this->assertLessThanOrEqual($ladder['strip'], $ladder['gate']);
    }

    /**
     * The ladder above is made of bullets, and a guard that only ever sees one
     * line shape cannot see a residual that a different shape still pays -
     * exactly the residual markup-carve/carve-rs#742 found in its own first
     * attempt. These are the other shapes that drive the same collector.
     *
     * @param string $kind
     *
     * @return void
     */
    #[DataProvider('shapeProvider')]
    public function testTheGateGrowsNoFasterThanTheDocument(string $kind): void
    {
        $small = $this->countWork(self::shape($kind, 100));
        $large = $this->countWork(self::shape($kind, 200));
        $this->assertGreaterThan(0, $small['gate']);
        $this->assertLessThanOrEqual(4.4, $large['gate'] / $small['gate']);
    }

    /**
     * @return array<array{string}>
     */
    public static function shapeProvider(): array
    {
        return [
            ['ordered'],
            ['task'],
            ['prose-with-colon'],
            ['colon-run'],
            ['tab'],
            ['quote-in-list'],
            ['lazy-tail'],
        ];
    }

    /**
     * @param string $kind
     * @param int $d
     *
     * @return string
     */
    private static function shape(string $kind, int $d): string
    {
        $out = [];
        for ($i = 0; $i < $d; $i++) {
            $out[] = match ($kind) {
                'ordered' => str_repeat(' ', 3 * $i) . '1. x',
                'task' => str_repeat(' ', 2 * $i) . '- [ ] x',
                'prose-with-colon' => str_repeat(' ', 2 * $i) . '- Note: a b c',
                'colon-run' => str_repeat(' ', 2 * $i) . '- ::: not an opener x',
                'tab' => str_repeat("\t", $i) . '- x',
                'quote-in-list' => str_repeat(' ', 2 * $i) . ($i % 2 === 1 ? '> x' : '- x'),
                'lazy-tail' => str_repeat(' ', 2 * $i) . '- x',
            };
        }
        if ($kind === 'lazy-tail') {
            for ($i = 0; $i < $d; $i++) {
                $out[] = str_repeat(' ', 2 * $d) . 'lazy tail';
            }
        }

        return implode("\n", $out) . "\n";
    }
}
