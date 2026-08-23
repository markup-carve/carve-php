<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 2b's narrowing is a SEARCH, and a search is where the writer
 * can go quadratic in the document.
 *
 * THE WRITER HAD NO SCALING ROW AT ALL. Every guard in this group measures a
 * parser scan or the Markdown target; not one of them runs the canonical Carve
 * writer, whose escape decision renders and re-parses the WHOLE document once
 * per step of a search. carve-js#1312 found the unit search quadratic in
 * failing units on ordinary input, and this engine carries the same search with
 * nothing watching it.
 *
 * TWO SHAPES, AND THEY FIND TWO DIFFERENT LEAVES.
 *
 * `narrowEscalation()` offers a group of UNITS its minimal form all at once and
 * halves the group when that fails, so its cost is proportional to how many
 * units FAIL. A file of indented `## H` paragraphs is every block failing, and
 * it is ordinary input rather than an adversarial one.
 *
 * `narrowOccurrences()` runs the same search one level finer, over the
 * candidate sites inside the units that stayed escalated
 * (markup-carve/carve#1533). The shape that finds ITS leaves is a document
 * where a load-bearing occurrence sits beside an idle one on every line, so no
 * group above a leaf can be relaxed whole: a paragraph of indented table rows
 * is exactly that - the leading `|` opens a row and the trailing one opens
 * nothing.
 *
 * MEASURED BOTH WAYS on the table-row shape at 50/100/200/400 rows. With the
 * budget: 65 / 73 / 81 / 89 renders. Without it: 200 / 400 / 800 / 1600, a
 * render of the whole document per occurrence. The budgets in
 * `narrowEscalation()` and `narrowOccurrences()` are what make this the linear
 * shape the guards assert.
 *
 * AND MEASURED AS THESE GUARDS READ IT, with the occurrence budget removed:
 * 3.52x per byte for the `## H` blocks and 3.29x for the table rows, against
 * the 2.0 threshold. Both are green with it. Measured on top of carve-php#1577,
 * which narrowed the unit search to the units the writer actually asks about -
 * the occurrence search inherits that smaller candidate set, and the two
 * compose.
 *
 * A RATIO AND NEVER A WALL-CLOCK THRESHOLD, which is the trait's whole
 * calibration: a threshold in milliseconds describes the machine that chose it.
 *
 * THE OUTPUT IS WIDER WHERE A BUDGET BINDS, NEVER NARROWER. A document that
 * spends one keeps escapes section 2 would have retired, and every state either
 * search returns has been verified against the conservative form's own
 * re-parse. On the corpus neither budget binds.
 */
#[Group('scaling')]
class WriterEscapeNarrowingScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Blocks in the smaller sample; the larger one is the trait's usual 4x.
     *
     * Far below the inline default, because a UNIT here is a whole block and
     * every step of the search renders and re-parses the entire document. At
     * 400 blocks a healthy run is already most of a second.
     *
     * @var int
     */
    private const SMALL_BLOCKS = 100;

    public function testADocumentWhereEveryBlockEscalatesScalesLinearly(): void
    {
        $this->assertWriterScalesLinearly("  ## H\n\n", 'blocks that all escalate');
    }

    public function testADocumentWhereEveryLineHoldsAFailingAndAnIdleCandidateScalesLinearly(): void
    {
        $this->assertWriterScalesLinearly(" | a |\n", 'lines that all escalate one occurrence');
    }

    /**
     * The canonical writer over a repeated fragment, timed by the shared
     * calibration.
     *
     * The timed closure is the WRITER and not `convert()`: parsing is linear
     * here and costs about as much, so timing both together halves the signal.
     *
     * @param string $fragment Repeated to build both samples.
     * @param string $label Identifies the shape in failure output.
     *
     * @return void
     */
    private function assertWriterScalesLinearly(string $fragment, string $label): void
    {
        $large = self::SMALL_BLOCKS * 4;

        $this->assertConversionScalesLinearly(
            static function (string $input): void {
                CarveConverter::toCarve($input);
            },
            str_repeat($fragment, self::SMALL_BLOCKS),
            str_repeat($fragment, $large),
            $label,
            self::SMALL_BLOCKS,
            $large,
            // THE RATIO IS WHAT DISCRIMINATES, and the trait says so where it
            // offers this override. A healthy run here is well under a second,
            // but the quadratic this guard exists for takes half a minute at
            // 400 blocks - and the default 20s backstop would abort the run
            // BEFORE the ratio is computed, so a regression would be reported
            // as "took 34s" rather than as the shape it is. Raising it is what
            // lets the guard name the thing it caught.
            maxSeconds: 60.0,
        );
    }
}
