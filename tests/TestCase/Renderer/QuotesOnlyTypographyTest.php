<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotesOnlyTypographyTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
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

    #[DataProvider('rendererProvider')]
    public function testQuotesOnlyMode(string $rendererClass): void
    {
        $source = 'He said "hi" and \'yes\'; it\'s fine... a--b c---d -> <= (c) (r) (tm) +-' . "\n";
        $renderer = (new $rendererClass())->setSmartTypography(SmartTypographyMode::QuotesSource);
        $converter = new CarveConverter(renderer: $renderer);
        $this->assertStringContainsString('He said "hi" and \'yes\'; it\'s fine… a–b c—d → ≤ © ® ™ ±', $converter->convert($source));
        $viaOption = new CarveConverter(renderer: new $rendererClass(), smartTypography: SmartTypographyMode::QuotesSource);
        $this->assertSame($converter->convert($source), $viaOption->convert($source));
    }

    #[DataProvider('rendererProvider')]
    public function testLiteralsAndDerivedHeadingLabels(string $rendererClass): void
    {
        $source = "“typed” ‘quotes’ \\\"escaped\\\" \\'single\\' and `a--b \"q\"`\n\n# Don't \"guess\"... a--b\n\n</#Don-t-guess-a-b>\n";
        $document = (new BlockParser())->parse($source);
        $renderer = (new $rendererClass())->setSmartTypography(SmartTypographyMode::QuotesSource);
        $output = $renderer->render($document);
        $this->assertStringContainsString('“typed” ‘quotes’ "escaped" \'single\'', str_replace('\\', '', $output));
        $this->assertStringContainsString('a--b "q"', $output);
        $this->assertSame(2, substr_count($output, 'Don\'t "guess"… a–b'));
        $this->assertStringContainsString('id="Don-t-guess-a-b"', (new HtmlRenderer())->render($document));
        $this->assertStringContainsString('id="Don-t-guess-a-b"', (new HtmlRenderer())->setSmartTypography(SmartTypographyMode::QuotesSource)->render($document));
    }

    public function testAccessibleTaskNamesAndTabLabels(): void
    {
        $converter = new CarveConverter(smartTypography: SmartTypographyMode::QuotesSource);
        $this->assertStringContainsString('aria-label="Don&apos;t &quot;guess&quot;… a–b"', $converter->convert("- [ ] Don't \"guess\"... a--b\n"));
        $converter->addExtension(new TabsExtension());
        $input = ":::: tabs\n::: tab\n# Don't \"guess\"... a--b\n\nBody.\n:::\n::::\n";
        $this->assertStringContainsString(">Don't \"guess\"… a–b</label>", $converter->convert($input));
    }

    public function testIndexBacklinkNamesAndTaskSymbols(): void
    {
        $converter = new CarveConverter(smartTypography: SmartTypographyMode::QuotesSource);
        $converter->addExtension(new IndexExtension());
        $output = $converter->convert(":index[\"quoted\" term]\n\n::: index\n:::\n");
        $this->assertStringContainsString('&quot;quoted&quot; term', $output);
        $this->assertStringNotContainsString('“', $output);
        $this->assertStringNotContainsString('”', $output);
        foreach ([SmartTypographyMode::Glyph, SmartTypographyMode::QuotesSource] as $mode) {
            $output = (new CarveConverter(smartTypography: $mode))->convert('- [ ] Ship :rocket: build');
            $this->assertStringContainsString('aria-label="Ship build"', $output);
        }
    }
}
