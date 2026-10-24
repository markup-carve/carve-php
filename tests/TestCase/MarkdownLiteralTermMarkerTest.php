<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownLiteralTermMarkerTest extends TestCase
{
    /**
     * Literal term-marker Markdown cases.
     *
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'space after marker' => ["\\:: term\n", "\\:: term\n"],
            'nested paragraph term' => ["- one\n :: term\n :  def\n", "- one\n  \\:: term\n  :  def\n"],
            'tab after marker' => ["::\tterm\n:  d\n", "\\::\tterm\n:  d\n"],
            'bare marker' => ["\\::\n", "::\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testLiteralTermMarkerKeepsItsMeaning(string $source, string $expected): void
    {
        $markdown = (new CarveConverter(renderer: new MarkdownRenderer()))->convert($source);
        self::assertSame($expected, $markdown);

        $back = (new MarkdownToCarve())->convert($markdown);
        $html = new CarveConverter();
        self::assertSame($html->convert($source), $html->convert($back));
    }
}
