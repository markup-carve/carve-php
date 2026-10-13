<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Fixture array{id: string, source: string, html: string, attribute: string, value: string|null, defaultValue: string|null, lossCodes: list<string>, carriers: array<string, string|null>, ordinaryAttributes: array<string, string|null>, carrierText: array<string, string|list<string>>, expectedNodes: list<array<string, mixed>>, fallbackNodes: list<array<string, mixed>>, fallbackHtml: string, carrierHosts: list<array{attribute: string, element: string}>, importModes: list<string>, forbiddenExportAttributes: list<string>, carrierFragments: list<string>, forbiddenTypes: list<string>, forbiddenSourceSubstrings: list<string>, forbiddenAstSubstrings: list<string>, forbiddenRenderedSubstrings: list<string>, requiresDiagnostic: bool, kept: bool, diagnosticControlHtml: string|null, expectedAbsentFields: list<array{type: string, field: string}>, forbiddenFieldValues: list<array{type: string, field: string, value: mixed}>}
 */
class SharedRoundtripExportAcceptanceTest extends TestCase
{
    public function testCachedTitleMetadataDoesNotAddAReaderDepthLimit(): void
    {
        $document = new Document();
        $div = new Div();
        $div->addClass('box');
        $div->setTyped(true);
        $div->setHeader('x');
        $first = new Span();
        $parent = $first;
        for ($i = 0; $i < 260; $i++) {
            $child = new Span();
            $parent->appendChild($child);
            $parent = $child;
        }
        $parent->appendChild(new Text('x'));
        $div->setHeaderNodes([$first]);
        $document->appendChild($div);
        $renderer = new CarveRenderer();
        self::assertSame("::: box \"x\"\n\n:::\n", $renderer->render($document));
    }

    /**
     * @var list<string>
     */
    private const INVENTORY = ['xref', 'caption-number', 'explicit-id', 'ref', 'autolink', 'footnote-label', 'inline-footnote', 'raw', 'escaped', 'abbreviations', 'col-widths', 'label', 'generated-id', 'id-slot', 'tight'];

    /**
     * Reserved without an inventory row: no core writer emits it, every importer consumes it.
     *
     * @var list<string>
     */
    private const RETIRED = ['data-carve-src', 'data-djot-src'];

    /**
     * @phpstan-param Fixture $fixture
     *
     * @param string $kind
     * @param array $fixture
     * @param string|null $mode
     */
    #[DataProvider('sharedCases')]
    public function testThePublishedPortableContract(string $kind, array $fixture, ?string $mode): void
    {
        switch ($kind) {
            case 'exportImport':
                $this->assertExportImport($fixture);

                break;
            case 'importNegative':
                self::assertNotNull($mode);
                $this->assertNegativeImport($fixture, $mode);

                break;
            case 'collisionExport':
                $this->assertCollisionExport($fixture);

                break;
            case 'markdownExportImport':
                $this->assertMarkdownExportImport($fixture);

                break;
            default:
                self::fail('Unknown published acceptance case');
        }
    }

    public function testExportDoesNotEnableADeniedDestination(): void
    {
        $result = (new CarveConverter(roundTripMode: true))->convertWithReport("[x](javascript:one)\n");
        self::assertStringNotContainsString('javascript:', $result->value);
        self::assertNotEmpty(array_filter($result->losses, static fn ($row): bool => $row['code'] === 'destination-denied'));
    }

    public function testTheContractReferencesTheExistingCompleteFootnoteResource(): void
    {
        $fixtures = self::resource();
        self::assertSame('footnote-carrier-recovery-fixtures.json', $fixtures['footnoteRecoveryResource']);
        $paired = self::readJson(dirname(__DIR__, 2) . '/spec/resources/' . $fixtures['footnoteRecoveryResource']);
        self::assertCount(24, $paired);
        foreach ($paired as $fixture) {
            self::assertIsArray($fixture);
            self::assertIsString($fixture['mode']);
            self::assertIsString($fixture['html']);
            self::assertIsString($fixture['expectedSource']);
            self::assertIsString($fixture['name']);
            $importer = new HtmlToCarve(importMode: $fixture['mode']);
            $result = $importer->convertWithReport($fixture['html']);
            self::assertSame($fixture['expectedSource'], $result->value, $fixture['name']);
            self::assertSame($fixture['expectedDiagnostics'], array_map(static fn ($row): array => [
                'code' => $row->code,
                'severity' => $row->severity,
                'fidelity' => $row->fidelity(),
                'confidence' => $row->confidence(),
            ], $result->diagnostics));
            self::assertNoReservedAttributes($importer->convertToAstWithReport($fixture['html'])->value);
        }
    }

    public function testRecoveredCarriersArePublishedAstFields(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                $converter = new HtmlToCarve(importMode: $mode);
                $html = '<p><span ' . $prefix . 'escaped>é</span> ordinary</p><img src="/a.png" alt="alt" title="title" ' . $prefix . 'ref="r">';
                $tree = $converter->convertToAstWithReport($html)->value;
                self::assertTrue(self::matchesProjection($tree, [
                    ['type' => 'escaped_text', 'value' => 'é'],
                    ['type' => 'text', 'value' => ' ordinary'],
                    ['type' => 'image', 'src' => '/a.png', 'ref' => 'r', 'title' => 'title'],
                    ['type' => 'link_reference_definition', 'label' => 'r', 'href' => '/a.png'],
                ]));
                self::assertStringNotContainsString('carve-escaped-carrier', json_encode($tree, JSON_THROW_ON_ERROR));
                $codec = new AstCodec();
                self::assertEquals($tree, $codec->encode($codec->decode($tree)));
            }
        }
        $ordinary = (new HtmlToCarve())->convertToAstWithReport('<p>* ordinary</p>')->value;
        self::assertTrue(self::matchesProjection($ordinary, [['type' => 'text', 'value' => '* ordinary']]));
    }

    public function testReferenceImageRecoveryPreservesDestinationsTitlesAndAltText(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                foreach (['alt', 'a[b', 'a]', 'a\\'] as $alt) {
                    $html = '<p>lead <img src="/a.png" alt="' . $alt . '" title="T" ' . $prefix . 'ref="r"> tail</p>';
                    $tree = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport($html)->value;
                    $source = (new CarveRenderer())->render((new AstCodec())->decode($tree));
                    $rendered = (new CarveConverter())->convert($source);
                    $images = array_values(array_filter(self::elements($rendered), static fn (DOMElement $element): bool => strtolower(HtmlDomLoader::elementName($element)) === 'img'));
                    self::assertCount(1, $images, $mode . ': ' . $source);
                    self::assertSame('/a.png', $images[0]->getAttribute('src'));
                    self::assertSame($alt, $images[0]->getAttribute('alt'));
                    self::assertSame('T', $images[0]->getAttribute('title'));
                }
                $html = '<p><a href="/a" ' . $prefix . 'ref="r">a</a> <img src="/b" alt="b" ' . $prefix . 'ref="r"></p>';
                $result = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport($html);
                $source = (new CarveRenderer())->render((new AstCodec())->decode($result->value));
                $parsed = (new CarveConverter())->parse($source);
                self::assertTrue(self::matchesProjection((new AstCodec())->encode($parsed), [
                    ['type' => 'link', 'href' => '/a'],
                    ['type' => 'image', 'src' => '/b'],
                ]));
                self::assertContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $result->diagnostics));
            }
        }
    }

    public function testReferenceTitlesEscapeBackslashesAndFallBackForNewlines(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['T\\', 'a\\"b', "a\nb"] as $title) {
                foreach (['a', 'img'] as $tag) {
                    $attrs = $tag === 'a' ? 'href="/u"' : 'src="/u" alt="alt"';
                    $html = '<p>lead <' . $tag . ' ' . $attrs . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '" data-carve-ref="r">' . ($tag === 'a' ? 'a</a>' : '') . ' tail</p>';
                    $converter = new HtmlToCarve(importMode: $mode);
                    $tree = $converter->convertToAstWithReport($html)->value;
                    foreach ([$converter->convertWithReport($html)->value, (new CarveRenderer())->render((new AstCodec())->decode($tree))] as $source) {
                        $parsed = (new AstCodec())->encode((new CarveConverter())->parse($source));
                        self::assertTrue(self::matchesProjection($parsed, [
                            [
                                'type' => $tag === 'a' ? 'link' : 'image',
                                $tag === 'a' ? 'href' : 'src' => '/u',
                                'title' => $title,
                            ],
                        ]), $source);
                    }
                }
            }
        }
    }

    public function testNumericReferenceLabelsRemainStringsOnTheWire(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['0', '1', '01'] as $label) {
                $tree = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport('<p>lead <img src="/a.png" alt="alt" title="T" data-carve-ref="' . $label . '"> tail</p>')->value;
                self::assertTrue(self::matchesProjection($tree, [['type' => 'link_reference_definition', 'label' => $label, 'title' => 'T']]));
                $codec = new AstCodec();
                self::assertEquals($tree, $codec->encode($codec->decode($tree)));
                $source = (new CarveRenderer())->render($codec->decode($tree));
                self::assertTrue(self::matchesProjection($codec->encode((new CarveConverter())->parse($source)), [
                    ['type' => 'image', 'src' => '/a.png', 'title' => 'T'],
                ]));
            }
        }
    }

    public function testReferenceImageInTitleUsesItsInlineDestination(): void
    {
        $html = '<details><summary><img src="/a.png" alt="alt" data-carve-ref="r"></summary><p>x</p></details>';
        $tree = (new HtmlToCarve())->convertToAstWithReport($html)->value;
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        $source = $writer->render((new AstCodec())->decode($tree));
        $report = $writer->finishConversionDiagnosticCollection();
        self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('ref', $report['diagnostics'][0]['field']);
        self::assertStringContainsString('![alt](/a.png)', $source);
        $images = array_values(array_filter(self::elements((new CarveConverter())->convert($source)), static fn (DOMElement $element): bool => strtolower(HtmlDomLoader::elementName($element)) === 'img'));
        self::assertCount(1, $images);
        self::assertSame('/a.png', $images[0]->getAttribute('src'));
    }

    public function testUnspellableReferenceCarrierFallsBackWithoutAnInventedDefinition(): void
    {
        foreach (['^n', '@name'] as $label) {
            $result = (new HtmlToCarve())->convertToAstWithReport('<p>lead <img src="/a.png" alt="alt" data-carve-ref="' . $label . '"> tail</p>');
            self::assertAbsentFields($result->value, [['type' => 'image', 'field' => 'ref']]);
            $source = (new CarveRenderer())->render((new AstCodec())->decode($result->value));
            self::assertStringContainsString('![alt](/a.png)', $source);
            self::assertStringNotContainsString(']:', $source);
            self::assertContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $result->diagnostics));
        }
    }

    public function testUnspellableEscapedGlyphRetainsPublicAstAndReportsSourceLoss(): void
    {
        foreach ([' ', "\n", 'n', "\u{00a0}", 'é'] as $value) {
            $tree = (new HtmlToCarve())->convertToAstWithReport('<p>a<span data-carve-escaped>' . $value . '</span>b</p>')->value;
            self::assertTrue(self::matchesProjection($tree, [['type' => 'escaped_text', 'value' => $value]]));
            $writer = new CarveRenderer();
            $writer->beginConversionDiagnosticCollection();
            $source = $writer->render((new AstCodec())->decode($tree));
            $renderedRoot = HtmlDomLoader::load((new CarveConverter())->convert($source));
            self::assertSame('a' . $value . 'b', trim($renderedRoot->textContent, "\n"));
            $report = $writer->finishConversionDiagnosticCollection();
            self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
            self::assertSame('value', $report['diagnostics'][0]['field']);
        }
    }

    public function testEscapedCarriersDoNotBreakIntermediateTreesOrAttributeReports(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                foreach (
                    [
                        '<details><summary>s<span CARRIER>*</span></summary><p>x</p></details>',
                        '<h2 id="x">a<span CARRIER>*</span></h2>',
                        '<p class="k">a<span CARRIER>*</span></p>',
                    ] as $template
                ) {
                    $html = str_replace('CARRIER', $prefix . 'escaped', $template);
                    $result = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport($html);
                    self::assertTrue(self::matchesProjection($result->value, [['type' => 'escaped_text', 'value' => '*']]));
                    self::assertSame([], $result->diagnostics);
                    $built = (new HtmlAstBuilder(importMode: $mode, sourceSafe: false))->buildResult($html);
                    (new AstCodec())->decodeImporterTree($built->tree);
                    self::assertStringNotContainsString('carve-escaped-carrier', json_encode($built->tree, JSON_THROW_ON_ERROR));
                }
            }
        }
    }

    public function testImageReferenceRecoveryMatchesTheExistingLinkLabelChecks(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                $converter = new HtmlToCarve(importMode: $mode);
                $collapsed = $converter->convertToAstWithReport('<img src="/a.png" alt="alt" ' . $prefix . 'ref="">')->value;
                self::assertTrue(self::matchesProjection($collapsed, [['type' => 'image', 'ref' => 'alt']]));
                foreach (['a]b[c', "a\n\nb"] as $label) {
                    $html = '<img src="/a.png" alt="alt" ' . $prefix . 'ref="' . $label . '">';
                    $tree = $converter->convertToAstWithReport($html)->value;
                    self::assertTrue(self::matchesProjection($tree, [['type' => 'image', 'src' => '/a.png', 'alt' => 'alt']]));
                    self::assertAbsentFields($tree, [['type' => 'image', 'field' => 'ref'], ['type' => 'image', 'field' => 'rawRef']]);
                    foreach (self::nodes($tree) as $node) {
                        self::assertNotSame('link_reference_definition', $node['type']);
                    }
                }
            }
        }
    }

    public function testTaskHooksAreOrdinaryOutput(): void
    {
        $fixtures = self::resource();
        $tasks = array_values(array_filter($fixtures['exportImport'], static fn ($row): bool => $row['id'] === 'task-states'));
        self::assertCount(1, $tasks);
        $html = (new CarveConverter())->convert($tasks[0]['source']);
        self::assertStringContainsString('task-list', $html);
        foreach (['x', '-', '_', '>', '?'] as $state) {
            self::assertNotEmpty(array_filter(self::attributes($html), static fn ($attr): bool => $attr['name'] === 'data-task-state' && $attr['value'] === $state));
        }
    }

    public function testSharedCaseIdentitiesAndAssertionsAreDistinct(): void
    {
        $fixtures = self::resource();
        $ids = [];
        foreach (['exportImport', 'importNegative', 'collisionExport', 'markdownExportImport'] as $kind) {
            foreach ($fixtures[$kind] as $fixture) {
                self::assertNotContains($fixture['id'], $ids);
                $ids[] = $fixture['id'];
                self::assertTrue($fixture['source'] !== '' || $fixture['html'] !== '');
                self::assertTrue($fixture['expectedNodes'] !== [] || $fixture['attribute'] !== '');
            }
        }
    }

    /**
     * @return iterable<string, array{string, Fixture, ?string}>
     */
    public static function sharedCases(): iterable
    {
        $resource = dirname(__DIR__, 2) . '/spec/resources/roundtrip-export-acceptance-fixtures.json';
        $fixtures = self::resource();
        foreach (['exportImport', 'importNegative', 'collisionExport', 'markdownExportImport'] as $kind) {
            foreach ($fixtures[$kind] as $fixture) {
                foreach ($kind === 'importNegative' ? $fixture['importModes'] : [null] as $mode) {
                    yield $kind . '/' . $fixture['id'] . ($mode === null ? '' : '/' . $mode) => [$kind, $fixture, $mode];
                }
            }
        }
    }

    /**
     * @phpstan-param Fixture $fixture
     *
     * @param array $fixture
     */
    private function assertExportImport(array $fixture): void
    {
        $ordinaryConverter = new CarveConverter();
        self::assertTrue(self::matchesProjection((new AstCodec())->encode($ordinaryConverter->parse($fixture['source'])), $fixture['expectedNodes']));
        $ordinary = $ordinaryConverter->convert($fixture['source']);
        self::assertSame($ordinary, (new CarveConverter(roundTripMode: false))->convert($fixture['source']));
        foreach (self::attributes($ordinary) as $attribute) {
            self::assertFalse(self::reserved($attribute['name']), $attribute['name']);
        }
        $exported = (new CarveConverter(roundTripMode: true))->convert($fixture['source']);
        $attrs = self::attributes($exported);
        foreach ($attrs as $attribute) {
            if (self::reserved($attribute['name'])) {
                self::assertFalse(str_starts_with($attribute['name'], 'data-djot-'));
                self::assertArrayHasKey($attribute['name'], $fixture['carriers']);
            }
        }
        foreach ($fixture['carriers'] as $name => $value) {
            self::assertNotEmpty(array_filter($attrs, static fn ($attr): bool => $attr['name'] === $name && ($value === null || $attr['value'] === $value)), $name);
        }
        foreach ($fixture['carrierHosts'] as $host) {
            self::assertNotEmpty(array_filter($attrs, static fn ($attr): bool => $attr['name'] === $host['attribute'] && $attr['element'] === $host['element']));
        }
        foreach ($fixture['forbiddenExportAttributes'] as $name) {
            self::assertEmpty(array_filter($attrs, static fn ($attr): bool => $attr['name'] === $name));
        }
        foreach ($fixture['ordinaryAttributes'] as $name => $value) {
            foreach ([$ordinary, $exported] as $html) {
                self::assertNotEmpty(array_filter(self::attributes($html), static fn ($attr): bool => $attr['name'] === $name && $attr['value'] === $value));
            }
        }
        foreach ($fixture['carrierText'] as $name => $text) {
            $hosts = array_values(array_filter(self::elements($exported), static fn ($node): bool => $node->hasAttribute($name)));
            self::assertCount(1, $hosts);
            foreach ($hosts[0]->childNodes as $child) {
                self::assertInstanceOf(DOMText::class, $child);
            }
            if (is_array($text)) {
                self::assertContains($hosts[0]->textContent, $text);
            } else {
                self::assertSame($text, $hosts[0]->textContent);
            }
        }
        foreach ([false, true] as $roundtrip) {
            $losses = (new CarveConverter(roundTripMode: $roundtrip))->convertWithReport($fixture['source'])->losses;
            foreach ($fixture['lossCodes'] as $code) {
                self::assertContains($code, array_column($losses, 'code'), $code . ($roundtrip ? ' under roundtrip export' : ' under default export'));
            }
        }
        foreach ($fixture['importModes'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            $imported = $importer->convertToAstWithReport($exported)->value;
            $declined = !self::matchesProjection($imported, $fixture['expectedNodes']);
            self::assertTrue(self::matchesProjection($imported, $declined ? $fixture['fallbackNodes'] : $fixture['expectedNodes']));
            self::assertFalse($declined && $fixture['fallbackNodes'] === []);
            self::assertNoReservedAttributes($imported);
            self::assertAbsentFields($imported, $fixture['expectedAbsentFields']);
            $legacy = $importer->convertToAstWithReport(self::deprecatedHtml($exported))->value;
            self::assertTrue(self::matchesProjection($legacy, $fixture['expectedNodes']) || ($fixture['fallbackNodes'] !== [] && self::matchesProjection($legacy, $fixture['fallbackNodes'])));
            self::assertNoReservedAttributes($legacy);
            $source = $importer->convertWithReport($exported)->value;
            self::assertSame(trim($declined ? $fixture['fallbackHtml'] : $ordinary), trim($ordinaryConverter->convert($source)), $mode);
        }
    }

    /**
     * @phpstan-param Fixture $fixture
     *
     * @param array $fixture
     * @param string $mode
     */
    private function assertNegativeImport(array $fixture, string $mode): void
    {
        $importer = new HtmlToCarve(importMode: $mode);
        $result = $importer->convertToAstWithReport($fixture['html']);
        self::assertTrue(self::matchesProjection($result->value, $fixture['expectedNodes']));
        self::assertNoReservedAttributes($result->value);
        if ($fixture['requiresDiagnostic']) {
            $control = $fixture['diagnosticControlHtml'] !== null ? count($importer->convertToAstWithReport($fixture['diagnosticControlHtml'])->diagnostics) : 0;
            self::assertGreaterThan($control, count($result->diagnostics));
        }
        $source = $importer->convertWithReport($fixture['html'])->value;
        foreach ($fixture['forbiddenSourceSubstrings'] as $fragment) {
            self::assertStringNotContainsString($fragment, $source);
        }
        foreach ($fixture['forbiddenAstSubstrings'] as $fragment) {
            self::assertStringNotContainsString($fragment, json_encode($result->value, JSON_THROW_ON_ERROR));
        }
        foreach ($fixture['forbiddenRenderedSubstrings'] as $fragment) {
            self::assertStringNotContainsString($fragment, (new CarveConverter())->convert($source));
        }
        foreach (self::nodes($result->value) as $node) {
            self::assertNotContains($node['type'], $fixture['forbiddenTypes']);
            foreach ($fixture['forbiddenFieldValues'] as $forbidden) {
                if ($node['type'] === $forbidden['type']) {
                    self::assertNotSame($forbidden['value'], self::field($node, $forbidden['field']));
                }
            }
        }
        self::assertAbsentFields($result->value, $fixture['expectedAbsentFields']);
    }

    /**
     * @phpstan-param Fixture $fixture
     *
     * @param array $fixture
     */
    private function assertCollisionExport(array $fixture): void
    {
        // Read off the bytes: the DOM loader consumes a retired name before a test could see it.
        $named = static function (string $html) use ($fixture): array {
            preg_match_all('/\s' . preg_quote($fixture['attribute'], '/') . '="([^"]*)"/', $html, $matches);

            return $matches[1];
        };
        $result = (new CarveConverter(roundTripMode: true))->convertWithReport($fixture['source']);
        self::assertSame($fixture['value'] === null ? [] : [$fixture['value']], $named($result->value));
        self::assertCount($fixture['kept'] ? 0 : 1, array_filter($result->losses, static fn ($row): bool => $row['code'] === 'carrier-attribute-dropped' && ($row['attribute'] ?? null) === $fixture['attribute']));
        if ($fixture['defaultValue'] === null) {
            return;
        }
        $ordinary = (new CarveConverter())->convertWithReport($fixture['source']);
        self::assertSame([$fixture['defaultValue']], $named($ordinary->value));
        self::assertNotContains('carrier-attribute-dropped', array_column($ordinary->losses, 'code'));
    }

    /**
     * @phpstan-param Fixture $fixture
     *
     * @param array $fixture
     */
    private function assertMarkdownExportImport(array $fixture): void
    {
        $ordinary = new CarveConverter(renderer: new MarkdownRenderer(), roundTripMode: false);
        self::assertStringNotContainsString('<!-- carve:', $ordinary->convert($fixture['source']));
        $exporter = new CarveConverter(renderer: new MarkdownRenderer(), roundTripMode: true);
        $report = $exporter->convertWithReport($fixture['source']);
        $exported = $report->value;
        foreach ($fixture['lossCodes'] as $code) {
            self::assertContains($code, array_column($report->losses, 'code'));
        }
        foreach ($fixture['carrierFragments'] as $fragment) {
            self::assertStringContainsString($fragment, $exported);
        }
        $source = (new MarkdownToCarve())->convert($exported);
        $converter = new CarveConverter();
        self::assertTrue(self::matchesProjection((new AstCodec())->encode($converter->parse($source)), $fixture['expectedNodes']));
        if (in_array('definition-list-flattened', $fixture['lossCodes'], true)) {
            self::assertNotSame(trim($converter->convert($fixture['source'])), trim($converter->convert($source)));
        } else {
            self::assertSame(trim($converter->convert($fixture['source'])), trim($converter->convert($source)));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readJson(string $path): array
    {
        $text = file_get_contents($path);
        self::assertIsString($text);
        $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array{footnoteRecoveryResource: string, exportImport: list<Fixture>, importNegative: list<Fixture>, collisionExport: list<Fixture>, markdownExportImport: list<Fixture>}
     */
    private static function resource(): array
    {
        $raw = self::readJson(dirname(__DIR__, 2) . '/spec/resources/roundtrip-export-acceptance-fixtures.json');
        self::assertIsString($raw['footnoteRecoveryResource']);

        return [
            'footnoteRecoveryResource' => $raw['footnoteRecoveryResource'],
            'exportImport' => self::fixtures($raw['exportImport']),
            'importNegative' => self::fixtures($raw['importNegative']),
            'collisionExport' => self::fixtures($raw['collisionExport']),
            'markdownExportImport' => self::fixtures($raw['markdownExportImport']),
        ];
    }

    /**
     * @return list<Fixture>
     */
    private static function fixtures(mixed $rows): array
    {
        self::assertIsArray($rows);
        $result = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $result[] = [
                'id' => self::text($row['id']),
                'source' => self::text($row['source'] ?? ''),
                'html' => self::text($row['html'] ?? ''),
                'attribute' => self::text($row['attribute'] ?? ''),
                'value' => array_key_exists('value', $row) && $row['value'] === null ? null : self::text($row['value'] ?? ''),
                'defaultValue' => isset($row['defaultValue']) ? self::text($row['defaultValue']) : null,
                'lossCodes' => self::strings($row['lossCodes'] ?? []),
                'carriers' => self::stringMap($row['carriers'] ?? []),
                'ordinaryAttributes' => self::stringMap($row['ordinaryAttributes'] ?? []),
                'carrierText' => self::carrierText($row['carrierText'] ?? []),
                'expectedNodes' => self::projections($row['expectedNodes'] ?? []),
                'fallbackNodes' => self::projections($row['fallbackNodes'] ?? []),
                'fallbackHtml' => self::text($row['fallbackHtml'] ?? ''),
                'carrierHosts' => self::hosts($row['carrierHosts'] ?? []),
                'importModes' => self::strings($row['importModes'] ?? []),
                'forbiddenExportAttributes' => self::strings($row['forbiddenExportAttributes'] ?? []),
                'carrierFragments' => self::strings($row['carrierFragments'] ?? []),
                'forbiddenTypes' => self::strings($row['forbiddenTypes'] ?? []),
                'forbiddenSourceSubstrings' => self::strings($row['forbiddenSourceSubstrings'] ?? []),
                'forbiddenAstSubstrings' => self::strings($row['forbiddenAstSubstrings'] ?? []),
                'forbiddenRenderedSubstrings' => self::strings($row['forbiddenRenderedSubstrings'] ?? []),
                'requiresDiagnostic' => self::boolean($row['requiresDiagnostic'] ?? false),
                'kept' => self::boolean($row['kept'] ?? false),
                'diagnosticControlHtml' => isset($row['diagnosticControlHtml']) ? self::text($row['diagnosticControlHtml']) : null,
                'expectedAbsentFields' => self::absent($row['expectedAbsentFields'] ?? []),
                'forbiddenFieldValues' => self::forbidden($row['forbiddenFieldValues'] ?? []),
            ];
        }

        return $result;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function boolean(mixed $value): bool
    {
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $value) {
            $result[] = self::text($value);
        }

        return $result;
    }

    /**
     * @return array<string, string|null>
     */
    private static function stringMap(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value === null ? null : self::text($value);
        }

        return $result;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private static function carrierText(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $key => $value) {
            self::assertIsString($key);
            $result[$key] = is_array($value) ? self::strings($value) : self::text($value);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function projections(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $value) {
            self::assertIsArray($value);
            $row = [];
            foreach ($value as $key => $item) {
                self::assertIsString($key);
                $row[$key] = $item;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return list<array{attribute: string, element: string}>
     */
    private static function hosts(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $value) {
            self::assertIsArray($value);
            $result[] = ['attribute' => self::text($value['attribute']), 'element' => self::text($value['element'])];
        }

        return $result;
    }

    /**
     * @return list<array{type: string, field: string}>
     */
    private static function absent(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $value) {
            self::assertIsArray($value);
            $result[] = ['type' => self::text($value['type']), 'field' => self::text($value['field'])];
        }

        return $result;
    }

    /**
     * @return list<array{type: string, field: string, value: mixed}>
     */
    private static function forbidden(mixed $values): array
    {
        self::assertIsArray($values);
        $result = [];
        foreach ($values as $value) {
            self::assertIsArray($value);
            $result[] = ['type' => self::text($value['type']), 'field' => self::text($value['field']), 'value' => $value['value']];
        }

        return $result;
    }

    private static function reserved(string $name): bool
    {
        if (in_array($name, ['data-marker', 'data-char', ...self::RETIRED], true)) {
            return true;
        }
        foreach (['data-carve-', 'data-djot-'] as $prefix) {
            if (str_starts_with($name, $prefix) && in_array(substr($name, strlen($prefix)), self::INVENTORY, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param string $path
     */
    private static function field(array $node, string $path): mixed
    {
        $value = $node;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<array<array-key, mixed>>
     */
    private static function nodes(array $value): array
    {
        $nodes = isset($value['type']) && is_string($value['type']) ? [$value] : [];
        foreach ($value as $child) {
            if (is_array($child)) {
                array_push($nodes, ...self::nodes($child));
            }
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param list<array<string, mixed>> $projections
     */
    private static function matchesProjection(array $tree, array $projections): bool
    {
        $remaining = self::nodes($tree);
        foreach ($projections as $projection) {
            $found = false;
            foreach ($remaining as $index => $node) {
                foreach ($projection as $key => $value) {
                    if (self::field($node, $key) !== $value) {
                        continue 2;
                    }
                }
                unset($remaining[$index]);
                $found = true;

                break;
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $tree
     */
    private static function assertNoReservedAttributes(array $tree): void
    {
        foreach (self::nodes($tree) as $node) {
                        $attrs = $node['attrs'] ?? [];
            self::assertIsArray($attrs);
            $values = $attrs['keyValues'] ?? [];
            self::assertIsArray($values);
            foreach (array_keys($values) as $name) {
                self::assertIsString($name);
                self::assertFalse(self::reserved($name), $name);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param list<array{type: string, field: string}> $absent
     */
    private static function assertAbsentFields(array $tree, array $absent): void
    {
        foreach (self::nodes($tree) as $node) {
            foreach ($absent as $entry) {
                if ($node['type'] === $entry['type']) {
                    self::assertNull(self::field($node, $entry['field']));
                }
            }
        }
    }

    /**
     * @return list<\DOMElement>
     */
    private static function elements(string $html): array
    {
        $root = HtmlDomLoader::load($html);
        $elements = [];
        $walk = static function (DOMNode $node) use (&$walk, &$elements): void {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };
        $walk($root);

        return $elements;
    }

    /**
     * @return list<array{name: string, value: string|null, element: string}>
     */
    private static function attributes(string $html): array
    {
        $attributes = [];
        foreach (self::elements($html) as $element) {
            foreach ($element->attributes as $attribute) {
                $attributes[] = ['name' => $attribute->nodeName, 'value' => $attribute->nodeValue, 'element' => $element->tagName];
            }
        }

        return $attributes;
    }

    private static function deprecatedHtml(string $html): string
    {
        $root = HtmlDomLoader::load($html);
        $uris = [];
        $uriToken = 'CARVEDEPRECATEDURI';
        while (str_contains($html, $uriToken)) {
            $uriToken .= 'X';
        }
        $walk = static function (DOMNode $node) use (&$walk, &$uris, $uriToken): void {
            if ($node instanceof DOMElement) {
                if ($node->hasAttribute('href') && preg_match('/[^\x00-\x7f]/', $node->getAttribute('href')) === 1) {
                    $token = $uriToken . count($uris) . 'Z';
                    $uris[$token] = htmlspecialchars($node->getAttribute('href'), ENT_QUOTES, 'UTF-8');
                    $node->setAttribute('href', $token);
                }
                foreach (self::INVENTORY as $name) {
                    $canonical = 'data-carve-' . $name;
                    if ($node->hasAttribute($canonical)) {
                        $node->setAttribute('data-djot-' . $name, $node->getAttribute($canonical));
                        $node->removeAttribute($canonical);
                    }
                }
            }
            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };
        $walk($root);

        $body = $root->getElementsByTagName('body')->item(0);
        if ($body === null) {
            $html = $root->saveHTML();
            self::assertIsString($html);

            return strtr($html, $uris);
        }
        $html = '';
        foreach ($body->childNodes as $child) {
            $fragment = $root->saveHTML($child);
            self::assertIsString($fragment);
            $html .= $fragment;
        }

        return strtr($html, $uris);
    }
}
