<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-151, C2-W-021: a paragraph or heading whose content is only spaces and tabs that end its line is written
 * empty once they are dropped, so the block is lost and reported. Losses are those of carve-js `next2`.
 */
final class BlankOnlyLeafTest extends TestCase
{
    /**
     * @return array<string, array{array<array<mixed>>, string, array<string>}>
     */
    public static function trees(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $paragraph = static fn (array ...$inlines): array => ['type' => 'paragraph', 'children' => $inlines];
        $heading = static fn (array ...$inlines): array => ['type' => 'heading', 'level' => 2, 'children' => $inlines];

        return [
            'paragraph of a space' => [[$paragraph($text(' '))], '', ['structure-unspellable /children/0']],
            'paragraph of two blank texts' => [[$paragraph($text("\t "), $text(' '))], '', ['structure-unspellable /children/0']],
            'heading of a space' => [[$heading($text(' '))], '', ['structure-unspellable /children/0']],
            'blank paragraph between paragraphs' => [
                [$paragraph($text('a')), $paragraph($text(' ')), $paragraph($text('b'))],
                "a\n\nb\n",
                ['structure-unspellable /children/1'],
            ],
            'blank paragraph in a quote' => [
                [['type' => 'block_quote', 'children' => [$paragraph($text(' '))]]],
                ">\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'text before a blank line keeps its paragraph' => [
                [$paragraph($text('a'), ['type' => 'soft_break'], $text(' '))],
                "a\n",
                ['structure-unspellable /children/0/children/1'],
            ],
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
    public function testABlankOnlyLeafIsLost(array $blocks, string $source, array $losses): void
    {
        $result = Writer::writeBlocksWithDiagnostics($blocks);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
