<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2 escapes a character IF AND ONLY IF omitting the escape would
 * change the re-parsed AST, and §4 asks for "the minimal-escape form when
 * dropping the candidate escapes changes nothing".
 *
 * A colon opens something only at the START of a line - `::` a definition
 * term, `:::` a div - so mid-line it is ordinary punctuation. The writer
 * escaped every colon, which produced `\^ Figure 1\: moon` where the caret is
 * already escaped and nothing downstream reads the colon (carve-php#743).
 * carve-rs emits the same bytes as this now, and the spec's own fmt fixture
 * for `158-indented-image-and-caption-stay-literal` pins it.
 */
class ColonEscapeIsPositionalTest extends TestCase
{
    public function testAColonAfterAnEscapedCaptionMarkerIsBare(): void
    {
        $this->assertSame(
            "![Apollo](a.jpg)\n\\^ Figure 1: moon\n",
            CarveConverter::toCarve(" ![Apollo](a.jpg)\n ^ Figure 1: moon\n"),
        );
    }

    public function testAMidLineColonIsBare(): void
    {
        $this->assertSame("a: b\n", CarveConverter::toCarve("a: b\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'definition term' => [":: term\n:  def\n"],
            'div opener' => ["::: note\nx\n:::\n"],
            'colon opening a line' => [":start of line\n"],
            'colon mid line' => ["a: b\n"],
            'caption with a number' => ["![i](i.png)\n^ Figure 1: moon\n"],
            'escaped caption marker' => [" ![i](i.png)\n ^ Figure 1: moon\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testFormattingPreservesTheDocument(string $source): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($converter->convert($source), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'fmt is idempotent');
    }
}
