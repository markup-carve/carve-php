<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `smart_quote` is PER-CHARACTER contextual substitution and not a paired span,
 * so `{` is an opening character and the braces around a quote are ordinary
 * text. Carve has no djot-style `{"` / `"}` quote marker
 * (markup-carve/carve-php#2028).
 */
class ABracedQuotePairKeepsItsBracesTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function bracedProvider(): array
    {
        return [
            'a double quote' => ["{\"}\n", "<p>{\u{201C}}</p>\n"],
            'a single quote' => ["{'}\n", "<p>{\u{2018}}</p>\n"],
            'between letters' => ["z{\"}z\n", "<p>z{\u{201C}}z</p>\n"],
            'doubled braces' => ["{{\"}}\n", "<p>{{\u{201C}}}</p>\n"],
            'a spare closing brace' => ["{\"}}\n", "<p>{\u{201C}}}</p>\n"],
            'a doubled quote' => ["{\"\"}\n", "<p>{\u{201C}\u{201C}}</p>\n"],
        ];
    }

    #[DataProvider('bracedProvider')]
    public function testTheBracesSurvive(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The clause's own worked example, which the removed handler contradicted.
     */
    public function testTheClauseExample(): void
    {
        $this->assertSame("<p>{\u{2018}q\u{2019}}</p>\n", $this->html("{'q'}\n"));
    }

    /**
     * The opening set is shared, so a parenthesis reads the quote the same way.
     */
    public function testAParenthesisOpensTheQuoteToo(): void
    {
        $this->assertSame("<p>(\u{201C}q\u{201D})</p>\n", $this->html("(\"q\")\n"));
    }

    /**
     * BOUND: the one braced typographic substitution the grammar does have.
     */
    public function testABracedHyphenPairIsStillAnEnDash(): void
    {
        $this->assertSame("<p>\u{2013}</p>\n", $this->html("{--}\n"));
    }

    /**
     * BOUND: a standalone marker never diverged, which is what said the defect
     * was the handler rather than the flanking rule.
     */
    public function testAStandaloneMarkerIsUnchanged(): void
    {
        $this->assertSame("<p>{\u{201C}x</p>\n", $this->html("{\"x\n"));
        $this->assertSame("<p>x\u{201D}}</p>\n", $this->html("x\"}\n"));
    }
}
