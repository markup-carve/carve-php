<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117 (carve-php#5941): a comment before the first row of a group reads back with the block above it, and a
 * braced comment among the rows reads back as a line comment; both are reported, as carve-js reports them.
 */
final class TableCommentReadBackTest extends TestCase
{
    /**
     * @return array<string, array{array<array<string, mixed>>, string, array<array{string, string}>}>
     */
    public static function tables(): array
    {
        $comment = ['type' => 'comment', 'form' => 'line', 'value' => ' c'];
        $braced = ['type' => 'comment', 'form' => 'braced', 'value' => 'c'];
        $body = fn (array ...$children): array => ['type' => 'table_body', 'children' => $children];
        $foot = ['type' => 'table_foot', 'children' => [self::row('f', true)]];

        return [
            'before the first row of the table' => [
                [$body($comment, self::row('b')), $foot],
                "{footer-rows=1}\n%%  c\n| b |\n|= f |\n",
                [['structure-unspellable', '/children/0']],
            ],
            'before the first row of a body alone' => [
                [$body($comment, self::row('b'))],
                "%%  c\n| b |\n",
                [['structure-unspellable', '/children/0']],
            ],
            'before the first row of a second body' => [
                [$body(self::row('a')), $body($comment, self::row('b'))],
                "{body-rows=1,1}\n| a |\n%%  c\n| b |\n",
                [['structure-unspellable', '/children/0/children/0'], ['structure-unspellable', '/children/0/children/1/children/0']],
            ],
            'between rows' => [
                [$body(self::row('a'), $comment, self::row('b'))],
                "| a |\n%%  c\n| b |\n",
                [],
            ],
            'braced between rows' => [
                [$body(self::row('a'), $braced, self::row('b'))],
                "| a |\n%% c\n| b |\n",
                [['field-unspellable', '/children/0/children/0/children/1/form']],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $groups
     * @param string $source
     * @param array<array{string, string}> $losses
     */
    #[DataProvider('tables')]
    public function testCommentReadBack(array $groups, string $source, array $losses): void
    {
        $tree = [
            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [['type' => 'table', 'columns' => [['type' => 'table_column']], 'children' => $groups]],
        ];

        $result = Carve::writeWithDiagnostics($tree);

        self::assertSame($source, $result->source);
        self::assertSame($losses, array_map(static fn ($d): array => [$d->code, (string)$d->path], $result->diagnostics));
    }

    public function testFootOnlyTableOfTwoRowsIsSpelled(): void
    {
        // carve#6664: an empty `body-rows` with a footer count of all the rows leaves no head (C2-B-187, C2-W-136).
        $result = Carve::importHtml('<table><tfoot><tr><th>F</th><th>G</th></tr><tr><td>x</td><td>y</td></tr></tfoot></table>');

        self::assertSame("{footer-rows=2 body-rows}\n|= F |= G |\n| x | y |\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $text, bool $header = false): array
    {
        return [

            'type' => 'table_row',
            'children' => [
                [
                    'type' => 'table_cell',
                    'header' => $header,
                    'children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $text]]]],
                ],
            ],
        ];
    }
}
