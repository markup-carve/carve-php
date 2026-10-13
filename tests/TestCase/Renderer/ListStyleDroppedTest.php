<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListStyleDroppedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function styles(): array
    {
        return [
            'lower alpha' => ["a. one\na. two\n", "1. one\n2. two\n", 'lower-alpha'],
            'upper alpha' => ["A. one\n", "1. one\n", 'upper-alpha'],
            'lower roman' => ["i. one\n", "1. one\n", 'lower-roman'],
            'upper roman' => ["I. one\n", "1. one\n", 'upper-roman'],
            'roman start' => ["iv) x\nv) y\n", "4) x\n5) y\n", 'lower-roman'],
        ];
    }

    #[DataProvider('styles')]
    public function testStyledListReportsLossOnlyWithoutCarrier(string $source, string $expected, string $style): void
    {
        $plain = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertSame($expected, $plain->value);
        self::assertSame(1, $plain->totalLosses);
        self::assertSame('list-style-dropped', $plain->losses[0]['code']);
        self::assertSame($style, $plain->losses[0]['style']);
        self::assertSame('block', $plain->losses[0]['nodeType']);
        self::assertArrayHasKey('pos', $plain->losses[0]);

        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $carried = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
        self::assertStringContainsString('<!-- carve:', $carried->value);
        self::assertSame([], $carried->losses);
        $imported = (new MarkdownToCarve())->convert($carried->value);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($imported));
    }

    public function testDecimalListHasNoStyleLoss(): void
    {
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("1. one\n2. two\n");
        self::assertSame([], $report->losses);
    }

    public function testListsReportInDocumentOrder(): void
    {
        $source = "a. x\n\n> I. quoted\n\n- y\n\n  i. nested\n";
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertSame(['lower-alpha', 'upper-roman', 'lower-roman'], array_column($report->losses, 'style'));
    }

    public function testAmbiguousListStyleCarriesTwoMarkers(): void
    {
        $source = "i. one\nj. two\n";
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $markdown = (new CarveConverter(renderer: $renderer))->convert($source);
        self::assertStringContainsString("<!-- carve: i. j. -->\n9. one", $markdown);
        $imported = (new MarkdownToCarve())->convert($markdown);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($imported));
    }

    public function testListStyleMarkerNeedsAnAdjacentOrderedList(): void
    {
        $markdown = "<!-- carve: a. -->\n\n1. one\n2. two\n";
        $importer = new MarkdownToCarve();
        $report = $importer->convertWithFidelityReport($markdown);
        self::assertTrue($importer->hadDamagedCarrierMarkers());
        self::assertContains('carrier-markers-damaged', array_column($report->diagnostics, 'code'));
        self::assertStringContainsString('1. one', $report->value);
    }

    public function testStyleMarkerKeepsQuoteAndAttributeHosts(): void
    {
        foreach (["> a. one\n> b. two\n", "{.c}\na. one\nb. two\n"] as $source) {
            $renderer = new MarkdownRenderer();
            $renderer->setRoundTripMode(true);
            $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
            self::assertSame([], $report->losses);
            $imported = (new MarkdownToCarve())->convert($report->value);
            $converter = new CarveConverter();
            self::assertSame($converter->convert($source), $converter->convert($imported));
        }
    }

    public function testStyleMarkerInsideAnOpeningListItemIsRejected(): void
    {
        $markdown = "- <!-- carve: a. -->\n  1. one\n  2. two\n- next\n";
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        self::assertContains('carrier-marker-dropped', array_column($report->diagnostics, 'code'));
        self::assertContains('carrier-markers-damaged', array_column($report->diagnostics, 'code'));
        self::assertStringContainsString('- next', $report->value);
    }

    public function testEmptyFootnoteCannotOwnStyleMarker(): void
    {
        $markdown = "[^n]:\n    <!-- carve: a. -->\n    1. one\n\nref [^n]\n";
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        self::assertContains('carrier-markers-damaged', array_column($report->diagnostics, 'code'));
        self::assertStringContainsString('[^n]: {empty}', $report->value);
    }

    public function testStyledListInFootnoteBodyCarriesAfterParagraph(): void
    {
        $source = "[^n]: text\n\n    a. one\n    b. two\nafter [^n]\n";
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
        self::assertSame([], $report->losses);
        self::assertStringContainsString('<!-- carve: a. -->', $report->value);
        $imported = (new MarkdownToCarve())->convert($report->value);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($imported));
    }

    public function testLaterStyleMarkerRestoresItsOwnList(): void
    {
        $markdown = "<!-- carve: a. -->\n1. one\n\npara\n\n<!-- carve: c. -->\n3. three\n";
        $imported = (new MarkdownToCarve())->convert($markdown);
        self::assertStringContainsString('a. one', $imported);
        self::assertStringContainsString('c. three', $imported);
    }

    public function testStyleMarkerOnTaskOrNestedItemLineIsRejected(): void
    {
        foreach (["- [ ] <!-- carve: a. -->\n  1. one\n- next\n", "- x\n  - <!-- carve: a. -->\n    1. one\n"] as $markdown) {
            $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
            self::assertContains('carrier-marker-dropped', array_column($report->diagnostics, 'code'));
        }
    }

    public function testFootnoteLookingTextInCodeFenceDoesNotDamageCarrier(): void
    {
        $markdown = "<!-- carve: a. -->\n1. one\n\n```\n[^n]:\n    <!-- carve: a. -->\n    1. x\n```\n";
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        self::assertFalse($importer->hadDamagedCarrierMarkers());
        self::assertStringContainsString('a. one', $imported);
    }

    public function testMismatchedStyleMarkerPairDrops(): void
    {
        $markdown = "<!-- carve: a. b) -->\n1. one\n2. two\n";
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        self::assertContains('carrier-marker-dropped', array_column($report->diagnostics, 'code'));
        self::assertStringContainsString('1. one', $report->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markerBoundaries(): array
    {
        return [
            'paragraph' => ["para\n<!-- carve: a. -->\n1. one\n2. two\n", "para\n\na. one\nb. two\n"],
            'quote' => ["> q\n<!-- carve: a. -->\n1. one\n", "> q\n\na. one\n"],
            'adjacent lists' => ["<!-- carve: a. -->\n1. one\n<!-- carve: b. -->\n2. two\n", "a. one\n\nb. two\n"],
            'table' => ["| a |\n|---|\n| b |\n<!-- carve: a. -->\n1. one\n", "|= a |\n| b |\n\na. one\n"],
            'nested bullet' => ["- outer\n  <!-- carve: a. -->\n  1. one\n  2. two\n- next\n", "- outer\n  a. one\n  b. two\n- next\n"],
            'nested ordered' => ["1. outer\n   <!-- carve: a. -->\n   1. one\n2. next\n", "1. outer\n   a. one\n2. next\n"],
            'nested noninitial start' => ["- outer\n  <!-- carve: a. -->\n  2. two\n  3. three\n- next\n", "- outer\n  b. two\n  c. three\n- next\n"],
            'nested noninitial loose' => ["- outer\n  <!-- carve: a. -->\n  2. two\n  3. three\n\n- next\n", "- outer\n  b. two\n  c. three\n\n- next\n"],
        ];
    }

    #[DataProvider('markerBoundaries')]
    public function testStyleMarkerStartsItsOwnList(string $markdown, string $expected): void
    {
        $report = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        self::assertSame($expected, $report->value);
        self::assertNotContains('carrier-markers-damaged', array_column($report->diagnostics, 'code'));
    }
}
