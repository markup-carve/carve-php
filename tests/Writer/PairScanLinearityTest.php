<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz finds: the pair tests of the writer (C2-W-146) and of lint read the whole text before each mark, and a run of
 * marks was measured again for each of its marks, so fmt of one long text line was quadratic (16 KB of `{_` took 26 s).
 */
final class PairScanLinearityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'nested highlight' => ['{=', '=}'],
            'star run then pairs' => ['*', 'a*'],
            'underscore words' => ['a_', ''],
        ];
    }

    #[DataProvider('shapes')]
    public function testFormatIsLinear(string $unit, string $tail): void
    {
        $small = self::source($unit, $tail, 250);
        $large = self::source($unit, $tail, 2000);
        $excess = Linearity::excess(static fn () => Carve::format($small), static fn () => Carve::format($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    #[DataProvider('shapes')]
    public function testLintIsLinear(string $unit, string $tail): void
    {
        $small = self::source($unit, $tail, 250);
        $large = self::source($unit, $tail, 2000);
        $options = ['upgrade' => true];
        $excess = Linearity::excess(static fn () => Carve::lint($small, $options), static fn () => Carve::lint($large, $options), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    /**
     * Braced openers turn quadratic only past about 2 KB, so one size is timed: 8 KB took 14 s before the fix.
     */
    public function testFormatOfBracedOpenersStaysFast(): void
    {
        $source = self::source('{_', '', 4000);
        $start = hrtime(true);
        Carve::format($source);

        $this->assertLessThan(5000, (hrtime(true) - $start) / 1e6);
    }

    public function testFormatKeepsTheTextOfTheShapes(): void
    {
        foreach ([['{_', ''], ['{=', ''], ['{~', ''], ['{=', '=}'], ['*', 'a*'], ['a_', ''], ['=a', '= ']] as [$unit, $tail]) {
            $source = self::source($unit, $tail, 40);
            $this->assertSame(Carve::toHtml($source), Carve::toHtml(Carve::format($source)), $unit . $tail);
        }
    }

    private static function source(string $unit, string $tail, int $count): string
    {
        return str_repeat($unit, $count) . str_repeat($tail, $tail === '' ? 0 : $count) . "\n";
    }
}
