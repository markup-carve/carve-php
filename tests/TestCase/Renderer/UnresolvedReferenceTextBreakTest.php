<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnresolvedReferenceTextBreakTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function references(): array
    {
        return [
            'link' => ["[x][a\nb]\n", "[x][a b]\n"],
            'image' => ["![x\ny][]\n", "![x y][]\n"],
            'marked image' => ["![*x*][a\nb]\n", "![*x*][a b]\n"],
        ];
    }

    #[DataProvider('references')]
    public function testSourceBreakIsOneSpaceInBothTextTargets(string $source, string $expected): void
    {
        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            self::assertSame($expected, CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testConfiguredNewlineModeKeepsTheSourceBreak(): void
    {
        $source = "[x][a\nb]\n";
        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            $renderer->setSoftBreakMode(SoftBreakMode::Newline);
            self::assertSame($source, CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testHardBreaksInFailedReferencesStayOnTwoLines(): void
    {
        foreach (
            [
                "::: |\n[a\nb][missing]\n:::\n" => "[a\nb][missing]\n",
                "[a\\\nb][missing]\n" => "[a\\\nb][missing]\n",
                "[a\nb\\\nc][missing]\n" => "[a b\\\nc][missing]\n",
            ] as $source => $expected
        ) {
            foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
                self::assertSame($expected, CarveConverter::create(renderer: $renderer)->convert($source));
            }
        }
    }
}
