<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class RawDocumentContainerReportTest extends TestCase
{
    public function testBodyAndItsChildrenReportLiveHandlersAsPreserved(): void
    {
        $html = '<!doctype html><html><body onclick="x()"><p onclick="y()">a</p></body></html>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertStringContainsString('<body onclick="x()"><p onclick="y()">a</p></body>', $result->value);
        $this->assertSame(
            [
                ['attribute-preserved', '/html[1]/body[1]'],
                ['raw-preserved', '/html[1]/body[1]'],
                ['attribute-preserved', '/p[1]'],
            ],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
        $this->assertStringContainsString(
            'inside the raw HTML <body> is kept as',
            $result->report()['diagnostics'][2]['message'],
        );
    }

    public function testTwinsInsideRawBodyAreBothPreserved(): void
    {
        $html = '<!doctype html><html><body>'
            . '<li onclick="x()">t</li><ul><li onclick="x()">t</li></ul>'
            . '</body></html>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame(
            [
                ['raw-preserved', '/html[1]/body[1]'],
                ['attribute-preserved', '/li[1]'],
                ['attribute-preserved', '/ul[2]/li[1]'],
            ],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testHeadAttributesArePreservedWithTheRawHead(): void
    {
        $html = '<!doctype html><html><head onclick="x()"><title>x</title></head><body><p>a</p></body></html>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame(
            [
                ['attribute-preserved', '/html[1]/head[1]'],
                ['raw-preserved', '/html[1]/head[1]'],
                ['raw-preserved', '/html[1]/body[2]'],
            ],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testBodyOnlyDocumentUsesTheSameRawVerdict(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<body onclick="x()"><p onclick="y()">a</p></body>');

        $this->assertSame(
            [
                ['attribute-preserved', '/body[1]'],
                ['raw-preserved', '/body[1]'],
                ['attribute-preserved', '/p[1]'],
            ],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testRawBodyWithoutAttributesStillReportsTheRawContainer(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport('<body><p>a</p></body>');

        $this->assertSame(
            [['raw-preserved', '/body[1]']],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testSafeAndSemanticModesStillReportDroppedHandlers(): void
    {
        $html = '<!doctype html><html><body onclick="x()"><p onclick="y()">a</p></body></html>';
        foreach (['safe', 'semantic'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame(
                ['attribute-dropped', 'attribute-dropped'],
                array_column($result->report()['diagnostics'], 'code'),
            );
        }
    }
}
