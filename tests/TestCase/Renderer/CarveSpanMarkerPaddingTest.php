<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lone table span marker keeps its padding.
 *
 * Glued to the opening pipe, `<` is also the LEFT-ALIGNMENT sigil, and the two
 * readings differ: the executable spec reads `|<|` as alignment on an empty cell
 * where all three engines read a colspan (carve#710). The grammar defines
 * `alignment_marker` as glued and lets `colspan_marker` carry surrounding
 * whitespace, so the padded form means the same thing to every reader - and the
 * writer was turning the unambiguous source into the ambiguous one.
 */
class CarveSpanMarkerPaddingTest extends TestCase
{
    private function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testColspanMarkerIsNotGluedToThePipe(): void
    {
        $out = $this->fmt("| a | b |\n|---|---|\n| < | d |\n");
        $this->assertStringContainsString('| < |', $out);
        $this->assertStringNotContainsString('|<|', $out);
    }

    public function testRowspanMarkerIsNotGluedEither(): void
    {
        $out = $this->fmt("| a | b |\n|---|---|\n| ^ | d |\n");
        $this->assertStringContainsString('| ^ |', $out);
        $this->assertStringNotContainsString('|^|', $out);
    }

    public function testGluedMarkerInTheSourceIsWrittenBackPadded(): void
    {
        // This parser reads the glued form as a span, so the document is a span
        // table either way; fmt canonicalizes it to the portable spelling.
        $out = $this->fmt("| a | b |\n|---|---|\n|<| d |\n");
        $this->assertStringContainsString('| < |', $out);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tableProvider(): array
    {
        return [
            'colspan in the first column' => ["| < | b |\n|---|---|\n| c | d |\n"],
            'rowspan in a body row' => ["| a | b |\n|---|---|\n| ^ | d |\n"],
            'two colspans in a row' => ["| a | b | c |\n|---|---|---|\n| d | < | < |\n"],
        ];
    }

    #[DataProvider('tableProvider')]
    public function testTableStillSaysTheSameThingAfterFormatting(string $source): void
    {
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }
}
