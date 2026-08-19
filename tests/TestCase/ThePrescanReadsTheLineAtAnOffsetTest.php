<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * COUNTED guard on the heading-reference prescan's container-prefix walk
 * (markup-carve/carve-php#1463).
 *
 * The walk peeled a line's quote markers and bullets by handing each step a
 * fresh COPY of the rest of the line, so a line of N prefix elements copied N
 * times the line: 4 GB for a 128 KB line of `> - ` repeated. PART 9 §25 is
 * normative about refusing rather than degrading, which makes that a defect
 * rather than a slow path, and it is the same lesson
 * markup-carve/carve-php#1407, markup-carve/carve-php#1426,
 * markup-carve/carve-php#1437 and markup-carve/carve-php#1442 each settled one
 * container over.
 *
 * COUNTED, NOT TIMED, and in the DEFAULT suite because of it. The wall-clock
 * guards live in the excluded `scaling` group and carry generous thresholds for
 * the reason `ScalingGuardTrait` records at length - CI contention reads as a
 * regression. This defect also shows exactly why a ratio guard is not enough on
 * its own: it sat under `QuotedMarkerLineScaleTest` for as long as a larger
 * linear constant hid it, and only surfaced when
 * markup-carve/carve-php#1458 removed that constant and made `main` red. A
 * character count is a property of the algorithm, reproduces under any load,
 * and cannot be hidden by a constant somewhere else.
 *
 * THE BOUND IS ABSOLUTE, not a ratio across doublings. The healthy walk copies
 * ONCE, at the end, so its total is a small multiple of the document however
 * long the prefix is; the copying spelling is quadratic and clears any linear
 * bound by orders of magnitude at these sizes. Measured on the fix: one byte,
 * at every size below.
 */
class ThePrescanReadsTheLineAtAnOffsetTest extends TestCase
{
    /**
     * A generous LINEAR ceiling. The walk copies once, so four times the
     * document is far above anything it can reach and far below what a copy per
     * element produces: at 8000 pairs the copying spelling copied about 8000
     * times the document.
     *
     * @var int
     */
    private const MULTIPLE = 4;

    /**
     * @return array<string, array{0: int}>
     */
    public static function prefixLengths(): array
    {
        return [
            '2000 pairs' => [2000],
            '4000 pairs' => [4000],
            '8000 pairs' => [8000],
        ];
    }

    #[DataProvider('prefixLengths')]
    public function testTheWalkCopiesAtMostTheLineItself(int $pairs): void
    {
        $source = str_repeat('> - ', $pairs) . "x\n";
        $parser = new class extends BlockParser {
            /** @param array<string> $lines */
            public function scan(array $lines): void
            {
                $this->extractHeadingReferences($lines);
            }
        };

        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            // Exercise the prescan directly. The default parse now skips it
            // when no warning-producing anchor can consume its index.
            $parser->scan(explode("\n", $source));
        } finally {
            LayoutWork::$on = false;
        }

        // LIVE, so the bound below cannot pass by counting nothing. The walk
        // reaches the content on this line, so it takes its one substring.
        $this->assertGreaterThan(0, LayoutWork::$prescan, 'the prescan counter is not counting');
        $this->assertLessThanOrEqual(
            self::MULTIPLE * strlen($source),
            LayoutWork::$prescan,
            sprintf(
                'the prescan copied %d bytes for a %d-byte document (%.1f x); a copy per prefix '
                    . 'element is quadratic in the line',
                LayoutWork::$prescan,
                strlen($source),
                LayoutWork::$prescan / strlen($source),
            ),
        );
    }
}
