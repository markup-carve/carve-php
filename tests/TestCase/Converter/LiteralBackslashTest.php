<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A backslash in HTML or BBCode text is a character, not an escape
 * (markup-carve/carve-php#1214).
 *
 * Neither language has a backslash escape, so every backslash in their text is
 * one the author typed. Carve does have one, so an undoubled backslash arriving
 * there is read as an escape and consumes the character after it.
 *
 * Asserted as a ROUND TRIP - text in, same text out - rather than against a
 * particular Carve spelling. carve-js and carve-rs pass all of these while
 * spelling the Carve differently from each other, so the spelling is not the
 * claim; the surviving text is.
 */
class LiteralBackslashTest extends TestCase
{
    private function renderBack(string $carve): string
    {
        $html = trim(CarveConverter::create()->convert($carve));

        return preg_replace('#^<p>(.*)</p>$#s', '$1', $html) ?? $html;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function htmlTextProvider(): array
    {
        return [
            'before a strong delimiter' => ['<p>a \*b* c</p>', 'a \*b* c'],
            'before a tag opener' => ['<p>a \#y b</p>', 'a \#y b'],
            'before a braced pair' => ['<p>a \{^x^} b</p>', 'a \{^x^} b'],
            'a doubled backslash' => ['<p>a \\\\*b* c</p>', 'a \\\\*b* c'],
            'before a highlight' => ['<p>a \= b</p>', 'a \= b'],
            // `\ ` is the non-breaking-space escape, so this one came back as
            // `x&nbsp;y` - a character the author never wrote.
            'before a space' => ['<p>x \ y</p>', 'x \ y'],
            'before an emphasis slash' => ['<p>a \/b/ c</p>', 'a \/b/ c'],
            'before a comment marker' => ['<p>a \%% b</p>', 'a \%% b'],
            // BOUND: `\p` is not a Carve escape, so the renderer already kept
            // this one. It passed before the fix and passes after - here so a
            // Windows path is not credited to the fix.
            'before a non-escapable' => ['<p>C:\path\to\file</p>', 'C:\path\to\file'],
        ];
    }

    #[DataProvider('htmlTextProvider')]
    public function testHtmlTextKeepsItsBackslashes(string $html, string $text): void
    {
        $this->assertSame($text, $this->renderBack((new HtmlToCarve())->convert($html)));
    }

    public function testBbcodeTextKeepsItsBackslashes(): void
    {
        $converter = new BbcodeToCarve();

        $this->assertSame('a \*b* c', $this->renderBack($converter->convert('a \*b* c')));
        $this->assertSame('x \ y', $this->renderBack($converter->convert('x \ y')));
    }

    /**
     * A link label goes through the text pass FIRST, so the label escaper must
     * not double the backslash a second time. It used to, which spelled a
     * literal backslash where an escape was meant and let the construct behind
     * it render.
     */
    public function testALinkLabelIsNotEscapedTwice(): void
    {
        $carve = (new HtmlToCarve())->convert('<p><a href="ftp://x/">a {,y,} b</a></p>');

        $this->assertSame("[a \\{,y,} b](ftp://x/)\n", $carve);
        $this->assertStringContainsString(
            '<a href="ftp://x/">a {,y,} b</a>',
            CarveConverter::create()->convert($carve),
        );
    }

    /**
     * BOUND, not proof: the raw `alt` attribute never goes through the text
     * pass, so it was correct before and stays correct. It is here because the
     * shared label escaper STOPPED doubling backslashes in this change, and
     * this is the call site that therefore has to do it itself.
     */
    public function testAnImageAltKeepsItsBackslashes(): void
    {
        $carve = (new HtmlToCarve())->convert('<p><img src="x.png" alt="a \*b* c"></p>');

        $this->assertStringContainsString('\\\\', $carve);
    }

    /**
     * BOUND, and the row a careless fix breaks: Djot and Markdown DO have a
     * backslash escape, so a backslash there is the author's escape and must
     * NOT be doubled. Doubling it would render the backslash they were hiding.
     */
    public function testDjotAndMarkdownBackslashesAreNotDoubled(): void
    {
        $this->assertSame('a #y b', $this->renderBack((new DjotToCarve())->convert("a \\#y b\n")));
        $this->assertSame('a *b* c', $this->renderBack((new MarkdownToCarve())->convert("a \\*b* c\n")));
    }

    /**
     * BOUND, not proof: this passed before the fix too, because the old loop
     * reached the inner pair a different way. It is here because the new
     * scanner could silently lose it.
     *
     * Pinned against a NAMED mutation rather than an A/B: changing the scanner
     * to resume AFTER each match (`$offset = $at + 1 + strlen($text)`) instead
     * of just inside it fails this test and one case in MarkdownToCarveTest,
     * and nothing else in the suite.
     */
    public function testBothBracesOfANestedPairAreEscaped(): void
    {
        $this->assertSame(
            "nested \\{^a\\{,b,}c^} d\n",
            (new MarkdownToCarve())->convert("nested {^a{,b,}c^} d\n"),
        );
    }
}
