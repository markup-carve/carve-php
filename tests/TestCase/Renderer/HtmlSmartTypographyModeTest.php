<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The HTML target honors the smart-typography mode.
 *
 * The Markdown renderer had the switch; HTML took the glyph unconditionally,
 * so a host that asked for source output got a page that looked configured and
 * was not - the state the spec names as the only non-conformant one for this
 * switch (a host may omit it, but not accept it silently). carve#560.
 */
class HtmlSmartTypographyModeTest extends TestCase
{
    private function source(): CarveConverter
    {
        return CarveConverter::create(
            null,
            (new HtmlRenderer())->setSmartTypography(SmartTypographyMode::Source),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function typographyProvider(): array
    {
        return [
            'ellipsis' => ['a...b', '<p>a…b</p>', '<p>a...b</p>'],
            'en dash' => ['a--b', '<p>a–b</p>', '<p>a--b</p>'],
            'em dash' => ['a---b', '<p>a—b</p>', '<p>a---b</p>'],
            'arrow' => ['a -> b', '<p>a → b</p>', '<p>a -&gt; b</p>'],
            'comparison' => ['a <= b', '<p>a ≤ b</p>', '<p>a &lt;= b</p>'],
            'copyright' => ['(c) 2026', '<p>© 2026</p>', '<p>(c) 2026</p>'],
            'double quotes' => ['say "hi"', '<p>say “hi”</p>', '<p>say "hi"</p>'],
        ];
    }

    #[DataProvider('typographyProvider')]
    public function testSourceModeEmitsWhatTheAuthorTyped(string $source, string $glyphs, string $typed): void
    {
        $this->assertNotSame('', $glyphs);
        $this->assertSame($typed, trim($this->source()->convert($source)));
    }

    #[DataProvider('typographyProvider')]
    public function testGlyphModeRemainsTheDefault(string $source, string $glyphs, string $typed): void
    {
        $this->assertNotSame('', $typed);
        $this->assertSame($glyphs, trim(CarveConverter::create()->convert($source)));
    }

    public function testTheEscapingRuleIsUnchanged(): void
    {
        // Source mode changes WHICH text is emitted, never how it is escaped.
        $this->assertSame('<p>a &amp; b</p>', trim($this->source()->convert('a & b')));
    }

    public function testCodeSpansAreLeftAlone(): void
    {
        $this->assertSame('<p><code>a...b</code></p>', trim($this->source()->convert('`a...b`')));
    }

    public function testHeadingIdsDoNotDependOnTheSwitch(): void
    {
        // Ids slug from the glyph text, normalized back to ASCII, so a
        // document's ids are the same in both modes. A switch that moved them
        // would break every link into the document.
        $carve = "# Don't repeat yourself\n";

        $this->assertStringContainsString('id="Don-t-repeat-yourself"', CarveConverter::create()->convert($carve));
        $this->assertStringContainsString('id="Don-t-repeat-yourself"', $this->source()->convert($carve));
    }
}
