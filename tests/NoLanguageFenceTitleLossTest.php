<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class NoLanguageFenceTitleLossTest extends TestCase
{
    public function testNoLanguageTitleProducesOneMarkdownLoss(): void
    {
        $source = "``` \"T\"\nx\n```\n";
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertStringStartsWith("```\nx\n```", $report->value);
        self::assertSame(['fence-title-dropped' => 1], $report->lossCounts);
        self::assertSame(1, $report->totalLosses);
        self::assertSame('markdown', $report->losses[0]['target']);
        self::assertSame('block', $report->losses[0]['nodeType']);
        self::assertSame('Dropped a code fence title without a language in Markdown', $report->losses[0]['message']);
    }

    public function testOverridingAttributeTitleIsReportedOnce(): void
    {
        $source = "{title=\"A\"}\n``` \"T\"\nx\n```\n";
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertSame(1, $report->lossCounts['fence-title-dropped'] ?? 0);
    }

    public function testAstHeaderWithoutAttributeIsAlsoReported(): void
    {
        $document = new Document();
        $document->appendChild(new CodeBlock("x\n", null, null, 'T'));
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->renderWithReport($document);
        self::assertSame(['fence-title-dropped' => 1], $report->lossCounts);
    }

    public function testLanguageAndRoundtripPreserveTitles(): void
    {
        $source = "```js \"T\"\nx\n```\n";
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertSame(0, $report->totalLosses);
        $roundtrip = (new CarveConverter(renderer: (new MarkdownRenderer())->setRoundTripMode(true)))
            ->convertWithReport("``` \"T\"\nx\n```\n");
        self::assertSame(0, $roundtrip->totalLosses);
    }
}
