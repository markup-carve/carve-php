<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Math and the inline literal wrap a code span, so an unclosed run behind
 * either prefix takes the run's own trailing strip at the `X}` that ends it
 * (markup-carve/carve-php#2098, ruled on markup-carve/carve#2051).
 */
class APrefixedUnclosedRunTakesTheSameStripTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function strippedProvider(): array
    {
        return [
            'inline math' => ["{*\$`a *}\n", "<p><strong><span class=\"math inline\" role=\"math\">\\(a\\)</span></strong></p>\n"],
            'display math' => ["{*\$\$`a *}\n", "<p><strong><span class=\"math display\" role=\"math\">\\[a\\]</span></strong></p>\n"],
            'an inline literal' => ["{~!`b ~}\n", "<p><s>b</s></p>\n"],
            'two spaces and a tab' => ["{~!`b \t ~}\n", "<p><s>b</s></p>\n"],
            'math with nothing but a space' => ["{*\$` *}\n", "<p><strong><span class=\"math inline\" role=\"math\">\\(\\)</span></strong></p>\n"],
            'a literal with nothing but a space' => ["{~!` ~}\n", "<p><s></s></p>\n"],
            'the exhausted candidate branch' => ["{~!``a``` ~}\n", "<p><s>a```</s></p>\n"],
        ];
    }

    #[DataProvider('strippedProvider')]
    public function testTheCloserStripsTheRunsTrailingWhitespace(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function keptProvider(): array
    {
        return [
            // The strip is trailing only; an unclosed run takes no surrounding strip.
            'a leading space in math' => ["{*\$` a *}\n", "<p><strong><span class=\"math inline\" role=\"math\">\\( a\\)</span></strong></p>\n"],
            'interior whitespace in a literal' => ["{~!`a  b~}\n", "<p><s>a  b</s></p>\n"],
            // A closed run keeps taking the code span's own single-space strip.
            'a closed math run' => ["\$` a `\n", "<p><span class=\"math inline\" role=\"math\">\\(a\\)</span></p>\n"],
            // A line break is not whitespace the strip takes, as corpus 380 pins
            // for the bare run.
            'a line break in a line block' => [
                "::: |\n\$`\n%%\n:::\n",
                "<div class=\"line-block\">\n  <p><span class=\"math inline\" role=\"math\">\\(\n\\)</span></p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('keptProvider')]
    public function testWhatTheStripDoesNotTake(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The block end strips the same way, which is where math and the literal
     * already agreed with the code span.
     */
    public function testTheBlockEndIsUnchanged(): void
    {
        $this->assertSame("<p><span class=\"math inline\" role=\"math\">\\(a\\)</span></p>\n", $this->html("\$`a \n"));
        $this->assertSame("<p>a</p>\n", $this->html("!`a \n"));
    }
}
