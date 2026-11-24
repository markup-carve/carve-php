<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table cell shapes `fmt` and `write` spell as carve-js does, and that read back as their tree.
 */
final class TableCellParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function formatted(): array
    {
        return [
            // C2-L-019: an empty continuation row is recorded and kept.
            'empty continuation row' => ["| a |\n+  |\n", "| a |\n+ |\n"],
            'empty continuation row below a span' => ["| ^ x |\n| < |\n+  |\n", "| ^ x |\n| \\< |\n+ |\n"],
            // C2-L-020: the wrap of a cell that spans rows carries `row`, 0 on its own row.
            'wrap of a row-spanning cell' => ["| Qty |\n+ k |\n| ^ |\n", "| Qty |\n+ k |\n| ^ |\n"],
            // C2-L-006: two wraps on one line contradict the record; the cell is one line.
            'contradicting wraps' => ["| < | < |\n+ k | k |\n| d | x |\n", "| < k k | < |\n+ | |\n| d | x |\n"],
            // C2-W-146: a fragment takes the pair escapes of any content.
            'pair escape in a fragment' => ["| a |\n+ _e_ |\n", "| a |\n+ \\_e_ |\n"],
            'brace in a fragment' => ["| {x} |\n+ k |\n", "| {x} |\n+ k |\n"],
            // C2-W-088: a backtick run that opens nothing takes no backslash.
            'lone backtick' => ["| `a |\n", "| `a |\n"],
            'lone backtick before a cell' => ["| a `b | c |\n", "| a `b | c |\n"],
            'backtick before a code span cell' => ["| `a | `d` |\n", "| `a | `d` |\n"],
            // C2-W-135: the closing empty continuation row stands for the recorded blank ones.
            'comment closing the last group' => ["| b | < | %% n\n+ x | z |\n", "| b x z | < |\n%% n\n+ |\n"],
            // C2-W-063, C2-B-184: span marks are read from the first line of a row.
            'mark text on the first line' => ["| a |\n| \\^ |\n+ z |\n", "| a |\n| \\^ |\n+ z |\n"],
        ];
    }

    #[DataProvider('formatted')]
    public function testFormat(string $source, string $expected): void
    {
        $written = Carve::format($source);

        self::assertSame($expected, $written);
        self::assertSame(self::tree($source), self::tree($written));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function written(): array
    {
        return [
            // C2-B-169: a cell whose bare run would pair across a pipe keeps a backslash on its backticks.
            'backtick pairing across a pipe' => ["| a `b |\n+ c | `d |\n", "| a \\`b c | `d |\n"],
            'runs pairing along the row' => ["| \\`a | \\`b | \\`c |\n", "| \\`a | \\`b | `c |\n"],
            'code spans in two cells' => ["| `x` | `y` |\n", "| `x` | `y` |\n"],
            'lone backtick' => ["| `a | b |\n", "| `a | b |\n"],
        ];
    }

    #[DataProvider('written')]
    public function testWrite(string $source, string $expected): void
    {
        $written = Carve::write(Carve::parse($source));

        self::assertSame($expected, $written);
        self::assertSame(self::tree($source), self::tree($written));
    }

    public function testLineCommentBeforeMoreCellContentIsReported(): void
    {
        $result = Carve::writeWithDiagnostics(Carve::parse("| a %% c |\n+ z |\n"));

        self::assertSame(['structure-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->expectException(CarveException::class);
        Carve::write(Carve::parse("| a %% c |\n+ z |\n"));
    }

    private static function tree(string $source): string
    {
        $tree = json_decode(Carve::encodeTree(Carve::parse($source)), true);

        return json_encode(self::withoutPositions($tree), JSON_THROW_ON_ERROR);
    }

    private static function withoutPositions(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        unset($node['pos'], $node['srcByteLength']);

        return array_map(self::withoutPositions(...), $node);
    }
}
