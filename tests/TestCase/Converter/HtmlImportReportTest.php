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

            // The fixtures state a `message`, a `severity` and - for one of
            // them - a `path` for every diagnostic too, and reading back only
            // the codes left all three unchecked: the event-handler message
            // could be reworded to anything, or emptied, and the whole suite
            // stayed green.
            //
            // `path` is the field that mattered most here. It went unread while
            // three engines each spelled it their own way, which is exactly how
            // the disagreement survived (`markup-carve/carve#1257`); an
            // unchecked column is what lets the next one start.
            foreach ($expectedReport['diagnostics'] as $index => $diagnostic) {
                $where = basename($fixture) . ' #' . $index;
                $this->assertArrayHasKey($index, $actual, $where);
                foreach (['message', 'severity', 'path'] as $field) {
                    if (!array_key_exists($field, $diagnostic)) {
                        continue;
                    }
                    $this->assertSame($diagnostic[$field], $actual[$index][$field] ?? null, $where . ' ' . $field);
                }
            }
        }
    }

    /**
     * The one shared fixture that states a `path` is answered with that path.
     *
     * It reads `/p[1]/kbd[11]`, counting the `<kbd>` among ALL eleven child
     * nodes of its paragraph rather than among the six element children, and
     * with no wrapper of the importer's own in front of it. Asserted by its
     * literal value as well as through the loop above, so the expectation
     * cannot quietly follow this engine if the engine moves.
     */
    public function testTheFixturePathIsTheSharedContractPath(): void
    {
        $fixture = dirname(__DIR__, 2) . '/spec/tests/html-import/semantic-span-attributes';
        $html = file_get_contents($fixture . '/input.html');
        $reportJson = file_get_contents($fixture . '/expected.report.json');
        $this->assertNotFalse($html);
        $this->assertNotFalse($reportJson);
        $expectedReport = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);

        $actual = (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'];

        $this->assertSame('/p[1]/kbd[11]', $expectedReport['diagnostics'][0]['path']);
        $this->assertSame('/p[1]/kbd[11]', $actual[0]['path']);
    }

    public function testDiagnosticsLimitIsTyped(): void
    {
        $this->expectException(HtmlImportLimitException::class);
        (new HtmlToCarve(maxDiagnostics: 0))->convertWithReport('<p onclick="x()">x</p>');
    }
}
