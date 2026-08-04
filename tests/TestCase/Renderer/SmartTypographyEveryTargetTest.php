<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The smart-typography switch has to mean the same thing on every presentation
 * target. HTML and Markdown carried it; plain text and ANSI emitted the glyph
 * whatever the caller asked for, so `--smart-typography source` was accepted
 * and silently ignored on two of the four (carve#560).
 *
 * A documented option that produces no error and no effect is worse than a
 * missing one: the output looks configured and is not.
 */
class SmartTypographyEveryTargetTest extends TestCase
{
    /**
     * @var string
     */
    protected const SOURCE = 'He said "hello" -- a--b (c)';

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function rendererProvider(): array
    {
        return [
            'html' => [HtmlRenderer::class],
            'markdown' => [MarkdownRenderer::class],
            'plain' => [PlainTextRenderer::class],
            'ansi' => [AnsiRenderer::class],
        ];
    }

    /**
     * @param class-string $rendererClass
     */
    #[DataProvider('rendererProvider')]
    public function testSourceModeEmitsWhatTheAuthorTyped(string $rendererClass): void
    {
        $document = (new BlockParser())->parse(self::SOURCE);

        $renderer = new $rendererClass();
        $glyph = $renderer->render($document);

        $renderer = new $rendererClass();
        $renderer->setSmartTypography(SmartTypographyMode::Source);
        $source = $renderer->render($document);

        $this->assertNotSame($glyph, $source, 'the switch changed nothing');
        $this->assertStringContainsString('"hello"', $source);
        $this->assertStringContainsString('a--b', $source);
        $this->assertStringContainsString('(c)', $source);
    }

    /**
     * @param class-string $rendererClass
     */
    #[DataProvider('rendererProvider')]
    public function testGlyphModeIsTheDefault(string $rendererClass): void
    {
        $document = (new BlockParser())->parse(self::SOURCE);

        $renderer = new $rendererClass();
        $default = $renderer->render($document);

        $renderer = new $rendererClass();
        $renderer->setSmartTypography(SmartTypographyMode::Glyph);
        $explicit = $renderer->render($document);

        $this->assertSame($default, $explicit);
        $this->assertStringContainsString('“hello”', $default);
    }

    public function testNoTargetLeaksAGlyphInSourceMode(): void
    {
        $document = (new BlockParser())->parse(self::SOURCE);
        $leaking = [];

        foreach (self::rendererProvider() as $name => [$rendererClass]) {
            $renderer = new $rendererClass();
            $renderer->setSmartTypography(SmartTypographyMode::Source);
            if (preg_match('/[\x{201C}\x{201D}\x{2013}\x{00A9}]/u', $renderer->render($document)) === 1) {
                $leaking[] = $name;
            }
        }

        $this->assertSame([], $leaking);
    }

    public function testTheConverterHonorsItEndToEnd(): void
    {
        $converter = new CarveConverter();
        $renderer = $converter->getRenderer();
        $this->assertInstanceOf(HtmlRenderer::class, $renderer);
        $renderer->setSmartTypography(SmartTypographyMode::Source);

        $this->assertStringContainsString('"hello"', $converter->convert(self::SOURCE));
    }
}
