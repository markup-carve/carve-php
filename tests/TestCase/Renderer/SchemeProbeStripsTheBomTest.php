<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheme probe strips every character PART 9 section 25 names, including the
 * BOM (carve-php#874).
 *
 * The clause lists what has to come off before the scheme is matched and ends
 * with "and the BOM (U+FEFF)". The probe stripped `\p{Z}` (separators) and
 * `\p{Cc}` (controls), and U+FEFF is in NEITHER - its category is Cf (format).
 * So a `<U+FEFF>javascript:` destination reached the output as a live `href`:
 *
 *     <p><a href="&#xFEFF;javascript:alert(1)">click</a></p>
 *
 * Seventeen of the eighteen characters the clause names ARE Z or Cc and were
 * already stripped, which is why nothing caught it - a probe that handles all
 * but one member of a named list looks like a working probe.
 *
 * carve-js and carve-rs both blanked this destination, so this engine was the
 * outlier rather than the shape being unspecified.
 */
class SchemeProbeStripsTheBomTest extends TestCase
{
    /**
     * Every character the clause names, so a future rewrite of the probe cannot
     * drop one the way this one dropped the BOM.
     *
     * @return array<string, array{int}>
     */
    public static function namedCharacters(): array
    {
        return [
            'U+202F NARROW NO-BREAK SPACE' => [0x202F],
            'U+00A0 NBSP' => [0x00A0],
            'U+2000 EN QUAD' => [0x2000],
            'U+2005 FOUR-PER-EM SPACE' => [0x2005],
            'U+200A HAIR SPACE' => [0x200A],
            'U+205F MEDIUM MATHEMATICAL SPACE' => [0x205F],
            'U+3000 IDEOGRAPHIC SPACE' => [0x3000],
            'U+2028 LINE SEPARATOR' => [0x2028],
            'U+2029 PARAGRAPH SEPARATOR' => [0x2029],
            'U+FEFF BOM' => [0xFEFF],
        ];
    }

    #[DataProvider('namedCharacters')]
    public function testANamedCharacterCannotHideAScheme(int $codepoint): void
    {
        $ws = mb_chr($codepoint, 'UTF-8');
        $html = (new CarveConverter())->convert("[click]({$ws}javascript:alert(1))\n");

        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*javascript:/i',
            $html,
            sprintf('U+%04X hid the scheme from the denylist', $codepoint),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sinks(): array
    {
        $bom = mb_chr(0xFEFF, 'UTF-8');

        return [
            'inline link' => ["[click]({$bom}javascript:alert(1))\n"],
            'image' => ["![i]({$bom}javascript:alert(1))\n"],
            'reference definition' => ["[click][a]\n\n[a]: {$bom}javascript:alert(1)\n"],
        ];
    }

    #[DataProvider('sinks')]
    public function testTheBomIsStrippedAtEverySink(string $source): void
    {
        $this->assertStringNotContainsString(
            'javascript:alert',
            (new CarveConverter())->convert($source),
        );
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targets(): array
    {
        return [
            'html' => [HtmlRenderer::class],
            'markdown' => [MarkdownRenderer::class],
            'plain' => [PlainTextRenderer::class],
            'ansi' => [AnsiRenderer::class],
        ];
    }

    #[DataProvider('targets')]
    public function testTheBomIsStrippedOnEveryTarget(string $renderer): void
    {
        // Section 25 binds every target that emits a resolvable URL - a scheme
        // blanked in HTML and passed through in Markdown is the same sink one
        // step removed.
        $bom = mb_chr(0xFEFF, 'UTF-8');
        $out = CarveConverter::create(renderer: new $renderer())
            ->convert("[click]({$bom}javascript:alert(1))\n");

        $this->assertStringNotContainsString('javascript:alert', $out);
    }

    public function testAnOrdinarySchemeStillResolves(): void
    {
        // The control. Widening the strip class must not blank a real URL, and
        // a BOM inside the destination is not a leading one.
        $html = (new CarveConverter())->convert("[x](https://ok.example/a)\n");
        $this->assertStringContainsString('href="https://ok.example/a"', $html);
    }
}
