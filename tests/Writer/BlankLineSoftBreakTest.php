<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117, C2-W-151: a soft break that ends an empty line, or that ends the paragraph, does not read back.
 */
final class BlankLineSoftBreakTest extends TestCase
{
    /**
     * @return array<string, array{list<array<string, string>>, string, list<string>}>
     */
    public static function cases(): array
    {
        $a = ['type' => 'text', 'value' => 'a'];
        $b = ['type' => 'text', 'value' => 'b'];
        $soft = ['type' => 'soft_break'];
        $hard = ['type' => 'hard_break'];
        $spaces = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $at = static fn (int $i): string => "structure-unspellable /children/0/children/$i";

        return [
            'two soft breaks' => [[$a, $soft, $soft, $b], "a\n\nb\n", [$at(2)]],
            'a line of only spaces' => [[$a, $soft, $spaces('  '), $soft, $b], "a\n\nb\n", [$at(3)]],
            'a soft break first' => [[$soft, $b], "\nb\n", [$at(0)]],
            'a soft break after spaces only' => [[$spaces(' '), $soft, $b], "\nb\n", [$at(1)]],
            'a soft break last' => [[$a, $soft], "a\n", [$at(1)]],
            'a soft break after a hard break' => [[$a, $hard, $soft, $b], "a\\\n\nb\n", [$at(2)]],
            'a hard break after a soft break' => [[$a, $soft, $hard, $b], "a\n\\\nb\n", []],
            'one soft break' => [[$a, $soft, $b], "a\nb\n", []],
        ];
    }

    /**
     * @param list<array<string, string>> $children
     * @param string $source
     * @param list<string> $losses
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testASoftBreakThatDoesNotReadBackIsReported(array $children, string $source, array $losses): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }

    /**
     * @return void
     */
    public function testHeadingsAndLinesAreHoldersToo(): void
    {
        $text = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $soft = ['type' => 'soft_break'];
        $heading = ['type' => 'heading', 'level' => 1, 'children' => [$text('a'), $soft, $soft, $text('b')]];
        $result = Writer::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => [$heading]]);
        $this->assertSame("= a\n\n  b\n", $result->source);
        $this->assertSame(['/children/0/children/2'], array_map(static fn ($d): ?string => $d->path, $result->diagnostics));
        $line = ['type' => 'line', 'children' => [$text('a'), $soft, $soft, $text('b')]];
        $block = ['type' => 'line_block', 'children' => [$line]];
        $result = Writer::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => [$block]]);
        $this->assertSame(['/children/0/children/0/children/2'], array_map(static fn ($d): ?string => $d->path, $result->diagnostics));
    }

    /**
     * @return void
     */
    public function testAnEmphasisMarkerFillsTheLine(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'emphasis', 'children' => [['type' => 'soft_break'], ['type' => 'text', 'value' => 'b']]],
                    ],
                ],
            ],
        ];
        $this->assertSame([], Writer::writeWithDiagnostics($tree)->diagnostics);
    }
}
