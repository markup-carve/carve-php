<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class HtmlImportReportTest extends TestCase
{
    public function testReportMakesLossVisible(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p onclick="evil()">safe<script>alert(1)</script><span title="lost"> text</span></p>',
        );

        $this->assertSame('safe[ text]{title=lost}', trim($result->value));
        $this->assertSame('safe', $result->mode);
        $this->assertSame(
            ['attribute-dropped', 'element-dropped'],
            array_column($result->report()['diagnostics'], 'code'),
        );
    }

    public function testTrustedConstructorSelectsRoundtripMode(): void
    {
        $result = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport('<p>x</p>');
        $this->assertSame('roundtrip', $result->mode);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HtmlToCarve(importMode: 'unknown');
    }

    public function testSharedContractFixtures(): void
    {
        $root = dirname(__DIR__, 2) . '/spec/tests/html-import';
        $fixtures = glob($root . '/*', GLOB_ONLYDIR);
        $this->assertNotEmpty($fixtures);
        foreach ($fixtures as $fixture) {
            $html = file_get_contents($fixture . '/input.html');
            $expected = file_get_contents($fixture . '/expected.crv');
            $reportJson = file_get_contents($fixture . '/expected.report.json');
            $this->assertNotFalse($html);
            $this->assertNotFalse($expected);
            $this->assertNotFalse($reportJson);
            $expectedReport = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);

            $result = (new HtmlToCarve())->convertWithReport($html);
            $this->assertSame($expected, $result->value, basename($fixture));
            $this->assertSame(
                array_column($expectedReport['diagnostics'], 'code'),
                array_column($result->report()['diagnostics'], 'code'),
                basename($fixture),
            );
        }
    }
}
