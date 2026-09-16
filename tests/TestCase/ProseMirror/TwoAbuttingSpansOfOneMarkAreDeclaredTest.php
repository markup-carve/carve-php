<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mark set carries no boundary, so two abutting spans of one mark come back
 * as a single run. The renderer declares it rather than letting the document
 * into the fully-covered population (markup-carve/carve-php#2014).
 */
class TwoAbuttingSpansOfOneMarkAreDeclaredTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function mergedProvider(): array
    {
        return [
            'two italics' => ['{/x/}{/y/}', 'emphasis'],
            'corpus 463' => ['~{/x/}{/y~/}', 'emphasis'],
            'two strongs' => ['{*x*}{*y*}', 'strong'],
            'two spans of one class' => ['[a]{.p}[b]{.p}', 'span'],
            'two highlights' => ['{=a=}{=b=}', 'highlight'],
            'a link at the boundary' => ['{/[a](u)/}{/y/}', 'emphasis'],
            'nested inside one mark' => ['{/{*a*}{*b*}/}', 'strong'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptApartProvider(): array
    {
        return [
            'different marks' => ['{/x/}{*y*}'],
            'text between them' => ['{/x/}q{/y/}'],
            'different attributes' => ['{/x/}{/y/}{.a}'],
            'one span the parser split' => ['/x_y/'],
            'two code spans, a content-bearing mark' => ['`a`{.q}`b`{.q}'],
        ];
    }

    #[DataProvider('mergedProvider')]
    public function testDeclaresTheLostBoundary(string $source, string $type): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render((new CarveConverter())->parse($source));

        $this->assertArrayHasKey($type, $renderer->degradedTypes());
    }

    #[DataProvider('mergedProvider')]
    public function testTheBoundaryIsActuallyLost(string $source, string $type): void
    {
        $this->assertNotSame($this->html($source), $this->roundTripHtml($source));
    }

    #[DataProvider('keptApartProvider')]
    public function testDeclaresNothingWhereTheBoundarySurvives(string $source): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render((new CarveConverter())->parse($source));

        $this->assertSame([], $renderer->degradedTypes());
    }

    #[DataProvider('keptApartProvider')]
    public function testTheBoundarySurvives(string $source): void
    {
        $this->assertSame($this->html($source), $this->roundTripHtml($source));
    }

    protected function html(string $source): string
    {
        return (new HtmlRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function roundTripHtml(string $source): string
    {
        $document = (new CarveConverter())->parse($source);
        $wire = (new ProseMirrorRenderer())->render($document);

        return (new HtmlRenderer())->render((new ProseMirrorToCarve())->convert($wire));
    }
}
