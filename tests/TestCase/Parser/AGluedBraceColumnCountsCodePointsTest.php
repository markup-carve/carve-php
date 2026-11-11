<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A glued attribute block's brace column is counted in code points, not bytes
 * (CARVE-P7-001 item 1, markup-carve/carve#5984; spec corpus row 1077).
 */
class AGluedBraceColumnCountsCodePointsTest extends TestCase
{
    protected function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    public function testTheReproductionTakesTheRun(): void
    {
        $this->assertSame(
            '<p title="😀" class="b c">p</p>',
            $this->html("{title=\"😀\"}{.b\n            .c}\np\n"),
        );
    }

    public function testAQuotedRunWithTheCharacterInALaterBlock(): void
    {
        $this->assertSame(
            '<blockquote><p class="a b c" title="𝒜𝒜">p</p></blockquote>',
            $this->html("> {.a}{title=\"𝒜𝒜\"}{.b\n>                   .c}\n> p\n"),
        );
    }

    public function testAContinuationOnTheBraceColumnIsStillRefused(): void
    {
        $this->assertSame(
            "<p>{title=“😀”}{.b\n.c}\np</p>",
            $this->html("{title=\"😀\"}{.b\n           .c}\np\n"),
        );
    }

    /**
     * Any one-column character gives the structure an ASCII letter gives.
     *
     * @return array<string, array{string}>
     */
    public static function characterProvider(): array
    {
        return [
            'two-byte' => ['é'],
            'three-byte' => ['中'],
            'four-byte' => ['😀'],
        ];
    }

    #[DataProvider('characterProvider')]
    public function testACharacterIsOneColumnWhateverItsWidthInBytes(string $char): void
    {
        foreach (["{title=\"@\"}{.b\n            .c}\np\n", "- {.a}{title=\"@\"}{.b\n                  .c}\n  p\n"] as $template) {
            $this->assertSame(
                $this->html(str_replace('@', 'X', $template)),
                str_replace($char, 'X', $this->html(str_replace('@', $char, $template))),
            );
        }
    }
}
