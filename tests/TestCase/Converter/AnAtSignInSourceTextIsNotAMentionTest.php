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
 * An at-sign that opens a Carve mention is escaped when it arrives as text
 * (markup-carve/carve-php#1380).
 *
 * The sibling of the tag rule from markup-carve/carve-php#1201. A mention is
 * not a pair: it opens on its own and needs no closer, so nothing downstream
 * can neutralize it and prose that quotes a directive came back as a mention
 * span.
 *
 * Asserted as a round trip, since the claim is about what the reader gets
 * back rather than which escape was chosen.
 */
class AnAtSignInSourceTextIsNotAMentionTest extends TestCase
{
    private function roundTrip(string $carve): string
    {
        $html = trim(CarveConverter::create()->convert($carve));

        return preg_replace('#^<p>(.*)</p>$#s', '$1', $html) ?? $html;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mentionOpenerProvider(): array
    {
        return [
            'after a space' => ['hi @user ok', 'hi @user ok'],
            'at line start' => ['@click toggles it', '@click toggles it'],
            'a dotted name' => ['use @keydown.window here', 'use @keydown.window here'],
            'after a parenthesis' => ['see (@can) there', 'see (@can) there'],
            'before a dash' => ['the @-form', 'the @-form'],
            'twice on a line' => ['@can and @click', '@can and @click'],
        ];
    }

    #[DataProvider('mentionOpenerProvider')]
    public function testHtmlTextKeepsItsAtSignAsText(string $text, string $expected): void
    {
        $html = '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>';

        $this->assertSame($expected, $this->roundTrip((new HtmlToCarve())->convert($html)));
    }

    #[DataProvider('mentionOpenerProvider')]
    public function testMarkdownTextKeepsItsAtSignAsText(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->roundTrip((new MarkdownToCarve())->convert($text)));
    }

    #[DataProvider('mentionOpenerProvider')]
    public function testBbcodeTextKeepsItsAtSignAsText(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->roundTrip((new BbcodeToCarve())->convert($text)));
    }

    #[DataProvider('mentionOpenerProvider')]
    public function testDjotTextKeepsItsAtSignAsText(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->roundTrip((new DjotToCarve())->convert($text)));
    }

    /**
     * BOUND: the escape mirrors the parser's opener, so an at-sign the parser
     * never opens on gains no backslash. Asserted on the converter output
     * rather than the round trip, because a round trip cannot tell a character
     * that was left alone from one that was escaped and unescaped again.
     *
     * @return array<string, array{0: string}>
     */
    public static function inertAtSignProvider(): array
    {
        return [
            'an email address' => ['mail me at foo@bar.de'],
            'intraword' => ['a@b'],
            'before a space' => ['name @ handle'],
            'before punctuation' => ['ping @, later'],
            'at end of line' => ['ends with @'],
        ];
    }

    #[DataProvider('inertAtSignProvider')]
    public function testAnAtSignThatOpensNothingIsLeftBare(string $text): void
    {
        $html = '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>';

        $this->assertSame($text, trim((new HtmlToCarve())->convert($html)));
    }

    /**
     * BOUND: an at-sign the source already escaped is not escaped twice.
     */
    public function testAnAlreadyEscapedAtSignIsNotEscapedAgain(): void
    {
        $this->assertSame('hi \@user ok', trim((new MarkdownToCarve())->convert('hi \@user ok')));
    }

    /**
     * BOUND: an authored Carve mention is still a mention. The rule belongs to
     * the converters, not to the parser.
     */
    public function testAnAuthoredMentionStillRenders(): void
    {
        $html = trim(CarveConverter::create()->convert('hi @user ok'));

        $this->assertStringContainsString('class="mention"', $html);
    }
}
