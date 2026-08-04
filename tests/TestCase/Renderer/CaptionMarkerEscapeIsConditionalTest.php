<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2: a character is escaped IF AND ONLY IF omitting the escape would
 * change the re-parsed AST. A `^ ` at the start of a block line opens a
 * CAPTION only where the preceding block can host one - a table, a code block,
 * a block quote, or a paragraph holding nothing but an image or display math.
 *
 * The writer escaped it from the line position alone, so a caption marker
 * after a plain paragraph came back as `\^ cap` for a construct that cannot
 * form there (carve-php#758). carve-js already emits the minimal form;
 * carve-rs has the same defect (markup-carve/carve-rs#565).
 */
class CaptionMarkerEscapeIsConditionalTest extends TestCase
{
    private function carve(string $source): string
    {
        return trim(CarveConverter::toCarve($source));
    }

    public function testAfterAPlainParagraphTheMarkerIsBare(): void
    {
        $this->assertSame("para\n^ cap", $this->carve("para\n^ cap\n"));
    }

    public function testAfterAnUnresolvedReferenceImageTheMarkerIsBare(): void
    {
        // The label resolves to nothing, so there is no image to caption.
        $this->assertSame("![a][nope]\n^ cap", $this->carve("![a][nope]\n^ cap\n"));
    }

    public function testAfterAnImageTheMarkerKeepsItsEscape(): void
    {
        // Here the caption WOULD form, so the escape is load-bearing: the
        // source had the image and the marker on separate blocks.
        $formatted = $this->carve(" ![Apollo](a.jpg)\n ^ Figure 1: moon\n");

        $this->assertStringContainsString('\\^ Figure 1', $formatted);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'plain paragraph' => ["para\n^ cap\n"],
            'unresolved reference image' => ["![a][nope]\n^ cap\n"],
            'indented image and caption' => [" ![Apollo](a.jpg)\n ^ Figure 1: moon\n"],
            'real figure' => ["![a](a.png)\n^ cap\n"],
            'table caption' => ["| a |\n^ tbl\n"],
            'code block caption' => ["```\nx\n```\n^ lst\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testFormattingPreservesTheRendering(string $source): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($converter->convert($source), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'fmt is idempotent');
    }
}
