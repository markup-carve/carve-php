<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text that is plain in the source stays plain in the output
 * (markup-carve/carve-php#1216 and markup-carve/carve-php#1218).
 *
 * HTML and BBCode have no code span and no attribute block, so a backtick or a
 * `{#…}` in their text is characters the author typed. Carried over bare they
 * became markup.
 *
 * Asserted as a round trip, since the claim is about what the reader gets back,
 * not which escape was chosen. carve-js and carve-rs pass all of these.
 */
class HtmlTextIsNotMarkupTest extends TestCase
{
    private function roundTrip(string $carve): string
    {
        $html = trim(CarveConverter::create()->convert($carve));

        return preg_replace('#^<p>(.*)</p>$#s', '$1', $html) ?? $html;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function plainTextProvider(): array
    {
        return [
            'a backtick pair' => ['<p>a `b` c</p>', 'a `b` c'],
            'a backtick pair intraword' => ['<p>a`b`c</p>', 'a`b`c'],
            'one lone backtick' => ['<p>x ` y</p>', 'x ` y'],
            'a leading backtick' => ['<p>`start</p>', '`start'],
            'a trailing backtick' => ['<p>end `</p>', 'end `'],
            'a backtick inside braces' => ['<p>{`x`}</p>', '{`x`}'],
            'an unpaired brace opener' => ['<p>a {*b{* c</p>', 'a {*b{* c'],
            'an attribute block' => ['<p>a {#id} b</p>', 'a {#id} b'],
        ];
    }

    #[DataProvider('plainTextProvider')]
    public function testHtmlTextSurvivesTheRoundTrip(string $html, string $text): void
    {
        $this->assertSame($text, $this->roundTrip((new HtmlToCarve())->convert($html)));
    }

    public function testBbcodeTextSurvivesTheRoundTrip(): void
    {
        $converter = new BbcodeToCarve();

        $this->assertSame('a `b` c', $this->roundTrip($converter->convert('a `b` c')));
        $this->assertSame('a {#id} b', $this->roundTrip($converter->convert('a {#id} b')));
    }

    /**
     * BOUND: a real `code` or `pre` element takes its own path and still emits
     * a code span. Escaping text-node backticks must not reach it.
     */
    public function testARealCodeElementStillBecomesACodeSpan(): void
    {
        $carve = (new HtmlToCarve())->convert('<p>a `b` and <code>k</code> c</p>');

        $this->assertStringContainsString('`k`', $carve);
        $this->assertStringContainsString(
            '<code>k</code>',
            CarveConverter::create()->convert($carve),
        );
    }

    /**
     * BOUND, and the row the attribute-block fix would break if it were applied
     * to every converter: Djot HAS attribute blocks, so a pinned id there is
     * deliberate and must survive unescaped.
     */
    public function testDjotKeepsAPinnedAttributeBlock(): void
    {
        $this->assertStringContainsString(
            '{#manual}',
            (new DjotToCarve())->convert("{#manual}\n# Hello\n"),
        );
    }
}
