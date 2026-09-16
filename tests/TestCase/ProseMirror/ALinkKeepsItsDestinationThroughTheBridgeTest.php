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
 * carve-php#2026. The sibling merge compared class and attributes, and a link
 * keeps its destination, title and reference outside both, so two different
 * links matched and the second destination was discarded.
 */
class ALinkKeepsItsDestinationThroughTheBridgeTest extends TestCase
{
    /**
     * @return array<string, array<string>>
     */
    public static function differingProvider(): array
    {
        return [
            'two destinations' => ['[a](u)[b](v)'],
            'two titles' => ['[a](u "t1")[b](u "t2")'],
            'two autolinks' => ['<http://a.b><http://c.d>'],
            'two references' => ["[a][r][b][s]\n\n[r]: u\n[s]: v"],
            'three destinations' => ['[a](u)[b](v)[c](w)'],
        ];
    }

    #[DataProvider('differingProvider')]
    public function testTheSecondLinkSurvives(string $source): void
    {
        $this->assertSame($this->html($source), $this->roundTripHtml($source));
    }

    #[DataProvider('differingProvider')]
    public function testNothingIsDeclaredWhereNothingMerges(string $source): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render((new CarveConverter())->parse($source));

        $this->assertSame([], $renderer->degradedTypes());
    }

    public function testTwoLinksThatAreOneMarkStillMergeAndAreDeclared(): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render((new CarveConverter())->parse('[a](u)[b](u)'));

        $this->assertArrayHasKey('link', $renderer->degradedTypes());
        $this->assertNotSame($this->html('[a](u)[b](u)'), $this->roundTripHtml('[a](u)[b](u)'));
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
