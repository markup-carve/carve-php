<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
use MarkupCarve\Carve\Converter\HtmlImportLimitException;
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
            $actual = $result->report()['diagnostics'];
            $this->assertSame($expected, $result->value, basename($fixture));
            $this->assertSame(
                array_column($expectedReport['diagnostics'], 'code'),
                array_column($actual, 'code'),
                basename($fixture),
            );

            // The fixtures state a `message` and a `severity` for every
            // diagnostic too, and reading back only the codes left both
            // unchecked: the event-handler message could be reworded to
            // anything, or emptied, and the whole suite stayed green.
            //
            // `path` is checked by `testTheFixturePathShapeDivergesFromTheSharedContract`
            // instead, because this engine does not currently produce the
            // fixture's shape and a loop that skipped the field in silence
            // would be the same unchecked column again.
            foreach ($expectedReport['diagnostics'] as $index => $diagnostic) {
                $where = basename($fixture) . ' #' . $index;
                $this->assertArrayHasKey($index, $actual, $where);
                foreach (['message', 'severity'] as $field) {
                    $this->assertSame($diagnostic[$field], $actual[$index][$field] ?? null, $where . ' ' . $field);
                }
            }
        }
    }

    /**
     * DIVERGENCE, pinned rather than skipped.
     *
     * One shared fixture states a `path`, and this engine answers it with a
     * different string in two independent ways:
     *
     * - a `/div[1]` prefix nobody else has. A fragment is wrapped in a `<div>`
     *   before parsing, so the wrapper the importer invented for itself is
     *   numbered into every path it reports.
     * - a different index basis. The fixture counts a child among ALL of its
     *   parent's child nodes, text included; this engine counts among element
     *   children only, so the eleventh node is the sixth element.
     *
     * Neither is a difference the `code`-only comparison could ever have shown,
     * and both are decisions with an owner: whether the engines agree on
     * `path` at all is a maintainer's call. This states what this engine
     * produces so the call can be checked against it, and so a change to either
     * rule lands here rather than in a consumer diffing reports across engines.
     */
    public function testTheFixturePathShapeDivergesFromTheSharedContract(): void
    {
        $fixture = dirname(__DIR__, 2) . '/spec/tests/html-import/semantic-span-attributes';
        $html = file_get_contents($fixture . '/input.html');
        $reportJson = file_get_contents($fixture . '/expected.report.json');
        $this->assertNotFalse($html);
        $this->assertNotFalse($reportJson);
        $expectedReport = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);

        $actual = (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'];

        $this->assertSame('/p[1]/kbd[11]', $expectedReport['diagnostics'][0]['path']);
        $this->assertSame('/div[1]/p[1]/kbd[6]', $actual[0]['path']);
    }

    public function testDiagnosticsLimitIsTyped(): void
    {
        $this->expectException(HtmlImportLimitException::class);
        (new HtmlToCarve(maxDiagnostics: 0))->convertWithReport('<p onclick="x()">x</p>');
    }
}
