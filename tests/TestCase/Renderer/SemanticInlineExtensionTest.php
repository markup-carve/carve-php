<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemanticInlineExtensionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function semanticTypes(): array
    {
        return [
            'abbr' => ['abbr', 'abbr'],
            'cite' => ['cite', 'cite'],
            'dfn' => ['dfn', 'dfn'],
            'kbd' => ['kbd', 'kbd'],
            'samp' => ['samp', 'samp'],
            'var' => ['var', 'var'],
            'time' => ['time', 'time'],
            'code' => ['code', 'code'],
            'mark' => ['mark', 'mark'],
        ];
    }

    #[DataProvider('semanticTypes')]
    public function testBuiltInSemanticTypeUsesMatchingElement(string $name, string $tag): void
    {
        $html = (new CarveConverter())->convert(':' . $name . '[x]');

        self::assertSame('<p><' . $tag . '>x</' . $tag . '></p>', trim($html));
    }

    public function testAttributesAreHardenedOnTheSemanticElement(): void
    {
        $html = (new CarveConverter())->convert(
            ':time[*noon*]{#clock .local datetime="12:00" onclick="alert(1)"}',
        );

        self::assertSame(
            '<p><time id="clock" class="local" datetime="12:00"><strong>noon</strong></time></p>',
            trim($html),
        );
    }

    public function testUnknownNameKeepsGenericFallback(): void
    {
        $html = (new CarveConverter())->convert(':widget[x]{.control}');

        self::assertSame('<p><span class="ext-widget control">x</span></p>', trim($html));
    }

    public function testPlainAndAnsiRenderOnlyContent(): void
    {
        $source = ':abbr[*HTML*]{title="HyperText Markup Language"}';

        $plain = CarveConverter::create(renderer: new PlainTextRenderer())->convert($source);
        $ansi = CarveConverter::create(renderer: new AnsiRenderer(useColors: false))->convert($source);

        self::assertSame("HTML\n", $plain);
        self::assertSame("HTML\n", $ansi);
    }
}
