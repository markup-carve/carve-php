<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference label that crosses a line fails, and the whole form stays text:
 * no inline reading, no abbreviation, nothing after it opens
 * (CARVE-P3-004, markup-carve/carve#4680).
 */
class AReferenceLabelAcrossALineStaysVerbatimTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function formProvider(): array
    {
        return [
            'explicit label' => ["[*t*][a\nb]\n", "<p>[*t*][a\nb]</p>"],
            'destination after the label' => ["[x][a\nb](u)\n", "<p>[x][a\nb](u)</p>"],
            'attributes after the label' => ["[x][a\nb]{.c}\n", "<p>[x][a\nb]{.c}</p>"],
            'quoted destination' => ["> [x][a\n> b](u)\n", "<blockquote><p>[x][a\nb](u)</p></blockquote>"],
            'abbreviation in the label' => ["*[y]: z\n\n[x][y\nq]\n", "<p>[x][y\nq]</p>"],
        ];
    }

    #[DataProvider('formProvider')]
    public function testTheFormStaysText(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    #[DataProvider('formProvider')]
    public function testTheWriterKeepsTheSourceSpelling(string $source, string $html): void
    {
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($source, $formatted);
        $this->assertSame($html, trim((new CarveConverter())->convert($formatted)));
    }

    public function testAnAbbreviationDefinitionDoesNotForceEscapes(): void
    {
        $source = "*[y]: z\n\na_b `c` \"d\"\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
