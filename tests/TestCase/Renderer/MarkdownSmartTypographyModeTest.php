<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownSmartTypographyModeTest extends TestCase
{
    private function source(): CarveConverter
    {
        return CarveConverter::create(
            null,
            (new MarkdownRenderer())->setSmartTypography(SmartTypographyMode::Source),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function typographyProvider(): array
    {
        return [
            'ellipsis' => ['a...b', 'a…b'],
            'en dash' => ['a--b', 'a–b'],
            'em dash' => ['a---b', 'a—b'],
            'dash run' => ['a----b', 'a––b'],
            'arrow' => ['a -> b', 'a → b'],
            'comparison' => ['a <= b', 'a ≤ b'],
            'copyright' => ['(c) 2026', '© 2026'],
            'double quotes' => ['say "hi"', 'say “hi”'],
            'single quotes' => ["say 'hi'", 'say ‘hi’'],
        ];
    }

    #[DataProvider('typographyProvider')]
    public function testSourceModeEmitsWhatTheAuthorTyped(string $source, string $glyphs): void
    {
        $this->assertNotSame('', $glyphs);
        $this->assertSame($source, trim($this->source()->convert($source)));
    }

    #[DataProvider('typographyProvider')]
    public function testGlyphModeRemainsTheDefault(string $source, string $glyphs): void
    {
        $this->assertSame($glyphs, trim(CarveConverter::markdown()->convert($source)));
    }

    public function testTheModeOnlyAffectsTypographyNotEscaping(): void
    {
        // Escaping is a separate concern with its own security rationale, and
        // this mode deliberately leaves it alone.
        $this->assertSame('a \* b', trim($this->source()->convert('a * b')));
        $this->assertSame('a \<b\> c', trim($this->source()->convert('a <b> c')));

        // A bare ampersand is NOT escaped, and that is the escaping rule rather
        // than this mode: an entity reference in Markdown text decodes to a
        // character, which a reader escapes again on output, so it can never
        // open a tag. Only an ampersand that would CHANGE the text gets a
        // backslash, which this one would not.
        $this->assertSame('a & b', trim($this->source()->convert('a & b')));
        $this->assertSame('a \&amp; b', trim($this->source()->convert('a &amp; b')));

        // An intraword underscore is NOT escaped: it needs no escape, and
        // escaping it degrades exact-match search in a generated corpus (#417).
        // This mode does not change that either. Pinned alongside the cases
        // above because using it as the example of escaping is exactly what
        // went stale here.
        $this->assertSame('company_id', trim($this->source()->convert('company_id')));
    }

    public function testEscapedSourceStaysEscaped(): void
    {
        $this->assertSame('a\.\.\.b', trim($this->source()->convert('a\.\.\.b')));
    }

    public function testCodeSpansAreUnaffected(): void
    {
        $this->assertSame('`a...b`', trim($this->source()->convert('`a...b`')));
    }

    public function testOtherTargetsAreUnaffected(): void
    {
        $this->assertSame("<p>a…b</p>\n", (new CarveConverter())->convert('a...b'));
        $this->assertSame('a…b', trim(CarveConverter::plainText()->convert('a...b')));
    }

    public function testSourceModeStillRendersMarkdownStructure(): void
    {
        $markdown = trim($this->source()->convert("# Title\n\nA *strong* claim... with a [link](https://example.com).\n"));

        $this->assertStringContainsString('# Title', $markdown);
        $this->assertStringContainsString('**strong**', $markdown);
        $this->assertStringContainsString('[link](https://example.com)', $markdown);
        $this->assertStringContainsString('claim... with', $markdown);
    }

    public function testTheModeIsFluentAndDefaultsToGlyph(): void
    {
        $renderer = new MarkdownRenderer();

        $this->assertSame($renderer, $renderer->setSmartTypography(SmartTypographyMode::Source));
        $this->assertSame('a...b', trim(CarveConverter::create(null, $renderer)->convert('a...b')));

        $renderer->setSmartTypography(SmartTypographyMode::Glyph);
        $this->assertSame('a…b', trim(CarveConverter::create(null, $renderer)->convert('a...b')));
    }
}
