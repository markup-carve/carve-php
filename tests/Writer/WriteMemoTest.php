<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

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
}
