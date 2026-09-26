<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
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
     * Each DECLARED key fails in BOTH DIRECTIONS:
     *
     *  - what this engine produces must equal what the CURRENT spec states, so a
     *    regression is caught exactly as the fixture would have caught it;
     *  - and it must still DIFFER from the pinned golden, so the entry fails and
     *    has to be deleted in the same commit that moves the pin.
     *
     * The keys are independent, because a clause need not move all three. A key
     * an entry omits is asserted against the pinned fixture as usual: a clause
     * that moves only the tree leaves the written source under the fixture's own
     * guard rather than under a restated copy of it.
     *
     * `diagnostics` is the code list this engine emits, given for a clause
     * that moves the rows as well as the source - which is the usual case,
     * since a row describes what the writer gave up.
     *
     * `ast` is the TREE this engine now imports, given for a clause that moves
     * the tree without moving the written source. It is the whole document, as
     * the fixture spells one, so the entry reads as the fixture's replacement
     * rather than as a patch nobody can check.
     *
     * @var array<string, array{reason: string, carve?: string, diagnostics?: list<string>, ast?: string}>
     */
    private const AHEAD_OF_PIN = [
        'security' => [
            'reason' => "markup-carve/carve#2361: a span's edge whitespace stands outside it",
            'carve' => "safe [text]{title=lost}\n",
            'ast' => '{"type":"document","children":[{"type":"paragraph","children":[{"type":"text","value":"safe "},'
                . '{"type":"span","attrs":{"keyValues":{"title":"lost"}},"children":[{"type":"text","value":"text"}]}]}]}',
        ],
    ];

    /**
     * Shared fixtures whose direct-import tree and canonical-source exit do not
     * yet agree in this engine. Every entry is checked in both directions: the
     * named mismatch must still exist, and an unnamed mismatch fails.
     *
     * @var array<string, string>
     */
    private const AST_DIVERGENCES = [
        // EMPTY, and the two-way guard below is what keeps it that way: an
        // entry whose divergence stops reproducing FAILS with "delete its
        // AST_DIVERGENCES entry", so a row cannot outlive its cause.
        //
        // The six it held had two causes, both of them the AST exit publishing
        // the SOURCE WRITER rather than the document, and both closed in
        // `markup-carve/carve-php#1716`. Four were the writer's escapes read
        // back as `escaped_text` nodes; two were structures Carve source cannot
        // spell, which this exit is not allowed to lose.
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
        $this->assertSame([], array_values(array_diff(array_keys(self::AST_DIVERGENCES), $present)));
    }

    public function testReportMakesLossVisible(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p onclick="evil()">safe<script>alert(1)</script><span title="lost"> text</span></p>',
        );

        $this->assertSame('safe [text]{title=lost}', trim($result->value));
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

    public function testAstConvenienceReturnsTheReportedValue(): void
    {
        $importer = new HtmlToCarve();
        $html = '<h1>Hello</h1>';

        $this->assertSame($importer->convertToAstWithReport($html)->value, $importer->convertToAst($html));
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
            $astJson = file_get_contents($fixture . '/expected.ast.json');
            $this->assertNotFalse($html);
            $this->assertNotFalse($expected);
            $this->assertNotFalse($reportJson);
            $this->assertNotFalse($astJson);
            $expectedReport = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
            $expectedAst = json_decode($astJson, true, flags: JSON_THROW_ON_ERROR);

            $result = (new HtmlToCarve())->convertWithReport($html);
            $astResult = (new HtmlToCarve())->convertToAstWithReport($html);
            $actual = $result->report()['diagnostics'];
            $ahead = self::AHEAD_OF_PIN[basename($fixture)] ?? null;
            if ($ahead !== null && array_key_exists('carve', $ahead)) {
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
            $expectedCodes = array_column($expectedReport['diagnostics'], 'code');
            if ($ahead !== null && array_key_exists('diagnostics', $ahead)) {
                $this->assertSame($ahead['diagnostics'], array_column($actual, 'code'), $ahead['reason']);
                // The staleness half, as above.
                $this->assertNotSame(
                    $ahead['diagnostics'],
                    $expectedCodes,
                    basename($fixture) . ' now matches the pin: delete its AHEAD_OF_PIN entry',
                );
                // The rows the fixture states are the ones this engine no
                // longer emits, so there is no field of them left to read. The
                // TREE and the two exits' agreement below still run: only the
                // report moved.
                $expectedReport['diagnostics'] = [];
                $matched = [];
            } else {
                $matched = self::matchDiagnostics($expectedCodes, array_column($actual, 'code'), basename($fixture));
            }

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
            //
            // `fidelity` and `confidence` joined the fixtures with importer
            // fidelity v2 (`markup-carve/carve#1985`) and are read here for
            // that reason: the classification is the shared contract's answer
            // per diagnostic code, so an engine answering it alone is the same
            // shape of drift one field over.
            foreach ($expectedReport['diagnostics'] as $index => $diagnostic) {
                $where = basename($fixture) . ' #' . $index;
                // The ROW THIS ONE MATCHED, not the row at the same offset: a
                // fixture's rows are a subsequence, so an engine that splits an
                // earlier one shifts every index after it (carve#1884).
                $at = $matched[$index] ?? $index;
                $this->assertArrayHasKey($at, $actual, $where);
                foreach (['message', 'severity', 'path', 'fidelity', 'confidence'] as $field) {
                    if (!array_key_exists($field, $diagnostic)) {
                        continue;
                    }
                    $this->assertSame($diagnostic[$field], $actual[$at][$field] ?? null, $where . ' ' . $field);
                }
            }

            $astDifference = self::astDifference($expectedAst, $astResult->value);
            if ($ahead !== null && array_key_exists('ast', $ahead)) {
                /** @var array<string, mixed> $aheadAst */
                $aheadAst = json_decode($ahead['ast'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertNull(
                    self::astDifference($aheadAst, $astResult->value),
                    $ahead['reason'] . ': ' . self::astDifference($aheadAst, $astResult->value),
                );
                // The staleness half. When the pin moves the fixture is
                // re-recorded to exactly this tree, and the entry has to go in
                // the same commit.
                $this->assertNotNull(
                    $astDifference,
                    basename($fixture) . ' now matches the pinned tree: delete its AHEAD_OF_PIN entry',
                );

                continue;
            }
            $declaredAstDifference = self::AST_DIVERGENCES[basename($fixture)] ?? null;
            if ($declaredAstDifference === null) {
                $this->assertNull($astDifference, basename($fixture) . ': ' . $astDifference);
            } else {
                $this->assertNotNull(
                    $astDifference,
                    basename($fixture) . ' now agrees: delete its AST_DIVERGENCES entry',
                );
            }
            $this->assertSame($result->report(), $astResult->report(), basename($fixture) . ' report');
        }
    }

    /**
     * Compare the fixture's required tree against the engine tree. Location
     * fields and optional fields absent from the fixture are ignored, exactly
     * as docs/html-import.md specifies; arrays remain exact and ordered.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $path
     */

    /**
     * The actual row each fixture row matched, or a failure naming what is off.
     *
     * HOW MANY ROWS ONE LOSS TAKES IS ENGINE-DEFINED (carve#1884, and the
     * `html-import` page says so). A table whose `<thead>` sits between two
     * `<tbody>` runs is one degradation, and this engine itemizes the distinct
     * losses where carve-js coalesces them - same code, same path, same
     * severity, two rows against one. Comparing the arrays element for element
     * pinned whichever granularity the fixture's author generated.
     *
     * So the fixture's codes must appear IN ORDER as a subsequence, and no row
     * may carry a code the fixture does not name. An engine may split a row; it
     * may not invent a code, drop one, or reorder them.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     * @param string $where
     *
     * @return list<int>
     */
    private static function matchDiagnostics(array $expected, array $actual, string $where): array
    {
        $allowed = array_unique($expected);
        $unexpected = array_values(array_diff(array_unique($actual), $allowed));
        self::assertSame([], $unexpected, $where . ': report adds code(s) the fixture does not name');

        $matched = [];
        $at = 0;
        foreach ($actual as $index => $code) {
            if ($at < count($expected) && $code === $expected[$at]) {
                $matched[$at] = $index;
                $at++;
            }
        }
        self::assertCount(
            count($expected),
            $matched,
            $where . ': fixture rows [' . implode(', ', $expected) . '] are not a subsequence of ['
                . implode(', ', $actual) . ']',
        );

        return $matched;
    }

    private static function isEmptyDelimitedComment(mixed $node): bool
    {
        return is_array($node)
            && ($node['type'] ?? null) === 'comment'
            && ($node['delimited'] ?? false) === true
            && trim((string)($node['content'] ?? '')) === '';
    }

    private static function astDifference(mixed $expected, mixed $actual, string $path = '$'): ?string
    {
        if (!is_array($expected)) {
            return $expected === $actual ? null : $path . ' differs';
        }
        if (!is_array($actual)) {
            return $path . ' is not an array';
        }
        if (array_is_list($expected)) {
            if (array_is_list($actual)) {
                // PART 11 §10k N3: an empty delimited comment compares equal to nothing.
                $actual = array_values(array_filter($actual, static fn (mixed $node): bool => !self::isEmptyDelimitedComment($node)));
                $expected = array_values(array_filter($expected, static fn (mixed $node): bool => !self::isEmptyDelimitedComment($node)));
            }
            if (!array_is_list($actual) || count($expected) !== count($actual)) {
                return $path . ' has a different list shape';
            }
            foreach ($expected as $index => $value) {
                $difference = self::astDifference($value, $actual[$index], $path . '[' . $index . ']');
                if ($difference !== null) {
                    return $difference;
                }
            }

            return null;
        }
        foreach ($expected as $key => $value) {
            if ($key === 'pos' || $key === 'srcByteLength') {
                continue;
            }
            if (!array_key_exists($key, $actual)) {
                return $path . '.' . $key . ' is missing';
            }
            $difference = self::astDifference($value, $actual[$key], $path . '.' . $key);
            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
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

    public function testZeroDiagnosticsLimitReturnsOnlyTheTruncationMarkerWhenThereIsLoss(): void
    {
        $converter = new HtmlToCarve(maxDiagnostics: 0);
        $result = $converter->convertWithReport('<p onclick="x()">x</p>');

        $this->assertSame('x', trim($result->value));
        $this->assertSame(['diagnostics-truncated'], array_column($result->report()['diagnostics'], 'code'));
        $this->assertSame('error', $result->report()['diagnostics'][0]['severity']);
        $this->assertSame('dropped', $result->report()['diagnostics'][0]['fidelity']);
        $this->assertSame('fallback', $result->report()['diagnostics'][0]['confidence']);
        $this->assertArrayNotHasKey('path', $result->report()['diagnostics'][0]);
        $this->assertSame([], $converter->convertWithReport('<p>clean</p>')->diagnostics);
    }

    public function testCaptionDiagnosticsUseTheSameLimitAsTheMainPass(): void
    {
        $html = '<p onclick="a">x</p><p onclick="b">y</p>'
            . '<figure><img src="/i" alt="x"><figcaption><p>one</p><p>two</p></figcaption></figure>';

        $expected = [
            1 => ['diagnostics-truncated'],
            2 => ['attribute-dropped', 'diagnostics-truncated'],
            3 => ['attribute-dropped', 'attribute-dropped', 'diagnostics-truncated'],
        ];
        foreach ($expected as $maximum => $codes) {
            $converter = new HtmlToCarve(maxDiagnostics: $maximum);
            $result = $converter->convertWithReport($html);
            $this->assertSame($codes, array_column($result->report()['diagnostics'], 'code'));
            $this->assertSame('error', $result->report()['diagnostics'][$maximum - 1]['severity']);
            $this->assertSame([], $converter->convertWithReport('<p>reusable</p>')->diagnostics);
        }

        foreach ([4, 5] as $maximum) {
            $result = (new HtmlToCarve(maxDiagnostics: $maximum))->convertWithReport($html);
            $this->assertSame(
                ['attribute-dropped', 'attribute-dropped', 'element-unwrapped', 'element-unwrapped'],
                array_column($result->report()['diagnostics'], 'code'),
            );
        }
    }
}
