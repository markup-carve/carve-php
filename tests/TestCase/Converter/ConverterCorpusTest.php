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
 * the drift is then DECLARED in the spec repo's resources/converter-drift.txt,
 * never tolerated silently (markup-carve/carve#1210).
 */
class ConverterCorpusTest extends TestCase
{
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
        // Converters at their DEFAULTS: the corpus dialect ruling is
        // CommonMark plus GFM, and everything past that base is behind a
        // constructor flag that defaults to off.
        $carve = match ($format) {
            'md' => (new MarkdownToCarve())->convert($source),
            'html' => (new HtmlToCarve())->convert($source),
            'bbcode' => (new BbcodeToCarve())->convert($source),
            'djot' => (new DjotToCarve())->convert($source),
            default => self::fail("No importer for format '{$format}' (case {$slug}) - declare the gap in the spec repo's converter-formats map or add the converter."),
        };

        $rendered = rtrim((new CarveConverter())->convert($carve), "\n");

        $this->assertSame(
            rtrim($expected, "\n"),
            $rendered,
            'Converter corpus mismatch for ' . $slug . "; produced Carve:\n" . $carve,
        );
    }
}
