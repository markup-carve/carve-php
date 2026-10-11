<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RenderersPreserveExhaustedPrivateUsePoolTest extends TestCase
{
    private static function privateUseText(): string
    {
        $text = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $text .= mb_chr($code, 'UTF-8');
        }

        return $text . "\u{F0000}\u{F0001}\u{F0002}\u{F0003}";
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function contexts(): iterable
    {
        $text = self::privateUseText() . "\u{E009}m0x0\u{E00A}\u{E009}m1x3\u{E00A}";
        foreach (['html', 'plain', 'ansi'] as $target) {
            foreach (
                [
                    'code' => "```\n{$text}\n```\n",
                    'definition-before' => "*[AB]: {$text}\n\nAB\n",
                    'definition-after' => "AB\n\n*[AB]: {$text}\n",
                    'unused-definition' => "*[AB]: {$text}\n\nother\n",
                ] as $name => $source
            ) {
                if ($target !== 'html' || $name !== 'unused-definition') {
                    yield "{$target}-{$name}" => [$target, $source];
                }
            }
        }
    }

    #[DataProvider('contexts')]
    public function testFullPoolAndAuthoredFallbackTokensRemainLiteral(string $target, string $source): void
    {
        $converter = match ($target) {
            'html' => new CarveConverter(),
            'plain' => CarveConverter::plainText(),
            default => CarveConverter::ansi(),
        };
        $result = $converter->convertWithReport($source, strictLosses: true);
        self::assertSame(1, substr_count($result->value, self::privateUseText()));
        self::assertStringContainsString("\u{E009}m0x0\u{E00A}\u{E009}m1x3\u{E00A}", $result->value);
        self::assertSame(0, $result->totalLosses);
        self::assertSame([], $result->losses);
        self::assertFalse($result->truncated);
        self::assertSame($result->value, $converter->convert($source));
        self::assertSame(0, $converter->convertWithReport("*[A]: alpha\n\nA\n")->totalLosses);
        self::assertSame($result->value, $converter->convertWithReport($source)->value);
    }

    public function testStrippedControlsCannotForgeRenderingMarkers(): void
    {
        $text = self::privateUseText();
        $token = "\u{E009}m0\u{202E}x0\u{E00A}";
        $forged = $token . '0' . $token;
        $source = "*[AB]: alpha\n\nAB\n\n```\n{$text}\n```\n\n{$forged}\n";
        foreach ([new CarveConverter(), CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            $result = $converter->convertWithReport($source, strictLosses: true);
            self::assertStringContainsString(str_replace("\u{202E}", '', $forged), $result->value);
            self::assertSame(1, substr_count($result->value, $text));
            self::assertSame(0, $result->totalLosses);
        }
    }

    public function testAdjacentSymbolReplacementsCannotForgeFootnotePlacement(): void
    {
        $text = self::privateUseText();
        $renderer = new HtmlRenderer(symbols: [
            'pool' => $text,
            'left' => "\u{E009}m0x",
            'right' => "2\u{E00A}",
        ]);
        $result = (new CarveConverter(renderer: $renderer))->convertWithReport(':pool: :left::right:', strictLosses: true);
        self::assertStringContainsString("\u{E009}m0x2\u{E00A}", $result->value);
        self::assertStringNotContainsString('class="footnotes"', $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testConfiguredFullPoolBesideRealBreaksAndFragments(): void
    {
        $text = self::privateUseText();
        $renderer = new HtmlRenderer(symbols: ['rocket' => $text]);
        $renderer->setSoftBreakMode(SoftBreakMode::Break);
        $converter = new CarveConverter(renderer: $renderer);
        self::assertSame("<p>{$text}<br>\nnext</p>\n", $converter->convert(":rocket:\nnext"));
        $paragraph = (new BlockParser())->parse(':rocket:')->getChildren()[0];
        self::assertSame("<p>{$text}</p>\n", $renderer->renderNodeFragment($paragraph));
        self::assertSame($text, $renderer->renderInlineNodesFragment($paragraph->getChildren()));
    }
}
