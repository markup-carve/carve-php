<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-146: the pair rounds of a text escape one opener per pair, so a run of marks needs a round per halving.
 * The rounds are settled on the spelling and written once (carve-php#5704); each form is the one the
 * round-by-round write gave.
 */
final class SettledPairEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        $unit = '\\*]\\*[\\!=\\* x';
        $run = static fn (int $count): string => str_repeat($unit . ' ', $count - 1) . $unit . "\n";
        $written = static fn (int $count): string => str_repeat('\\*]\\*[\\!\\=* x ', $count - 1) . "\\*]\\*[\\!=* x\n";

        return [
            'highlight marks, two rounds' => [$run(5), $written(5)],
            'highlight marks, three rounds' => [$run(8), $written(8)],
            'highlight marks, four rounds' => [$run(16), $written(16)],
            'highlight marks, many rounds' => [$run(300), $written(300)],
            'marks in an item and its lazy line' => [
                "1. -=** =}{=]+.{===]ab<^\\*>.\n=))\"(\n",
                "1. -\\=** \\=}{\\=]+.{===]ab<^*>.\n   =))\"(\n",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testRoundsSettleToTheRoundByRoundForm(string $source, string $expected): void
    {
        $out = Carve::format($source);

        $this->assertSame($expected, $out);
        $this->assertSame($out, Carve::format($out));
    }
}
