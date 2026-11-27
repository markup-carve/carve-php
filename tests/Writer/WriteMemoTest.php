<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Text;
use MarkupCarve\Carve\Writer\WriteMemo;
use PHPUnit\Framework\TestCase;

/**
 * carve#6852: the memo of the traced writes keys every input and gives back the write state a hit skips.
 */
final class WriteMemoTest extends TestCase
{
    public function testAHitGivesTheResultAndTheTextStateOfTheWrite(): void
    {
        $writes = 0;
        $write = static function () use (&$writes): array {
            $writes++;
            Text::$undecided = true;

            return ['written', true];
        };
        $outer = Text::writeState();
        try {
            [$first, $second] = WriteMemo::scoped(static function () use ($write): array {
                $first = WriteMemo::remember('bare', ['tree', 'escapes'], $write);
                Text::$undecided = false;

                return [$first, WriteMemo::remember('bare', ['tree', 'escapes'], $write)];
            });
            $this->assertSame(['written', true], $first);
            $this->assertSame(['written', true], $second);
            $this->assertSame(1, $writes);
            $this->assertTrue(Text::$undecided);
        } finally {
            Text::restoreWriteState($outer);
        }
    }

    public function testOtherInputsOrAnotherTextStateWriteAgain(): void
    {
        $writes = 0;
        $write = static function () use (&$writes): array {
            $writes++;

            return [$writes, false];
        };
        $outer = Text::writeState();
        try {
            WriteMemo::scoped(static function () use ($write): void {
                WriteMemo::remember('bare', ['tree', 'a'], $write);
                WriteMemo::remember('bare', ['tree', 'b'], $write);
                Text::$conservative = true;
                WriteMemo::remember('bare', ['tree', 'a'], $write);
            });
            $this->assertSame(3, $writes);
        } finally {
            Text::restoreWriteState($outer);
        }
    }

    public function testInputsWithClosuresAreKeyedByTheirObjects(): void
    {
        $writes = 0;
        $write = static function () use (&$writes): array {
            $writes++;

            return ['x', false];
        };
        $generator = static fn (string $page): string => '/w/' . $page;
        $other = static fn (string $page): string => '/v/' . $page;
        WriteMemo::scoped(static function () use ($write, $generator, $other): void {
            WriteMemo::remember('bare', [['extensions' => [$generator]]], $write);
            WriteMemo::remember('bare', [['extensions' => [$generator]]], $write);
            WriteMemo::remember('bare', [['extensions' => [$other]]], $write);
        });
        $this->assertSame(2, $writes);
    }

    public function testOutsideAScopeNothingIsKept(): void
    {
        $writes = 0;
        $write = static function () use (&$writes): array {
            $writes++;

            return ['x', false];
        };
        WriteMemo::remember('bare', ['tree'], $write);
        WriteMemo::remember('bare', ['tree'], $write);
        $this->assertSame(2, $writes);
    }

    public function testAProbeHitGivesItsResultAndChargeWithinTheBudget(): void
    {
        $runs = 0;
        $run = static function () use (&$runs): array {
            $runs++;
            Text::$undecided = true;

            return [['escapes'], 40];
        };
        $outer = Text::writeState();
        try {
            $results = WriteMemo::scoped(static function () use ($run): array {
                $first = WriteMemo::probe(['inlines'], 100, $run);
                Text::$undecided = false;
                $second = WriteMemo::probe(['inlines'], 40, $run);

                return [$first, $second];
            });
            $this->assertSame([[['escapes'], 40], [['escapes'], 40]], $results);
            $this->assertSame(1, $runs);
            $this->assertTrue(Text::$undecided);
        } finally {
            Text::restoreWriteState($outer);
        }
    }

    public function testAProbePastTheBudgetLeftRunsAgainAndIsNotKept(): void
    {
        $runs = 0;
        $run = static function () use (&$runs): array {
            $runs++;

            return [[], 40];
        };
        WriteMemo::scoped(static function () use ($run): void {
            WriteMemo::probe(['inlines'], 100, $run);
            WriteMemo::probe(['inlines'], 39, $run);
            WriteMemo::probe(['other'], 39, $run);
            WriteMemo::probe(['other'], 100, $run);
        });
        $this->assertSame(4, $runs);
    }

    /**
     * carve-php#6081: a leaf takes the same content probe in more than one round, and a repeated paragraph takes the
     * probes of the first one.
     */
    public function testRepeatedProbesGiveTheFormOfTheRounds(): void
    {
        $p = '^[/\`[`[/[^]]][<] and [cfa \[b0][k] text \/x0 [c][]';
        $q = '^[/\`[`[/[^]]][<] and [dge \[h0][k] text \/y0 [c][]';
        $defs = "[<]: /u\n\n[k]: /k\n\n[c]: /c\n";
        $p2 = '^\[\/\`[\`\[\/\[^\]\]][<] and [cfa \[b0][k] text \/x0 [c][]';
        $q2 = '^\[\/\`[\`\[\/\[^\]\]][<] and [dge \[h0][k] text \/y0 [c][]';
        $this->assertSame(
            $p2 . "\n\n" . $q2 . "\n\n" . $p2 . "\n\n" . $defs,
            Carve::format($p . "\n\n" . $q . "\n\n" . $p . "\n\n" . $defs),
        );
    }
}
