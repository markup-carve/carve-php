<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalTextLossTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function lines(): array
    {
        return [
            'paragraph space' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x ']]],
                "x\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'paragraph tabs' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => "x \t"]]],
                "x\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'heading space' => [
                ['type' => 'heading', 'level' => 2, 'children' => [['type' => 'text', 'value' => 'x ']]],
                "== x\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'space before soft break' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a '],
                        ['type' => 'soft_break'],
                        ['type' => 'text', 'value' => 'b'],
                    ],
                ],
                "a\nb\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'spaces on both sides of a soft break' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a '],
                        ['type' => 'soft_break'],
                        ['type' => 'text', 'value' => 'b '],
                    ],
                ],
                "a\nb\n",
                [
                    'structure-unspellable /children/0/children/0',
                    'structure-unspellable /children/0/children/2',
                ],
            ],
            'interior space stays' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a b']]],
                "a b\n",
                [],
            ],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     * @param array<string> $diagnostics
     */
    #[DataProvider('lines')]
    public function testReportsOnlyTextChangedAtALineEnding(array $block, string $source, array $diagnostics): void
    {
        $written = Writer::writeBlocksWithDiagnostics([$block]);
        self::assertSame($source, $written->source);
        self::assertSame($diagnostics, array_map(
            static fn ($diagnostic): string => $diagnostic->code . ' ' . $diagnostic->path,
            $written->diagnostics,
        ));
    }

    public function testReproductionReadbackShowsTheReportedLoss(): void
    {
        $block = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x ']]];
        $written = Writer::writeBlocksWithDiagnostics([$block]);
        $read = TreeEncoder::decodedForWriter(Carve::parse($written->source));

        self::assertSame("x\n", $written->source);
        self::assertSame('structure-unspellable', $written->diagnostics[0]->code);
        self::assertFalse(TreeEquality::equal([$block], $read['children']));
    }
}
