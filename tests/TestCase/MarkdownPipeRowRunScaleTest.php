<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A run of closed pipe rows must not cost the run once per row.
 *
 * `escapeRowContinuation` asks whether a GFM table is already under way at each
 * row, and the answer lives in the run of rows ABOVE rather than in the one line
 * above (markup-carve/carve-php#2365). Scanning that run per row is quadratic (carve-php#2390),
 * and a document of lone rows is the shape that shows it: 500 rows cost 4.8s,
 * 1000 cost 20s and 2000 cost 96s before `gfmTableIsUnderWay` was memoized and
 * built each answer from the one below it.
 *
 * THE ROWS MUST ANSWER NO DELIMITER, which is the trap here. A run under a real
 * header takes the table-body branch and `continue`s before the escape is
 * reached, so a run WITH a delimiter row measures linear however bad the scan is
 * and pins nothing. The quoted spelling is the one that reaches it in both
 * cases, so it is measured with a delimiter row as well.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other guards;
 * ScalingGuardTrait records the calibration.
 */
#[Group('scaling')]
class MarkdownPipeRowRunScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * One line per repeat, so the inline default would build a 50000-line
     * document to say what 5000 lines already say.
     *
     * @var int
     */
    private const REPEATS = 1250;

    /**
     * @return array<string, array{0: string}>
     */
    public static function rowRuns(): array
    {
        return [
            // Lone rows: no delimiter anywhere, so every one reaches the escape.
            'closed rows at column 0' => ["| a | b |\n"],
            'one-cell rows' => ["|x|\n"],
            'quoted closed rows' => ["> | a | b |\n"],
            // A quoted table's body rows reach the escape too, and each of them
            // walks back past a longer run than the row before it.
            'quoted body rows under a delimiter' => ["> | c | d |\n"],
        ];
    }

    #[DataProvider('rowRuns')]
    public function testARunOfClosedRowsScalesLinearly(string $unit): void
    {
        $converter = new MarkdownToCarve();
        $prefix = str_starts_with($unit, '>') ? "> | a | b |\n> | - | - |\n" : '';
        $small = $prefix . str_repeat($unit, self::REPEATS);
        $large = $prefix . str_repeat($unit, self::REPEATS * 4);

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            $small,
            $large,
            'row run ' . trim($unit),
            self::REPEATS,
            self::REPEATS * 4,
        );
    }

    /**
     * The memo belongs to the document being converted, so a second conversion
     * on the same instance reads its own lines rather than the first one's.
     */
    public function testTheMemoDoesNotSurviveTheNextConversion(): void
    {
        $converter = new MarkdownToCarve();
        $converter->convert("| a | b |\n| - | - |\n| c | d |\n");

        $this->assertSame("\\| c | d |\n", $converter->convert("| c | d |\n"));
        $this->assertSame(
            '<p>| c | d |</p>',
            trim((string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert("\\| c | d |\n"))),
        );
    }
}
