<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
use MarkupCarve\Carve\Converter\HtmlImportLimitException;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class HtmlImportReportTest extends TestCase
{
    /**
     * Fixtures this engine has DELIBERATELY moved PAST the pinned spec on.
     *
     * The mirror of carve-js's `AHEAD_OF_PIN` in
     * `test/html-import-conformance.test.ts`, for the same reason: an engine
     * ahead of a pinned fixture is a normal state BETWEEN two pin bumps, and
     * what is not normal is not knowing which window you are in. The spec repo
     * declares the other side of the same window itself, with a `PIN_LAG` entry
     * written in the commit that ruled the clause.
     *
     * Each entry FAILS IN BOTH DIRECTIONS:
     *
     *  - the written source must equal what the CURRENT spec states, so a
     *    regression is caught exactly as the fixture would have caught it;
     *  - and it must still DIFFER from the pinned golden, so the entry fails and
     *    has to be deleted in the same commit that moves the pin.
     *
     * @var array<string, array{reason: string, carve: string}>
     */
    private const AHEAD_OF_PIN = [
        // EMPTY, and that is the state to expect between windows. The only
        // entry here was PART 9 §17 L7's `derived-endnotes-section`, and the
        // pin move in this change is the one its own staleness half was
        // waiting for: the fixture on disk now IS the value the entry
        // recorded, so the entry stops asserting anything and goes in the same
        // commit that moved the pin.
    ];

    /**
     * An entry naming a fixture that is not there asserts nothing - it was
     * renamed upstream, or already retired.
     */
    public function testAheadOfPinNamesOnlyFixturesThatExist(): void
    {
        $root = dirname(__DIR__, 2) . '/spec/tests/html-import';
        $present = array_map('basename', (array)glob($root . '/*', GLOB_ONLYDIR));

        $this->assertSame([], array_values(array_diff(array_keys(self::AHEAD_OF_PIN), $present)));
    }

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
            $ahead = self::AHEAD_OF_PIN[basename($fixture)] ?? null;
            if ($ahead !== null) {
                $this->assertSame($ahead['carve'], $result->value, $ahead['reason']);
                // THE STALENESS HALF. When the pin moves past the clause the
                // fixture is rewritten to exactly this value, and the entry has
                // to go in the same commit that moves the pin. Without this the
                // carve-out would outlive the window it describes and silently
                // stop asserting anything.
                $this->assertNotSame(
                    $ahead['carve'],
                    $expected,
                    basename($fixture) . ' now matches the pin: delete its AHEAD_OF_PIN entry',
                );
            } else {
                $this->assertSame($expected, $result->value, basename($fixture));
            }
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
