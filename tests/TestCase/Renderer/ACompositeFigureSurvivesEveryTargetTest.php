<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10g (markup-carve/carve#1122): the composite figure degrades
 * DETERMINISTICALLY on every non-HTML target, so engines produce one spelling
 * instead of each meeting the graceful-degradation floor its own way.
 */
class ACompositeFigureSurvivesEveryTargetTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "{#fig-x}\n::: figure\n![one](a.png)\n^ (a) One\n\n![two](b.png)\n^ (b) Two\n:::\n^ Figure #: Group caption\n";

    public function testMarkdownEmitsPanelsThenTheBoldGroupCaptionLast(): void
    {
        // T1: each host degraded as usual, each panel caption an emphasized
        // paragraph after its host, the group caption LAST as a bold
        // paragraph, its number resolved.
        $expected = "![one](a.png)\n\n*(a) One*\n\n![two](b.png)\n\n*(b) Two*\n\n**Figure 1: Group caption**\n";

        $this->assertSame($expected, CarveConverter::markdown()->convert(self::SOURCE));
    }

    public function testPlainTextPutsTheGroupCaptionFirst(): void
    {
        // T2: caption-first, because on a caption-less target the group
        // caption is the only line that says what the following blocks are
        // one of; then per panel its caption line, then its host.
        $expected = "Figure 1: Group caption\n\n(a) One\none\n\n(b) Two\ntwo\n";

        $this->assertSame($expected, CarveConverter::plainText()->convert(self::SOURCE));
    }

    public function testTheTerminalFollowsThePlainTextOrderWithItsCaptionStyling(): void
    {
        $ansi = CarveConverter::ansi()->convert(self::SOURCE);
        $stripped = (string)preg_replace('/\x1b\[[0-9;]*m/', '', $ansi);

        $groupAt = strpos($stripped, 'Figure 1: Group caption');
        $panelAt = strpos($stripped, '(a) One');
        $this->assertNotFalse($groupAt);
        $this->assertNotFalse($panelAt);
        $this->assertLessThan($panelAt, $groupAt, 'the group caption line comes first on the terminal target');
    }

    public function testStrayContentIsPreservedInPlaceOnEveryTarget(): void
    {
        // §10g floor: preserved stray content is CONTENT and no target may
        // silently discard it.
        $source = "::: figure\nShot the same day.\n\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n";

        $this->assertStringContainsString('Shot the same day.', CarveConverter::markdown()->convert($source));
        $this->assertStringContainsString('Shot the same day.', CarveConverter::plainText()->convert($source));
    }

    public function testAFigureExposesItsStructuralParts(): void
    {
        $group = (new CarveConverter())->parse(self::SOURCE)->getChildren()[0];
        $this->assertInstanceOf(FigureGroup::class, $group);
        $figure = $group->getChildren()[0];
        $this->assertInstanceOf(Figure::class, $figure);

        $this->assertCount(1, $figure->getTargets());
        $this->assertSame('image', $figure->getTargets()[0]->getType());
        $this->assertSame('caption', $figure->getCaption()?->getType());
        $this->assertCount(1, $figure->getCaptions());
    }

    public function testAnApiBuiltFigureKeepsEveryTargetAndCaption(): void
    {
        $document = new Document();
        $document->appendChild($this->apiBuiltFigure());

        foreach ($this->renderEveryTarget($document) as $renderer => $output) {
            $firstTargetAt = strpos($output, 'target one');
            $secondTargetAt = strpos($output, 'target two');
            $firstCaptionAt = strpos($output, 'caption one');
            $secondCaptionAt = strpos($output, 'caption two');

            $this->assertNotFalse($firstTargetAt, $renderer);
            $this->assertNotFalse($secondTargetAt, $renderer);
            $this->assertNotFalse($firstCaptionAt, $renderer);
            $this->assertNotFalse($secondCaptionAt, $renderer);
            $this->assertLessThan($secondTargetAt, $firstTargetAt, $renderer);
            $this->assertLessThan($firstCaptionAt, $secondTargetAt, $renderer);
            $this->assertLessThan($secondCaptionAt, $firstCaptionAt, $renderer);
        }
    }

    public function testAnApiBuiltFigureGroupKeepsEveryPanelTargetAndCaption(): void
    {
        $group = new FigureGroup();
        $group->appendChild($this->apiBuiltFigure());
        $document = new Document();
        $document->appendChild($group);

        foreach ($this->renderEveryTarget($document) as $renderer => $output) {
            foreach (['target one', 'target two', 'caption one', 'caption two'] as $part) {
                $this->assertStringContainsString($part, $output, $renderer);
            }
        }
    }

    private function apiBuiltFigure(): Figure
    {
        $figure = new Figure();
        $parts = [
            [Caption::class, 'caption one'],
            [Paragraph::class, 'target one'],
            [Paragraph::class, 'target two'],
            [Caption::class, 'caption two'],
        ];
        foreach ($parts as [$class, $content]) {
            $part = new $class();
            $part->appendChild(new Text($content));
            $figure->appendChild($part);
        }

        return $figure;
    }

    /**
     * @return array<class-string, string>
     */
    private function renderEveryTarget(Document $document): array
    {
        $renderers = [
            new HtmlRenderer(),
            new CarveRenderer(),
            new MarkdownRenderer(),
            new PlainTextRenderer(),
            new AnsiRenderer(useColors: false),
        ];
        $output = [];
        foreach ($renderers as $renderer) {
            $output[$renderer::class] = $renderer->render($document);
        }

        return $output;
    }
}
