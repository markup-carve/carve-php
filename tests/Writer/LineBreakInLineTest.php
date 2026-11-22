<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-A-069, C2-W-117: every line ending in a line of a line block ends the line, so a soft break, or a hard break
 * before more content, does not read back.
 */
final class LineBreakInLineTest extends TestCase
{
    /**
     * @return array<string, array{list<array<string, mixed>>, string, list<string>}>
     */
    public static function cases(): array
    {
        $a = ['type' => 'text', 'value' => 'a'];
        $b = ['type' => 'text', 'value' => 'b'];
        $soft = ['type' => 'soft_break'];
        $hard = ['type' => 'hard_break'];
        $at = static fn (string $tail): string => "structure-unspellable /children/0/children/0/children/$tail";

        return [
            'a soft break between two texts' => [[$a, $soft, $b], "::: |\na\nb\n:::\n", [$at('1')]],
            'a hard break between two texts' => [[$a, $hard, $b], "::: |\na\nb\n:::\n", [$at('1')]],
            'a soft break inside emphasis' => [
                [['type' => 'emphasis', 'children' => [$a, $soft, $b]]],
                "::: |\n/a\nb/\n:::\n",
                [$at('0/children/1')],
            ],
            'a hard break that ends the line' => [[$a, $hard], "::: |\na\\\n:::\n", []],
            'a hard break before spaces only' => [[$a, $hard, ['type' => 'text', 'value' => '  ']], "::: |\na\\\n:::\n", []],
            'one text' => [[$a], "::: |\na\n:::\n", []],
        ];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param string $source
     * @param list<string> $losses
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testABreakThatSplitsTheLineIsReported(array $children, string $source, array $losses): void
    {
        $line = ['type' => 'line', 'children' => $children];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'line_block', 'children' => [$line]]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
