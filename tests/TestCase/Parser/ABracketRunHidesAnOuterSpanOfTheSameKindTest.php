<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §9 E2b/E3: a bracket run, resolved or literal, bounds every pair, so a
 * span opened outside it is not reachable inside it and a same-kind bare pair
 * in the run nests (markup-carve/carve#3897, markup-carve/carve-php#4219).
 */
class ABracketRunHidesAnOuterSpanOfTheSameKindTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a literal run' => ["*[*g*] x*\n", '<p><strong>[<strong>g</strong>] x</strong></p>'],
            'a link' => ["*[*g*](u) x*\n", '<p><strong><a href="u"><strong>g</strong></a> x</strong></p>'],
            'a span' => ["*[*g*]{.c} x*\n", '<p><strong><span class="c"><strong>g</strong></span> x</strong></p>'],
            'a defined reference' => ["*[*g*][r] x*\n\n[r]: u\n", '<p><strong><a href="u"><strong>g</strong></a> x</strong></p>'],
            // An unresolved reference keeps its whole source literal, outer span or not.
            'an undefined reference' => ["*[*g*][r] x*\n", '<p><strong>[*g*][r] x</strong></p>'],
            'underline' => ["_[_g_] x_\n", '<p><u>[<u>g</u>] x</u></p>'],
            'strikethrough' => ["~[~g~] x~\n", '<p><s>[<s>g</s>] x</s></p>'],
            'highlight' => ["=[=g=] x=\n", '<p><mark>[<mark>g</mark>] x</mark></p>'],
            'emphasis' => ["/[/g/] x/\n", '<p><em>[<em>g</em>] x</em></p>'],
            'a braced parent' => ["{*a [*g*] x*}\n", '<p><strong>a [<strong>g</strong>] x</strong></p>'],
            'a nested run' => ["*[x [*g*] y] z*\n", '<p><strong>[x [<strong>g</strong>] y] z</strong></p>'],
            'a combined token' => ["/*[*g*] x*/\n", '<p><strong><em>[<strong>g</strong>] x</em></strong></p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testTheInnerPairNests(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAnInlineNoteBodyIsBoundedToo(): void
    {
        $html = CarveConverter::create()->convert("*^[*g*] x*\n");

        $this->assertStringContainsString('<p><strong>g</strong><a href="#fnref1"', $html);
    }
}
