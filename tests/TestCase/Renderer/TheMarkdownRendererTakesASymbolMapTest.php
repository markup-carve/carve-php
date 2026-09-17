<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class TheMarkdownRendererTakesASymbolMapTest extends TestCase
{
    private const CASE = __DIR__ . '/../../spec/tests/corpus-optional/30-symbol-map-markdown';

    /**
     * @var array<string, string>
     */
    private const SYMBOLS = ['rocket' => '🚀', 'tada' => '🎉', '+1' => '👍', 'UPPER' => '⬆️'];

    public function testTheOptionalCorpusCaseRendersWithTheMap(): void
    {
        $renderer = new MarkdownRenderer(symbols: self::SYMBOLS);
        $source = (string)file_get_contents(self::CASE . '.crv');

        $this->assertSame(
            (string)file_get_contents(self::CASE . '.md'),
            CarveConverter::create(renderer: $renderer)->convert($source),
        );
    }

    public function testAMappedSymbolKeepsItsSourceSpelling(): void
    {
        $renderer = new MarkdownRenderer(symbols: ['rocket' => '<b>R</b>']);

        $this->assertSame("go :rocket:\n", CarveConverter::create(renderer: $renderer)->convert('go :rocket:'));
    }

    public function testTheMapIsReadBack(): void
    {
        $this->assertSame(self::SYMBOLS, (new MarkdownRenderer(symbols: self::SYMBOLS))->getSymbols());
        $this->assertSame([], (new MarkdownRenderer())->getSymbols());
    }
}
