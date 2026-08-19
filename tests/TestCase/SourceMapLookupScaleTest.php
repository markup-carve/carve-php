<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TRACKING POSITIONS MUST NOT COST THE BLOCK TWICE.
 *
 * A source map carries one segment per source line - or, in a line block, one
 * per run of text between preserved gaps - and it is consulted once per inline
 * node. Two separate scans over that list turned a linear parse quadratic, and
 * only with `trackPositions` on, which is why the default suite never saw it:
 *
 * - Lookup scanned every segment. A block with N segments and N nodes did N*N
 *   comparisons.
 * - `shifted()`, which every NESTED inline parse takes - one per emphasis run,
 *   one per link text - rebuilt the whole segment list. N nested constructs
 *   copied N*N segments. A 2000-line paragraph moved four million of them.
 *
 * Both were pre-existing and both were reachable from a plain multi-line
 * paragraph; a line block joined them the moment its stanza became a single
 * parse rather than one parse per line (markup-carve/carve-php#1327).
 *
 * THE SHAPE NEEDS BOTH HALVES AT ONCE. A document with many segments but no
 * nested constructs measures the lookup only; one long line with many nested
 * constructs has a single segment and measures neither. Every fragment below is
 * a LINE - so the segment count grows - carrying a nested construct - so the
 * shift does too. That is the shape that was quadratic, and either fix alone
 * leaves it quadratic.
 *
 * Measured through `BlockParser` with tracking ON rather than through
 * `CarveConverter`, because the converter does not ask for positions and so
 * cannot express this at all.
 */
#[Group('scaling')]
class SourceMapLookupScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{0: string}>
     */
    public static function trackedShapeProvider(): array
    {
        return [
            // A paragraph: one segment per folded line, one nested parse per
            // emphasis run.
            'a folded paragraph with emphasis' => ["*a* b\n"],
            // A link's text is a nested parse too, and its map is shifted by a
            // different amount, so it exercises the accumulating shift rather
            // than a single one.
            'a folded paragraph with links' => ["[t](/u) b\n"],
            // A line block: the preserved gap splits the line into two mapped
            // runs, so this shape carries MORE segments per line than the
            // paragraph does, on top of the same nested parse.
            'a line block stanza with gaps' => ["*a*  b\n"],
        ];
    }

    #[DataProvider('trackedShapeProvider')]
    public function testTrackingPositionsScalesLinearly(string $fragment): void
    {
        $isStanza = str_contains($fragment, '  ');
        $small = 2000;
        $large = $small * 4;

        $build = static function (int $repeats) use ($fragment, $isStanza): string {
            $body = str_repeat($fragment, $repeats);

            return $isStanza ? "::: |\n" . $body . ":::\n" : $body;
        };

        $this->assertConversionScalesLinearly(
            static function (string $input): void {
                (new BlockParser(trackPositions: true))->parse($input);
            },
            $build($small),
            $build($large),
            $fragment,
            $small,
            $large,
        );
    }

    public function testEveryShapeIsStillCovered(): void
    {
        $this->assertCount(3, self::trackedShapeProvider());
    }
}
