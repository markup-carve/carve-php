<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-169, C2-B-184, C2-W-088: a backtick in a table cell takes a backslash only where the read-back pairs it, across
 * a pipe or across the fragments of a continued cell, one per run. Each input is the form carve-js formats; the
 * second value is its write of the parsed tree, which joins a continued cell, where that differs.
 */
final class TableCellTickReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function fixedPoints(): array
    {
        return [
            'run in a continued cell stays bare' => ["| ``a |\n+ b |\n", "| ``a b |\n"],
            'one backslash on a run that pairs across a pipe' => ["| \\``b | ``b |\n", null],
            'tick before a code span on a continuation row stays bare' => ["| a ` |\n+ b `c` |\n", "| a \\` b `c` |\n"],
            'last lone tick of the row stays bare' => ["| \\` | \\` | ` |\n", null],
            'tick that pairs with a later cell' => ["| \\`a | b ` |\n", null],
            'tick glued behind a code span' => ["| `x`\\` | y |\n", null],
            'cell pipe is escaped in the bare candidate' => ["| `a|b`\\|!``` |\n", null],
            'backtick kind of a cell behind a glued tick' => ["| \$\$`x`\\`a\\`b |\n", null],
            'literal backslash before a tick inside the span' => ["| ab* | \\`\\\\\\`*{ |\n", null],
            'no ask behind a code span that did not form' => ["| *\\``` ` ``{ | a`a|b` |\n", null],
            'doubled backslash before a space is no seed escape' => [
                "| !`x`{=html}a``` | \\\\  `ab | \\\\\\`\\` |\n| ^`] | \\`ab`x`{=html}``` | [/ |\n",
                null,
            ],
            'sigil before a tick takes no backslash' => ["| `a`[b!\\`\\` | b\$`x`\$`x``a|b` |\n| !{\$\$`x` | |\n", null],
            'every row pairs a run across a pipe' => ["| \\``b0 | ``b |\n| \\``b1 | ``b |\n", null],
            'no backslash asked on a doubled literal backslash' => ["| `a`} \\`\\`\\` | \\\\`` ` ``\$`x` | ```} |\n", null],
            'kind of a reported cell tick reaches its continuation' => [
                "| {\\\\ |\n| ] |\n+ |\n| ^b\\`\$\$`x` |\n+ b \\`]\\\\ |\n",
                "| {\\\\ |\n| ] |\n| ^b\\`\$\$`x` b \\`]\\\\ |\n",
            ],
        ];
    }

    #[DataProvider('fixedPoints')]
    public function testWritesBackUnchanged(string $input, ?string $written): void
    {
        $result = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $result->source);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($written ?? $input, Carve::write(Carve::parse($input)));
    }
}
