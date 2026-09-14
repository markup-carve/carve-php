<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs the spec repo's CONVERTER corpus (tests/corpus-convert in the pinned
 * submodule): foreign source in, Carve out, and the produced document's render
 * compared against the case's expected HTML.
 *
 * The conformance corpus covers everything that READS Carve; this one covers
 * what WRITES it. The comparison is SEMANTIC by design - the engines spell
 * Carve differently on purpose, so the corpus pins the render of the produced
 * document, not its bytes (the corpus README records the measurement behind
 * that decision). The spec repo's cross-engine gate (`npm run compare:convert`)
 * renders every engine's output through carve-js; this runner renders through
 * this engine, which the renderer-parity corpus keeps byte-equal.
 *
 * Every case in the pinned corpus must pass: a new case arriving with a pin
 * bump turns CI red here exactly as a conformance-corpus category does, and a
 * source format this engine cannot convert fails loudly rather than skipping -
 * the drift is then DECLARED, never tolerated silently (markup-carve/carve#1210).
 * `DECLARED_DRIFT` below is where that declaration lives for a case this engine
 * is known to lose, and `testEveryDeclaredDriftStillDiverges()` deletes the
 * hiding place as soon as the entry stops being true.
 */
class ConverterCorpusTest extends TestCase
{
    /**
     * Cases this engine is KNOWN to diverge on, each naming the issue that
     * closes it.
     *
     * The spec repo's `resources/converter-drift.txt` declares the same thing
     * for the cross-engine runner. This engine cannot read that file for a
     * case the pin itself introduces - the declaration would have to land in
     * the spec, be pinned here, and only then let the pin bump go green - so
     * the declaration lives next to the runner that enforces it.
     *
     * A declared case is skipped, never passed: it still appears in the run,
     * and the entry is deleted by the commit that proves the engine matches.
     *
     * @var array<string, string>
     */
    protected const DECLARED_DRIFT = [];

    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, format: string, source: string, expected: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/spec/tests/corpus-convert';
        $caseDirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        if ($caseDirs === []) {
            throw new RuntimeException(
                "Converter corpus not found at {$dir}.\nInitialize the submodule:\n  git submodule update --init",
            );
        }

        $cases = [];
        foreach ($caseDirs as $caseDir) {
            $slug = basename($caseDir);
            $inputs = glob($caseDir . '/input.*') ?: [];
            if (count($inputs) !== 1) {
                throw new RuntimeException($caseDir . ' must hold exactly one input.<ext> file');
            }
            $cases[$slug] = [
                'slug' => $slug,
                'format' => pathinfo($inputs[0], PATHINFO_EXTENSION),
                'source' => (string)file_get_contents($inputs[0]),
                'expected' => (string)file_get_contents($caseDir . '/expected.html'),
            ];
        }

        return $cases;
    }

    #[DataProvider('corpusProvider')]
    public function testConvertedSourceRendersTheExpectedHtml(string $slug, string $format, string $source, string $expected): void
    {
        if (isset(self::DECLARED_DRIFT[$slug])) {
            $this->markTestSkipped('Declared converter drift - ' . self::DECLARED_DRIFT[$slug]);
        }

        $carve = $this->convertCase($slug, $format, $source);

        $this->assertSame(
            rtrim($expected, "\n"),
            rtrim((new CarveConverter())->convert($carve), "\n"),
            'Converter corpus mismatch for ' . $slug . "; produced Carve:\n" . $carve,
        );
    }

    /**
     * A declaration that no longer describes the engine is worse than none: it
     * hides a case that passes. Deleting the entry is the commit that proves
     * the fix, so this fails the moment the entry stops being true.
     */
    public function testEveryDeclaredDriftStillDiverges(): void
    {
        $this->addToAssertionCount(1);
        $cases = self::corpusProvider();
        foreach (self::DECLARED_DRIFT as $slug => $reason) {
            $this->assertArrayHasKey($slug, $cases, "Declared drift names no corpus case: {$slug}");
            $case = $cases[$slug];
            $carve = $this->convertCase($slug, $case['format'], $case['source']);
            $this->assertNotSame(
                rtrim($case['expected'], "\n"),
                rtrim((new CarveConverter())->convert($carve), "\n"),
                "{$slug} now matches the corpus - delete its DECLARED_DRIFT entry ({$reason})",
            );
        }
    }

    /**
     * Converters at their DEFAULTS: the corpus dialect ruling is CommonMark
     * plus GFM, and everything past that base is behind a constructor flag
     * that defaults to off.
     */
    protected function convertCase(string $slug, string $format, string $source): string
    {
        return match ($format) {
            'md' => (new MarkdownToCarve())->convert($source),
            'html' => (new HtmlToCarve())->convert($source),
            'bbcode' => (new BbcodeToCarve())->convert($source),
            'djot' => (new DjotToCarve())->convert($source),
            default => self::fail("No importer for format '{$format}' (case {$slug}) - declare the gap in the spec repo's converter-formats map or add the converter."),
        };
    }
}
