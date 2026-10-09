<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use Generator;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RawReferenceLabelBracketsTest extends TestCase
{
    #[DataProvider('referenceSources')]
    public function testLabelTextPairsWithVerbatimReferenceSource(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testAReferenceFollowedByParenthesesNeedsNoExtraEscape(): void
    {
        foreach (["see [x][r](note)\n", "see [x][r](note)\n\n[r]: /v\n"] as $source) {
            $this->assertSame($source, CarveConverter::toCarve($source));
        }
    }

    public function testReusingARendererDoesNotReuseFixedReferenceSites(): void
    {
        $renderer = new CarveRenderer();
        $converter = new CarveConverter();
        foreach (range(1, 50) as $_) {
            foreach (["[t[x][r[n]](/u)\n", "[t \\[ x](/u)\n"] as $source) {
                $this->assertSame(CarveConverter::toCarve($source), $renderer->render($converter->parse($source)));
            }
        }
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function referenceSources(): Generator
    {
        $sources = json_decode(file_get_contents(__DIR__ . '/../../fixtures/raw-reference-label-brackets.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($sources as $index => $source) {
            yield 'case ' . $index => [$source];
        }
    }
}
