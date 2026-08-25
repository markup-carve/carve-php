<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use PHPUnit\Framework\TestCase;

class RenderLossReportTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "`one`{=latex} and `two`{=typst}\n";

    public function testHtmlReportsActualDropsWithoutChangingOutput(): void
    {
        $converter = CarveConverter::create();
        $result = $converter->convertWithReport(self::SOURCE);

        self::assertSame($converter->convert(self::SOURCE), $result->value);
        self::assertSame(2, $result->totalLosses);
        self::assertFalse($result->truncated);
        self::assertSame(['latex', 'typst'], array_column($result->losses, 'format'));
        self::assertSame(['inline', 'inline'], array_column($result->losses, 'nodeType'));
        self::assertSame(1, $result->losses[0]['pos']['startLine']);
    }

    public function testMatchingAndVisibleFallbacksAreNotLosses(): void
    {
        self::assertSame(0, CarveConverter::create()->convertWithReport('`<b>x</b>`{=html}')->totalLosses);
        self::assertSame(0, CarveConverter::markdown()->convertWithReport('`<b>x</b>`{=html}')->totalLosses);

        $block = "``` =latex\nx\n```\n";
        self::assertSame(0, CarveConverter::ansi()->convertWithReport($block)->totalLosses);
        self::assertSame(1, CarveConverter::plainText()->convertWithReport($block)->totalLosses);
        self::assertSame(0, CarveConverter::carve()->convertWithReport($block)->totalLosses);

        $markdown = CarveConverter::markdown()->convertWithReport(self::SOURCE);
        self::assertSame(2, $markdown->totalLosses);
        $ansi = CarveConverter::ansi()->convertWithReport(self::SOURCE);
        self::assertSame(2, $ansi->totalLosses);
    }

    public function testStrictErrorCarriesTheCompleteBoundedReport(): void
    {
        try {
            CarveConverter::create()->convertWithReport(self::SOURCE, true, 1);
            self::fail('Strict rendering should refuse the loss.');
        } catch (RenderLossException $exception) {
            self::assertSame(2, $exception->totalLosses);
            self::assertCount(1, $exception->losses);
            self::assertTrue($exception->truncated);
        }
    }

    public function testResultArrayUsesTheSharedWireNames(): void
    {
        $result = CarveConverter::create()->convertWithReport(self::SOURCE);

        self::assertSame(
            ['value', 'losses', 'totalLosses', 'truncated'],
            array_keys($result->toArray()),
        );
    }

    public function testNegativeReportBoundIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CarveConverter::create()->convertWithReport(self::SOURCE, maxRenderLosses: -1);
    }
}
