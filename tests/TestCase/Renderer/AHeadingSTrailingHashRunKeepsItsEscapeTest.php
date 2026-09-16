<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A run of hashes at the end of a heading line is the ATX closing sequence to
 * every CommonMark reader, which drops it. PART 11 §8a M1f escapes the run's
 * first hash where a space or a tab opens it (markup-carve/carve#2052).
 */
class AHeadingSTrailingHashRunKeepsItsEscapeTest extends TestCase
{
    protected function markdown(string $source): string
    {
        return CarveConverter::markdown()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function escapedProvider(): array
    {
        return [
            // Corpus 84-single-line-headings-6 through -10.
            'a run of two' => ["# a ##\n", "# a \\##\n"],
            'a run of one' => ["# a #\n", "# a \\#\n"],
            'text after a medial run' => ["# a ### b ###\n", "# a ### b \\###\n"],
            'a run of seven' => ["# a #######\n", "# a \\#######\n"],
            'a heading in a quoted bullet' => [
                "> - a\n>\n>   ### b ###\n",
                "> - a\n>\n>   ### b \\###\n",
            ],
            'a tab opens the run' => ["# a\t##\n", "# a\t\\##\n"],
            'the run is the whole heading' => ["# ##\n", "# \\##\n"],
        ];
    }

    #[DataProvider('escapedProvider')]
    public function testTheRunKeepsItsEscape(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->markdown($source));
    }

    /**
     * @return array<string, array<string>>
     */
    public static function bareProvider(): array
    {
        return [
            'a medial run is no closing sequence' => ["# a ## b\n", "# a ## b\n"],
            'no space opens the run' => ["# a##\n", "# a##\n"],
            'a paragraph is not a heading line' => ["a ##\n", "a ##\n"],
            'a heading without a run' => ["# a\n", "# a\n"],
        ];
    }

    #[DataProvider('bareProvider')]
    public function testARunThatCannotCloseStaysBare(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->markdown($source));
    }

    public function testAnIdSuffixLeavesNoRunAtTheLineEnd(): void
    {
        // The `{#id}` a referenced heading carries stands behind the run, so
        // the line has no closing sequence and the hashes need no escape.
        $this->assertSame(
            "# a ## {#a}\n\n[x](#a)\n",
            $this->markdown("# a ##\n\n[x](#a)\n"),
        );
    }
}
