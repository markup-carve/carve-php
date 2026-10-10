<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P3-023 closes a quote written after a dash when closing punctuation
 * follows it. Escaping that punctuation takes it out of the set the rule reads,
 * so the quote comes back as an OPENER and `fmt` changes what the document
 * renders (markup-carve/carve-php#3064).
 */
class TheFormatterKeepsThePunctuationThatDecidesASmartQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function closingPunctuationProvider(): array
    {
        return [
            'period' => ["end-\".\n", "end-\".\n"],
            'exclamation' => ["end-'!\n", "end-'!\n"],
            'semicolon' => ["end-\";\n", "end-\";\n"],
            'closing paren' => ["end-\")\n", "end-\")\n"],
            // The corpus row this was found on.
            'corpus 19-smart-typography-dashes-and-quotes-14' => [
                "end-\"\n\nend-\".\n\nend-'!\n",
                "end-\"\n\nend-\".\n\nend-'!\n",
            ],
        ];
    }

    #[DataProvider('closingPunctuationProvider')]
    public function testThePunctuationIsWrittenBare(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('closingPunctuationProvider')]
    public function testFormattingKeepsTheQuoteDirectionAndIsIdempotent(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($expected, $formatted, $source);
        $this->assertSame($converter->convert($source), $converter->convert($formatted), $source);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), $source);
    }

    /**
     * The four rules of CARVE-P3-023 and the escapes around them do not move.
     *
     * @return array<string, array{string}>
     */
    public static function controlProvider(): array
    {
        return [
            'R1 end of line closes' => ["end-\"\n"],
            'R1 whitespace closes' => ["end-\" x\n"],
            'R1 a dash still opens quoted text' => ["said -\"quoted text\"\n"],
            'R2 a listed elision is an apostrophe' => ["'tis the season\n"],
            'R2 a quoted elision keeps its pair' => ["a 'n' b\n"],
            'R3 single quotes do not nest' => ["'outer 'tis inner'\n"],
            'R3 an unclosed opener is demoted' => ["x 'unclosed to the end\n"],
            'R4 an empty braced comment is transparent' => ["x---{%%}\" y\n"],
            'an opening quote' => ["\"quoted\" text\n"],
            'an apostrophe' => ["the cat's paw\n"],
            'a quote at the end of the input' => ["he said \"\n"],
            'a quote before a digit' => ["the '90s\n"],
            'punctuation that opens a construct keeps its escape' => ["end-\"!`code`\n"],
        ];
    }

    #[DataProvider('controlProvider')]
    public function testAControlKeepsItsRenderAcrossAFormat(string $source): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($converter->convert($source), $converter->convert($formatted), $source);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), $source);
    }
}
