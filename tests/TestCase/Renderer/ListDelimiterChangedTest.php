<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListDelimiterChangedTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function roundTrip(): array
    {
        return ['plain export' => [false], 'roundtrip export' => [true]];
    }

    #[DataProvider('roundTrip')]
    public function testAdjacentListsReportTheChangedDelimiter(bool $roundTrip): void
    {
        $source = <<<'CARVE'
a. one

1. two

CARVE;
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode($roundTrip);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);

        self::assertStringContainsString("1) two\n", $report->value);
        $losses = array_values(array_filter($report->losses, static fn (array $loss): bool => $loss['code'] === 'list-delimiter-changed'));
        self::assertCount(1, $losses);
        self::assertSame('markdown', $losses[0]['target']);
        self::assertSame('block', $losses[0]['nodeType']);
        self::assertSame('.', $losses[0]['delim']);
        self::assertSame(')', $losses[0]['emittedDelim']);
        self::assertArrayNotHasKey('format', $losses[0]);
        self::assertArrayHasKey('pos', $losses[0]);
        self::assertSame(1, $report->lossCounts['list-delimiter-changed'] ?? null);
    }

    public function testAParenthesisListSwitchesToAPeriodUnlessItCarriesAStyle(): void
    {
        $source = <<<'CARVE'
1) one

i) two

CARVE;
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);

        self::assertStringEndsWith("1) one\n\n<!-- carve: i) -->\n1) two\n", $report->value);
        self::assertSame([], $report->losses);

        $plain = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("a) one\n\n1) two\n");
        self::assertSame("1) one\n\n1. two\n", $plain->value);
        $losses = array_values(array_filter($plain->losses, static fn (array $loss): bool => $loss['code'] === 'list-delimiter-changed'));
        self::assertCount(1, $losses);
        self::assertSame(')', $losses[0]['delim']);
        self::assertSame('.', $losses[0]['emittedDelim']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptDelimiters(): array
    {
        return [
            'single list' => ["1. one\n2. two\n"],
            'lists apart' => ["1. one\n\npara\n\n1. two\n"],
            'bullet then ordered' => ["- one\n\n1. two\n"],
            'adjacent bullets' => ["- one\n\n* two\n"],
        ];
    }

    #[DataProvider('keptDelimiters')]
    public function testAKeptDelimiterReportsNothing(string $source): void
    {
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);

        self::assertSame([], $report->losses);
    }

    public function testOnlyMarkdownReportsTheCode(): void
    {
        $report = (new CarveConverter(renderer: new PlainTextRenderer()))->convertWithReport("1. one\n\n1. two\n");

        self::assertSame([], $report->losses);
    }
}
