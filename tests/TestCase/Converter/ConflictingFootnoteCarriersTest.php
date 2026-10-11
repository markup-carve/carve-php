<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConflictingFootnoteCarriersTest extends TestCase
{
    #[DataProvider('sharedFixtures')]
    public function testTheSharedRecoveryContract(array $fixture): void
    {
        $converter = new HtmlToCarve(importMode: $fixture['mode']);
        $result = $converter->convertWithReport($fixture['html']);
        self::assertSame($fixture['expectedSource'], $result->value);
        self::assertSame($fixture['expectedDiagnostics'], array_map(static fn ($row): array => [
            'code' => $row->code,
            'severity' => $row->severity,
            'fidelity' => $row->fidelity(),
            'confidence' => $row->confidence(),
        ], $result->diagnostics));
        $ast = json_encode($converter->convertToAst($fixture['html']), JSON_THROW_ON_ERROR);
        foreach ($fixture['forbiddenAuthoredAttributes'] as $name) {
            self::assertStringNotContainsString($name, $result->value);
            self::assertStringNotContainsString($name, $ast);
        }
    }

    /**
     * @return iterable<string, array{array{name: string, mode: string, html: string, expectedSource: string, expectedDiagnostics: list<array<string, string>>, forbiddenAuthoredAttributes: list<string>}}>
     */
    public static function sharedFixtures(): iterable
    {
        $fixtures = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/footnote-carrier-recovery-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            yield $fixture['name'] => [$fixture];
        }
    }

    #[DataProvider('contradictoryCarriers')]
    public function testContradictoryEndnoteCarriersPreserveTheNumberedBody(
        string $mode,
        string $labelPrefix,
        string $inlinePrefix,
        bool $referenceLabel,
        string $label,
    ): void {
        $html = self::noteHtml($labelPrefix . 'footnote-label="' . $label . '" ' . $inlinePrefix . 'inline-footnote="1"', $referenceLabel);
        $converter = new HtmlToCarve(importMode: $mode);
        $source = $converter->convertWithReport($html);
        self::assertSame("[^1]\n\n[^1]: body\n", $source->value);
        self::assertCount(1, $source->diagnostics);
        self::assertSame('attribute-dropped', $source->diagnostics[0]->code);
        self::assertSame('warning', $source->diagnostics[0]->severity);
        self::assertSame('dropped', $source->diagnostics[0]->fidelity());
        self::assertSame('exact', $source->diagnostics[0]->confidence());

        $ast = $converter->convertToAstWithReport($html);
        self::assertSame('1', $ast->value['children'][0]['children'][0]['label']);
        self::assertSame('1', $ast->value['children'][1]['label']);
        self::assertSame('body', $ast->value['children'][1]['children'][0]['children'][0]['value']);
        self::assertCount(1, $ast->diagnostics);
        self::assertSame('attribute-dropped', $ast->diagnostics[0]->code);
        self::assertStringNotContainsString('data-carve-', $source->value);
        self::assertStringNotContainsString('data-djot-', $source->value);
        self::assertStringNotContainsString('data-carve-', json_encode($ast->value, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('data-djot-', json_encode($ast->value, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<array{string, string, string, bool, string}>
     */
    public static function contradictoryCarriers(): iterable
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $labelPrefix) {
                foreach (['data-carve-', 'data-djot-'] as $inlinePrefix) {
                    foreach ([false, true] as $referenceLabel) {
                        foreach (['named'] as $label) {
                            yield [$mode, $labelPrefix, $inlinePrefix, $referenceLabel, $label];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('invalidLabelAndInlineCarrier')]
    public function testAnInvalidLabelDoesNotContradictAValidInlineCarrier(string $mode, string $labelPrefix, string $inlinePrefix, string $label): void
    {
        $html = self::noteHtml($labelPrefix . 'footnote-label="' . $label . '" ' . $inlinePrefix . 'inline-footnote="1"');
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
        self::assertSame("^[body]\n", $result->value);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('attribute-dropped', $result->diagnostics[0]->code);
        self::assertStringContainsString('invalid footnote carrier label', $result->diagnostics[0]->message);
    }

    /**
     * @return iterable<array{string, string, string, string}>
     */
    public static function invalidLabelAndInlineCarrier(): iterable
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $labelPrefix) {
                foreach (['data-carve-', 'data-djot-'] as $inlinePrefix) {
                    foreach (['', 'bad]label', 'bad&#10;label', 'bad&#13;label'] as $label) {
                        yield [$mode, $labelPrefix, $inlinePrefix, $label];
                    }
                }
            }
        }
    }

    #[DataProvider('singleCarriers')]
    public function testEachSingleCarrierStillRestoresItsFootnoteKind(string $mode, string $prefix, bool $inline): void
    {
        $attrs = $prefix . ($inline ? 'inline-footnote="1"' : 'footnote-label="named"');
        $converter = new HtmlToCarve(importMode: $mode);
        $source = $converter->convertWithReport(self::noteHtml($attrs));
        self::assertSame($inline ? "^[body]\n" : "[^named]\n\n[^named]: body\n", $source->value);
        self::assertSame([], $source->diagnostics);
        $ast = $converter->convertToAst(self::noteHtml($attrs));
        $reference = $ast['children'][0]['children'][0];
        self::assertSame($inline ? 'inline_footnote' : 'footnote_ref', $reference['type']);
        if ($inline) {
            self::assertCount(1, $ast['children']);
            self::assertSame('body', $reference['inline'][0]['value']);
        } else {
            self::assertSame('named', $reference['label']);
            self::assertSame('named', $ast['children'][1]['label']);
        }
    }

    /**
     * @return iterable<array{string, string, bool}>
     */
    public static function singleCarriers(): iterable
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                yield [$mode, $prefix, false];
                yield [$mode, $prefix, true];
            }
        }
    }

    public function testAnUnrelatedReferenceLabelIsNotStripped(): void
    {
        $html = '<p><a href="#elsewhere" data-carve-footnote-label="elsewhere">2</a></p>' . self::noteHtml('data-carve-footnote-label="named" data-carve-inline-footnote="1"');
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $source = (new HtmlToCarve(importMode: $mode))->convert($html);
            self::assertStringStartsWith("[^elsewhere]\n\n[^1]\n", $source);
            self::assertStringContainsString("[^1]: body\n", $source);
        }
    }

    public function testAnOrdinaryLinkToTheEndnoteRemainsALink(): void
    {
        $html = '<p><a href="#fn1">Jump</a></p>' . self::noteHtml('data-carve-inline-footnote="1"');
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $source = (new HtmlToCarve(importMode: $mode))->convert($html);
            self::assertSame("[Jump](#fn1)\n\n^[body]\n", $source);
        }
    }

    private static function noteHtml(string $attributes, bool $referenceLabel = false): string
    {
        return '<p><a href="#fn1" role="doc-noteref"' . ($referenceLabel ? ' data-carve-footnote-label="named"' : '') . '>1</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1" ' . $attributes . '><p>body</p></li></ol></section>';
    }
}
