<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-I-002: a reader drops the spaces and tabs that start a line, so text that starts a line with them loses its value.
 * Only text of nothing else that also ends the line is dropped without a report (C2-W-151). Outputs and losses are
 * those of carve-js `next2`.
 */
final class LineStartBlankTextTest extends TestCase
{
    /**
     * @return array<string, array{array<array<mixed>>, string, array<string>}>
     */
    public static function trees(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $em = ['type' => 'emphasis', 'children' => [$text('a')]];
        $soft = ['type' => 'soft_break'];
        $paragraph = static fn (array ...$inlines): array => ['type' => 'paragraph', 'children' => $inlines];
        $heading = static fn (array ...$inlines): array => ['type' => 'heading', 'level' => 1, 'children' => $inlines];
        $at = static fn (string $path): array => ["field-unspellable $path/value"];

        return [
            'space before emphasis' => [[$paragraph($text(' '), $em)], " /a/\n", $at('/children/0/children/0')],
            'space before code' => [[$paragraph($text(' '), ['type' => 'code', 'value' => 'x'])], " `x`\n", $at('/children/0/children/0')],
            'space before emphasis after a soft break' => [
                [$paragraph($text('b'), $soft, $text(' '), $em)],
                "b\n /a/\n",
                $at('/children/0/children/2'),
            ],
            'space-b after a soft break' => [[$paragraph($text('a'), $soft, $text(' b'))], "a\n b\n", $at('/children/0/children/2')],
            'space-b after a hard break' => [
                [$paragraph($text('a'), ['type' => 'hard_break'], $text(' b'))],
                "a\\\n b\n",
                $at('/children/0/children/2'),
            ],
            'space before emphasis in a quote' => [
                [['type' => 'block_quote', 'children' => [$paragraph($text(' '), $em)]]],
                ">  /a/\n",
                $at('/children/0/children/0/children/0'),
            ],
            'heading of space-a' => [[$heading($text(' a'))], "=  a\n", $at('/children/0/children/0')],
            'heading of space before emphasis' => [[$heading($text(' '), $em)], "=  /a/\n", $at('/children/0/children/0')],
            'heading line two of space-b' => [[$heading($text('a'), $soft, $text(' b'))], "= a\n   b\n", $at('/children/0/children/2')],
            'space between spans stays' => [[$paragraph($em, $text(' '), $em)], "/a/ /a/\n", []],
        ];
    }

    /**
     * @param array<array<mixed>> $blocks
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('trees')]
    public function testLineStartBlanksOfTextAreReported(array $blocks, string $source, array $losses): void
    {
        $result = Writer::writeBlocksWithDiagnostics($blocks);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
