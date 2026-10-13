<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NewRoundtripCarriersTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function fixtureProvider(): array
    {
        $fixtures = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/roundtrip-carriers-label-attrs-dl-captions.json'), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($fixtures['markdownExportImport'] as $fixture) {
            $rows[$fixture['id']] = [$fixture];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtureProvider')]
    public function testSharedMarkdownFixture(array $fixture): void
    {
        $renderer = new MarkdownRenderer();
        $converter = CarveConverter::create(renderer: $renderer);
        $ordinary = $converter->convert($fixture['source']);
        $this->assertStringNotContainsString('<!-- carve:', $ordinary);
        $renderer->setRoundTripMode(true);
        $exported = $converter->convert($fixture['source']);
        foreach ($fixture['carrierFragments'] as $fragment) {
            $this->assertStringContainsString($fragment, $exported);
        }
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($exported);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $this->assertSame(CarveConverter::create()->convert($fixture['source']), CarveConverter::create()->convert($imported));
        $tree = (new AstCodec())->encode(CarveConverter::create()->parse($imported));
        foreach ($fixture['expectedNodes'] as $projection) {
            $this->assertProjection($tree, $projection);
        }
        $renderer->setRoundTripMode(false);
        $this->assertSame($ordinary, $converter->convert($fixture['source']));
    }

    /**
     * @param array<string|int, mixed> $tree
     * @param array<string, mixed> $projection
     */
    private function assertProjection(array $tree, array $projection): void
    {
        $nodes = [];
        $visit = static function (array $value) use (&$visit, &$nodes): void {
            if (isset($value['type'])) {
                $nodes[] = $value;
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $visit($child);
                }
            }
        };
        $visit($tree);
        foreach ($nodes as $node) {
            foreach ($projection as $path => $expected) {
                $value = $node;
                foreach (explode('.', $path) as $part) {
                    $value = $value[$part] ?? null;
                }
                if ($value !== $expected) {
                    continue 2;
                }
            }
            $this->assertTrue(true);

            return;
        }
        $this->fail('Missing projection: ' . json_encode($projection));
    }

    public function testCodeLabelIsReadInEveryModeAndSpelling(): void
    {
        $source = "```php [Example]\necho 1;\n```\n";
        $renderer = new HtmlRenderer();
        $converter = CarveConverter::create(renderer: $renderer);
        $ordinary = $converter->convert($source);
        $this->assertStringNotContainsString('data-carve-label', $ordinary);
        $renderer->setRoundTripMode(true);
        $exported = $converter->convert($source);
        $this->assertStringContainsString(' data-carve-label="Example"', $exported);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-label', 'data-djot-label'] as $name) {
                $importer = new HtmlToCarve(importMode: $mode);
                $html = str_replace('data-carve-label', $name, $exported);
                $tree = $importer->convertToAst($html);
                $this->assertProjection($tree, ['type' => 'code_block', 'lang' => 'php', 'label' => 'Example']);
                $this->assertStringNotContainsString($name, json_encode($tree, JSON_THROW_ON_ERROR));
                $this->assertSame($ordinary, CarveConverter::create()->convert($importer->convert($html)));
            }
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostProvider(): array
    {
        return [
            'hard break block' => ["::: \\\na\nb\n:::\n", '<!-- carve: ::: \\ -->'],
            'attributed hard break block' => ["{#id .other .hardbreaks}\n::: \\\na\nb\n:::\n", '<!-- carve: ::: \\ -->'],
            'duplicate hard break classes' => ["{.hardbreaks .hardbreaks}\n::: \\\na\nb\n:::\n", '<!-- carve: ::: \\ -->'],
            'figure panel caption' => ["::: figure\n![a](x.png)\n^ Panel\n:::\n^ Group\n", '<!-- carve: ^ Panel -->'],
            'definition in item' => ["- :: One\n  : Body.\n", '<!-- carve: :: One -->'],
            'definition in quote' => ["> :: One\n> : Body.\n", '<!-- carve: : -->'],
            'multiple definitions and blocks' => [":: One\n: First.\n\n  Second.\n: Other.\n\n:: Two\n: Last.\n", '<!-- carve: -->'],
            'code attributes' => ["{.code}\n```php\nx\n```\n", '<!-- carve: {.code} -->'],
            'definition list attributes' => ["{.c}\n:: Carve\n: A markup language.\n", '<!-- carve: {.c} -->'],
            'star break attributes' => ["{.x}\n***\n", '<!-- carve: {.x} -->'],
            'dash break attributes' => ["{.x}\n---\n", '<!-- carve: {.x} -->'],
            'figure attributes' => ["{.x}\n$$`x`\n^ Cap\n", '<!-- carve: ^ Cap -->'],
            'figure attributes in item' => ["- {.x}\n  $$`x`\n  ^ Cap\n", '<!-- carve: ^ Cap -->'],
            'figure caption in item' => ["- $$`x`\n  ^ Cap\n", '<!-- carve: ^ Cap -->'],
            'thematic break attributes' => ["{.rule}\n***\n", '<!-- carve: {.rule} -->'],
            'nested quote attributes' => ["{.outer}\n> {.inner}\n> Paragraph.\n", '<!-- carve: {.outer} -->'],
            'nested list attributes' => ["{.outer}\n- {.inner}\n  Paragraph.\n", '<!-- carve: {.outer} -->'],
            'quote attributes' => ["{.q}\n> Quote.\n", '<!-- carve: {.q} -->'],
            'list attributes' => ["{.l}\n- One\n- Two\n", '<!-- carve: {.l} -->'],
            'heading in quote' => ["> {#h}\n> # Head\n", '<!-- carve: {#h} -->'],
            'paragraph in item' => ["- {.p}\n  Paragraph.\n", '<!-- carve: {.p} -->'],
            'image caption' => ["![alt](x.png)\n^ Caption\n", '<!-- carve: ^ Caption -->'],
            'code caption' => ["```php\n1;\n```\n^ Caption\n", '<!-- carve: ^ Caption -->'],
            'equation caption' => ["$$`x`\n^ Caption\n", '<!-- carve: ^ Caption -->'],
            'escaped terminator' => ["{data-x=\"-->\"}\nParagraph.\n", '<!-- carve: {data-x=--\\>} -->'],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testNewCarriersInTheirHosts(string $source, string $fragment): void
    {
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $markdown = CarveConverter::create(renderer: $renderer)->convert($source);
        $this->assertStringContainsString($fragment, $markdown);
        $importer = new MarkdownToCarve(convertMath: true);
        $imported = $importer->convert($markdown);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $this->assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($imported));
        if (in_array($fragment, ['<!-- carve: ^ Cap -->', '<!-- carve: {.x} -->', '<!-- carve: {.c} -->'], true)) {
            $codec = new AstCodec();
            $before = $codec->encode(CarveConverter::create()->parse($source));
            $after = $codec->encode(CarveConverter::create()->parse($imported));
            unset($before['srcByteLength'], $after['srcByteLength']);
            unset($before['children'][0]['marker'], $after['children'][0]['marker']);
            $this->assertSame($before, $after);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equationCaptionProvider(): array
    {
        return [
            'attributes' => ["{.x}\n$$`x`\n^ Cap\n"],
            'list item' => ["- $$`x`\n  ^ Cap\n"],
            'attributed list item' => ["- {.x}\n  $$`x`\n  ^ Cap\n"],
        ];
    }

    #[DataProvider('equationCaptionProvider')]
    public function testEquationCaptionsWithDefaultMarkdownImport(string $source): void
    {
        $markdown = (new CarveConverter(roundTripMode: true, renderer: new MarkdownRenderer()))->convert($source);
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $this->assertSame($source, $imported);
        $this->assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($imported));
    }

    public function testRestoredDefinitionBodyKeepsBlankLinesEmpty(): void
    {
        $source = ":: Carve\n: A markup language.\n  :: inner\n  : def\n";
        $markdown = (new CarveConverter(roundTripMode: true, renderer: new MarkdownRenderer()))->convert($source);
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $this->assertSame(":: Carve\n: A markup language.\n\n  *inner*\n\n  def\n", $imported);
    }

    public function testOnlyOutermostDefinitionListIsCarried(): void
    {
        $source = ":: Outer\n: :: Inner\n  : Inside.\n\n  After.\n";
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $markdown = CarveConverter::create(renderer: $renderer)->convert($source);
        $this->assertStringNotContainsString('<!-- carve: :: Inner -->', $markdown);
        $this->assertSame(1, substr_count($markdown, '<!-- carve: -->'));
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $expected = ":: Outer\n: *Inner*\n\n  Inside.\n\n  After.\n";
        $this->assertSame(CarveConverter::create()->convert($expected), CarveConverter::create()->convert($imported));
        $codec = new AstCodec();
        $before = $codec->encode(CarveConverter::create()->parse($expected));
        $after = $codec->encode(CarveConverter::create()->parse($imported));
        unset($before['srcByteLength'], $after['srcByteLength']);
        $this->assertSame($before, $after);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function labelProvider(): array
    {
        return ['empty' => [''], 'bracket' => ['bad]label'], 'newline' => ["bad\nlabel"], 'carriage return' => ["bad\rlabel"]];
    }

    #[DataProvider('labelProvider')]
    public function testLabelsAreConsumed(string $label): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $html = '<pre data-carve-label="' . htmlspecialchars($label, ENT_QUOTES) . '"><code>x</code></pre>';
            $importer = new HtmlToCarve(importMode: $mode);
            $tree = $importer->convertToAst($html);
            $this->assertProjection($tree, ['type' => 'code_block', 'label' => $label === '' ? '' : null]);
            $this->assertStringNotContainsString('data-carve-label', json_encode($tree, JSON_THROW_ON_ERROR));
            $diagnostics = $importer->convertWithReport($html)->diagnostics;
            $dropped = array_filter($diagnostics, static fn ($row): bool => $row->code === 'attribute-dropped');
            $this->assertSame($label !== '', $dropped !== []);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function damagedProvider(): array
    {
        return [
            'attribute without block' => ["<!-- carve: {.c} -->\n\nParagraph.\n"],
            'attribute above attribute' => ["<!-- carve: {.c} -->\n<!-- carve: {#id} -->\nParagraph.\n"],
            'definition without term' => ["<!-- carve: : -->\nBody.\n<!-- carve: -->\n"],
            'definition without closer' => ["<!-- carve: :: Term -->\n**Term**\n\n<!-- carve: : -->\nBody.\n"],
            'closing without list' => ["Paragraph.\n<!-- carve: -->\n"],
            'caption before heading' => ["> Quote.\n\n<!-- carve: ^ Caption -->\n# Heading\n"],
            'orphan caption' => ["<!-- carve: ^ Caption -->\nCaption\n"],
            'caption after paragraph' => ["Paragraph.\n\n<!-- carve: ^ Caption -->\nCaption\n"],
            'duplicate caption' => ["> Quote.\n\n<!-- carve: ^ First -->\nFirst\n\n<!-- carve: ^ Second -->\nSecond\n"],
            'unclosed line block' => ["<!-- carve: ::: | -->\nOne\\\nTwo\n"],
            'unclosed hard break block' => ["<!-- carve: ::: \\ -->\nOne\\\nTwo\n"],
        ];
    }

    public function testTermWithoutDefinitionIsNotDamaged(): void
    {
        $importer = new MarkdownToCarve();
        $markdown = "<!-- carve: :: Term -->\n**Term**\n\n<!-- carve: -->\n";
        $imported = $importer->convert($markdown);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $this->assertSame(":: Term\n", $imported);
    }

    #[DataProvider('damagedProvider')]
    public function testDamagedSetsFallBackTogether(string $markdown): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $diagnostics = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'carrier-markers-damaged'));
        $this->assertCount(1, $diagnostics);
        $this->assertSame('degraded', $diagnostics[0]->fidelity);
        $this->assertSame('fallback', $diagnostics[0]->confidence);
        $control = (new MarkdownToCarve())->convert(str_replace('<!-- carve:', '<!-- ordinary:', $markdown));
        $this->assertSame(str_replace('<!-- ordinary:', '<!-- carve:', $control), $result->value);
    }
}
